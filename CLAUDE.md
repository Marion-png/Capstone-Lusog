# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# First-time setup
composer run setup          # install deps, generate key, migrate, build assets

# Development (runs all concurrently: server, queue, logs, vite)
composer run dev

# Testing
composer run test           # config:clear then phpunit
php artisan test --filter ClassName::methodName   # single test

# Code style
./vendor/bin/pint           # auto-format (Laravel Pint)

# Database
php artisan migrate:fresh --seed   # reset DB and seed conditions
```

## Architecture

### Student Roster Persistence (invariant)

Adviser-entered student data must survive session expiry, re-login, and server restarts. The database is the source of truth; `session('school_health_card_records')` is only a working copy:

- `AdviserController::store` persists the **full** entry (names, birth date, guardian, address, contact, gender, ...) into the encrypted `student_health_records.student_details` JSON column, alongside the nutrition summary columns.
- `App\Support\StudentRosterSync::syncToSession()` rebuilds the session roster from the DB (scoped to the active institution) and is called by the adviser dashboard, the consent-forms module, and the nurse queue. Any new page that reads `school_health_card_records` must call it first.
- Never treat the session as the only store for new student-entered fields — persist them in `student_details` too.
- `AdviserRosterPersistenceTest` guards this invariant; keep it passing.

### Authentication — Session-Only, No Laravel Auth

This app does **not** use `Laravel\Auth` or the `users` table for login. Authentication is custom and session-based; login accounts live in the database:

- Accounts are rows in the `accounts` table (`name`, `username`, `password_hash`, `role`, `institution_id`, `school_name`, plus adviser `assigned_grade_level` / `assigned_section`). Usernames are unique **per school** (see the Multi-School invariant).
- Login (`POST /login`) looks up the username in `accounts`, verifies the password hash, and on a match writes role data into the session. Active session keys: `active_role`, `active_name`, `active_username`, `active_school_name`, `active_institution_id` (class advisers also get `assigned_grade_level` / `assigned_section`).
- New accounts are self-service: a registration writes a pending row to `account_requests`; the System Admin approves it into `accounts`.
- **Prototype auto-session:** visiting a protected URL without a session does not force a login. `EnsureActiveSession` seeds a demo session for the role that URL belongs to, so any role's UI opens by typing its URL; a real account session is never overwritten. `PrototypeSessionTest` guards this.
- System admin login (`/admin-login`) uses `SYSTEM_ADMIN_USERNAME` / `SYSTEM_ADMIN_PASSWORD` env vars (defaults: `systemadmin` / `admin123`).

### Route Guard

`App\Http\Middleware\EnsureActiveSession` — the only auth middleware. It checks `session()->has('active_role')` and redirects to login if missing. It also sets `no-cache` headers on every response (authenticated or not) to prevent browser history from showing authenticated pages after logout.

Applied to all routes under: `dashboard/*`, `adviser/*`, `nurse/*`, `health-records/*`.

### Role System

Roles stored as strings in `session('active_role')`. The seven roles are:

| session value | Description |
|---|---|
| `school_nurse` | **School Nurse** — manages deworming, consultations, health records |
| `clinic_staff` | Consultation logging, medicine inventory |
| `class_adviser` | Student data entry, medical certificates, consent forms |
| `school_head` | Reports, deworming approval |
| `feeding_coor` | SBFP: uploads attendance (gates the workflow), fills SBFP forms, views auto-tabulated BMI reports |
| `nutricor` | Nutrition analytics, consolidated reports |
| `system_admin` | Account approval/management |

Role checks in controllers are manual string comparisons on `session('active_role')`.

### Feeding Coordinator workflow (attendance-first)

The coordinator's flow is ordered and mostly computed — the period is the `school_year`:

1. **Attendance upload first.** `FeedingProgramController::importAttendance` parses a DMIRIE-style CSV/XLSX (`App\Support\AttendanceSheetParser` — NAME/GRADE/SECTION + dated columns), matches rows to learners, writes `feeding_attendances`, recomputes at-risk (`refreshAttendanceRiskFlags`: attended **< 75%** of sessions), and records one `AttendanceImport` batch — all in a single transaction (no partial writes). `AttendanceImport::existsForPeriod()` is the gate.
2. **At-risk is derived, never tagged.** Only attendance decides; nutritional status never auto-flags a learner.
3. **SBFP Forms is the coordinator's single forms page** (`FeedingCoordinatorController::sbfpForms` → `feedingcor-dashboard/sbfp-forms.blade.php`). Two kinds of templates live here:
   - **Hand-encoded, client-side (localStorage) drafts:** Milk Component Forms 5/6/7/7-a, narrative report, and masterlist. The masterlist and beneficiary (Form 6) forms auto-fill from adviser records grouped by grade (one grade per form; `isQualifiedForFeeding` = Wasted / Severely Wasted / Underweight).
   - **Auto-tabulated, read-only DepEd BMI reports:** the **Baseline** (`bmib_*`) and **Final** (`bmif_*`) Nutritional Assessment grids (Grades 7-10 + Overall). `buildBmiValues()` counts learners by grade × sex × BMI-for-age × height-for-age — in PHP, since those fields are encrypted at rest — and the `partials/bmi-table` cells render those server values read-only (no draft, print only). Source: sex + HFA from `student_details` (`gender`, `nutritional_status_height_for_age`), BMI-for-age from `baseline_nutritional_status` (Baseline) / `endline_nutritional_status` (Final); Final HFA is recomputed from endline height/age. The adviser's non-standard "Underweight" status is grouped under **Wasted** (the DepEd sheet has no Underweight column); the classifier never emits "Obese", so that column stays 0. `FeedingBmiReportTest` guards this.
   
   There are **no separate Baseline, Endline, or Reports tabs** on the coordinator — DB-backed baseline/endline measurements are entered by the class adviser (`StudentHealthRecordController::storeBaseline`/`storeEndline`).
### Routing Pattern

All routes are inline closures or controller actions in `routes/web.php` — no route groups by role. Permission checks inside closures and controllers gate access manually. There is no route-level role middleware.

### Data Layer

- **Database:** SQLite (`database/database.sqlite`), 36 migrations.
- **Session storage:** `SESSION_DRIVER=file`. Session data is in `storage/framework/sessions/`.
- Some data (e.g. deworming requests) falls back to session when its DB table doesn't exist — the controllers check `Schema::hasTable()` before querying.
- **Uploaded files** (medical certificates, parental consent forms) are stored in `storage/app/private/` using Laravel's `private` disk.

### Encryption at Rest (invariant)

All personal and sensitive personal information (student names, contact details, medical/health data, consent answers, signatures) is encrypted at rest with AES-256 keyed by `APP_KEY`. Rules to preserve in every change:

- Sensitive model attributes use the custom casts in `App\Casts` — `EncryptedString`, `EncryptedArray`, `EncryptedBoolean`. They always encrypt on write but tolerate legacy plaintext on read (falling back to the raw value instead of throwing `DecryptException`), so pre-encryption rows and empty-string defaults never 500 a page. Do **not** use Laravel's built-in `encrypted` casts here — they throw on plaintext. See the `$casts` arrays on `StudentHealthRecord`, `Consultation`, `StudentHealthCondition`, `MedicalCertificate`, `ParentalConsentForm`, `HealthAssessment`, and `HealthConsentForm` for the authoritative field lists.
- **Never reference an encrypted column in SQL** — no `WHERE`, `ORDER BY`, `GROUP BY`, `DISTINCT`, or aggregate on it. Fetch first, then filter/sort/group on the decrypted collection in PHP. Lookup keys deliberately left plain: `student_id` (LRN), `student_lrn`, `school_name`, `section`, `school_year`, `token`, `status`, `institution_id`, `is_at_risk`, `attendance_sessions_count`.
- Uploaded documents (medical certificates, signed consent scans) are stored encrypted via `App\Support\EncryptedFileStorage` — use it for any new upload/download, never `storeAs()` + `Storage::response()` directly.
- Sessions are encrypted (`SESSION_ENCRYPT=true`) because adviser-entered student data (addresses, guardians, phone numbers) lives in the session.
- Losing `APP_KEY` means losing the data — treat it as a production secret and never rotate it without re-encrypting (see `2026_07_12_000003_encrypt_existing_sensitive_data.php` for the in-place re-encryption pattern).
- `EncryptionAtRestTest` guards this invariant; keep it passing.

### Audit Trail (invariant)

Every access and action on personal/sensitive personal information is logged to the append-only `audit_logs` table for forensic investigation. Rules to preserve:

- Models holding sensitive data use the `App\Models\Concerns\Auditable` trait — it logs created/updated/deleted with field-level old/new values in the encrypted `details` column. Any new model with personal data must add the trait.
- `App\Http\Middleware\AuditSensitiveAccess` logs page views, API reads, and document downloads on sensitive URL patterns — add new sensitive URL prefixes to its `SENSITIVE_PATTERNS` list.
- Actions that bypass Eloquent (raw `DB::table` writes, e.g. the account routes) must call `App\Support\AuditTrail::record()` explicitly.
- Login, failed login, and logout events are recorded in the login/logout routes.
- Audit entries are evidence: never update or delete `audit_logs` rows from application code (`AuditLog` has no updated_at and no edit path). The System Admin views them at `/dashboard/system-admin/audit-logs`.
- `AuditTrailTest` guards this invariant; keep it passing.

### Multi-School Data Separation (invariant)

Data separation covers **all** modules and records — health records, consultations, medicine inventory, deworming, consent forms, health assessments, feeding attendance, and accounts. Rules to preserve in every change:

- Every query that reads or writes school-owned data must be scoped by the session's `active_institution_id`. Use `StudentHealthRecord::forActiveInstitution()` for student lookups by LRN; child tables (certificates, consents, assessments, attendance) inherit scope through their parent record — verify the parent's `institution_id` before serving them (e.g. downloads).
- Rows created for a school must be stamped with `institution_id` from the session.
- Usernames on `accounts` are unique **per school** (`username + institution_id`), not globally: a teacher working in multiple schools holds a separate account per school. Login verifies the password against every account with that username and asks for a school choice (`school_choices` flash + `institution_id` input) when more than one matches.
- `system_admin` is the only unscoped role; `InstitutionScope` middleware ejects scoped roles that have no `active_institution_id`.

### Key Models and Their Scope

- `StudentHealthRecord` — the central model. Tracks a student across baseline/endline nutrition measurements, feeding attendance, conditions, and consent. Filtered by `school_name` (string) to scope per school.
- `Condition` — master catalog of 32 health conditions, seeded by `ConditionSeeder`. Supports `search()` and `byCategory()` local scopes.
- `Consultation` — belongs to a `Condition`. Created by clinic staff/nurse during clinic visits.
- `StudentHealthCondition` — pivot between `StudentHealthRecord` and conditions; has an `isVerified()` method that checks for an attached `MedicalCertificate`.
- `ParentalConsentForm` — tracks deworming/program consent per student per school year. `ParentalConsentForm::currentSchoolYear()` computes the year dynamically.

### Frontend

Blade templates with Tailwind CSS 4. No Livewire. Alpine.js is used inline in some views. Vite bundles assets (`npm run build`). The condition search (`resources/views/components/condition-search.blade.php`) is a self-contained Alpine.js component that calls `GET /api/conditions`.

### Test Setup

PHPUnit with an in-memory SQLite DB (configured in `phpunit.xml`). Tests use `RefreshDatabase`. Feature tests directly boot the Laravel app — session state must be set manually via `$this->withSession([...])` since there is no Auth facade to log in with.
