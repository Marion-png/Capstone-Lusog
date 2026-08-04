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
- System admin login (`/admin-login`) has no `accounts` row — it is the account that approves those. Credentials come from `config/system_admin.php`, which reads `SYSTEM_ADMIN_USERNAME`, `SYSTEM_ADMIN_PASSWORD_HASH` (preferred, bcrypt) and `SYSTEM_ADMIN_PASSWORD` (plaintext fallback). Defaults are `systemadmin` / `admin123` — **set the hash before deploying**. The login fails closed if neither password is configured, verifies with `Hash::check`/`hash_equals`, and clears `active_institution_id` so an unscoped admin never inherits a school binding from a previous session on the browser.
- **Never call `env()` outside `config/`.** `php artisan config:cache` stops loading `.env`, so a runtime `env()` returns its fallback — this is exactly how the admin login silently reverted to the default password. `SystemAdminLoginTest` scans `app/` and `routes/` for this and guards the login behaviour; keep it passing.

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

- **Database:** PostgreSQL, 44 migrations. There is no SQLite fallback — `config/database.php` defines no `sqlite` connection. Two connections: `pgsql` (central, database `capstone_lusog`) and `tenant` (one database per school) — see the Per-Institution Database Isolation invariant.
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

### Per-Institution Database Isolation (invariant)

Every school owns a **physically separate PostgreSQL database**. Two schools' records cannot be mixed by a missing `WHERE` clause because they are not in the same database at all. Rules to preserve in every change:

- **Central database** (`capstone_lusog`, the `pgsql` connection) holds only what must span schools: `institutions`, `institution_requests`, `accounts`, `account_requests`, and `audit_logs`. Login needs to search usernames across all schools, and the System Admin's forensic view has to stay global, so these cannot be per-tenant.
- **Tenant databases** (`capstone_lusog_inst_<institution id>`, the `tenant` connection) hold every school-owned record: health records, conditions, certificates, consent forms, health assessments, consultations, clinic notes, **medicine inventory**, **deworming requests**, feeding attendance, attendance imports, announcements, and events. A model whose rows belong to a school uses the `App\Models\Concerns\TenantConnection` trait — never `protected $connection`, which cannot fold onto the default connection in tests. Any new school-owned model must add the trait. Only `AuditLog`, `Institution`, `InstitutionRequest`, and the unused `User` are central.
- **Raw queries on school-owned tables must use `Tenancy::table()` / `Tenancy::schema()`, never `DB::table()` / `Schema::hasTable()`.** A bare `DB::table()` runs on the default connection — the central database — where the table exists but is empty, so the query silently returns nothing and writes land in the wrong place. The deworming module and the two activity-pulse stamps go through `Tenancy::table()` for exactly this reason. `accounts` and `account_requests` are the only tables raw `DB::table()` may target.
- `App\Support\Tenancy` binds the connection: `InstitutionScope` middleware calls `Tenancy::bind()` from `active_institution_id` on every request, after `Tenancy::forget()` clears whatever the previous request left bound. Use `Tenancy::runFor($id, fn () => ...)` to visit another school's database (System Admin cross-school reads) — it restores the previous binding afterwards.
- Outside shared-database mode the `tenant` connection's database is **null** until bound, so an unscoped tenant query fails loudly instead of silently reading the central database.
- **Provisioning** happens when the System Admin approves an `institution_requests` row: `App\Support\InstitutionProvisioner::provision()` issues `CREATE DATABASE`, runs every migration on it, and seeds `ConditionSeeder`. It is idempotent. Schools that predate this are backfilled with `php artisan institutions:provision --all`.
- `CREATE DATABASE` cannot run inside a transaction, so the provisioner issues it on a cloned `tenancy_maintenance` connection with its own PDO handle. Never wrap provisioning in `DB::transaction()`.
- Postgres cannot point a foreign key at another database, and several tenant tables have an `institution_id` FK to `institutions`. Each tenant database therefore holds **exactly one** institution row — its own — written by the provisioner. The central `institutions` table stays authoritative.
- Row-level `institution_id` scoping below is **not** replaced by this; it stays as a second layer.
- `InstitutionDatabaseIsolationTest` guards this invariant, provisioning real databases rather than running in shared mode; keep it passing.

**Testing:** `config('tenancy.shared_database')` (set by `TENANCY_SHARED_DATABASE=true` in `phpunit.xml`) folds the tenant connection onto the default connection so `RefreshDatabase` works on one database — provisioning a real database per test would add minutes to the run. Never enable it outside tests: it collapses every school into one database.

### Multi-School Data Separation (invariant)

Row-level scoping, retained as defense in depth on top of the per-institution databases above. Data separation covers **all** modules and records — health records, consultations, medicine inventory, deworming, consent forms, health assessments, feeding attendance, and accounts. Rules to preserve in every change:

- Every query that reads or writes school-owned data must be scoped by the session's `active_institution_id`. Use `StudentHealthRecord::forActiveInstitution()` for student lookups by LRN; child tables (certificates, consents, assessments, attendance) inherit scope through their parent record — verify the parent's `institution_id` before serving them (e.g. downloads).
- Rows created for a school must be stamped with `institution_id` from the session.
- Usernames on `accounts` are unique **per school** (`username + institution_id`), not globally: a teacher working in multiple schools holds a separate account per school, each with its own role and grade/section assignment. The composite unique index `accounts_username_institution_unique` enforces this at the database level. Login verifies the password against every account with that username and asks for a school choice (`school_choices` flash + `institution_id` input) when more than one matches — note the login form posts the username under the field name `email`. `PerSchoolAccountSeparationTest` guards this; keep it passing.
- Accounts stay in the **central** database on purpose: login must find a username across all schools to offer that picker, which is impossible if accounts are scattered across tenant databases. Their separation comes from the composite unique key plus `institution_id` scoping on every read, not from physical isolation.
- `system_admin` is the only unscoped role; `InstitutionScope` middleware ejects scoped roles that have no `active_institution_id`.

### Key Models and Their Scope

- `StudentHealthRecord` — the central model. Tracks a student across baseline/endline nutrition measurements, feeding attendance, conditions, and consent. Filtered by `school_name` (string) to scope per school.
- `Condition` — master catalog of 32 health conditions, seeded by `ConditionSeeder`. Supports `search()` and `byCategory()` local scopes.
- `Consultation` — belongs to a `Condition`. Created by clinic staff/nurse during clinic visits.
- `StudentHealthCondition` — pivot between `StudentHealthRecord` and conditions; has an `isVerified()` method that checks for an attached `MedicalCertificate`.
- `MedicalCertificate` — one uploaded medical document for a learner, keyed by the plain `student_lrn` + `institution_id` columns. `student_health_condition_id` is **optional**: documents uploaded from the Medical Documents tab of the student profile carry no condition, so never assume `->condition` is present. The class adviser, school nurse, and clinic staff (`StudentMedicalDocuments::UPLOAD_ROLES`) share one list per learner and each sees what the others filed — `uploaded_by_role` records which desk did. The tab is one component (`partials/student-documents-panel` + `-script`, `resources/css/student-documents.css`) included by both profiles; its routes are deliberately under `/health-records/*`, not `/adviser/*`, because `EnsureActiveSession` would switch a prototype nurse session over to `class_adviser` on an adviser URL. An open panel polls `student-documents.pulse` every 20s — a no-PII count+timestamp stamp, exempt in `AuditSensitiveAccess` like the adviser activity pulse — and re-reads the list only when the stamp moves, so each desk sees what the others just filed. The list endpoints return that same stamp alongside `documents`, so an uploader never re-fetches what it just rendered. Files go through `EncryptedFileStorage`; `StudentMedicalDocumentTest` guards upload/preview/download/delete, the cross-role list, and the per-school scoping.
- `ParentalConsentForm` — tracks deworming/program consent per student per school year. `ParentalConsentForm::currentSchoolYear()` computes the year dynamically.

### Frontend

Blade templates with Tailwind CSS 4. No Livewire. Alpine.js is used inline in some views. Vite bundles assets (`npm run build`). The condition search (`resources/views/components/condition-search.blade.php`) is a self-contained Alpine.js component that calls `GET /api/conditions`.

### Test Setup

PHPUnit runs against a dedicated PostgreSQL database, `capstone_lusog_test` (configured in `phpunit.xml`; it inherits `DB_USERNAME`/`DB_PASSWORD` from `.env`). The database must exist before running the suite. Tests use `RefreshDatabase`. Feature tests directly boot the Laravel app — session state must be set manually via `$this->withSession([...])` since there is no Auth facade to log in with.
