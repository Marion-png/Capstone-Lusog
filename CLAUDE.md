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

1. **Attendance first.** Three paths, all landing in `feeding_attendances` inside one transaction (no partial writes), each recording an `AttendanceImport` batch. `AttendanceImport::existsForPeriod()` is the gate.
   - **Spreadsheet:** `importAttendance` parses a DMIRIE-style CSV/XLSX (`App\Support\AttendanceSheetParser` — NAME/GRADE/SECTION + dated columns) and matches rows to learners.
   - **Photograph:** `scanAttendancePhoto` sends the photo to Claude vision **together with the roster** (`App\Support\AttendanceSheetScanner`) so the model matches a mark to an already-known learner instead of reading names cold — deliberately more reliable than blind OCR, and it can't invent a learner. It is never used to identify anyone from a face. Requires `ANTHROPIC_API_KEY`; absent one, the route is disabled and the spreadsheet path is unaffected.
   - **Recorded on site:** `recordAttendanceForm` / `storeRecordedAttendance` (`feedingcor-dashboard/record-attendance.blade.php`) — one screen listing every beneficiary, one tap per learner, one save, reached from the dashboard's "Record Today's Attendance". Marks are confirmed on arrival (`SOURCE_MANUAL_ENTRY`, `needs_review` false) and supersede an unread scanned mark for the same session. **A learner left unmarked writes no row at all** — never an absence; the coordinator may simply have skipped them. An absence may carry a `remarks` reason: it is personal information, cast `EncryptedString`, and because the bulk write is an **upsert (which bypasses model casts)** it is encrypted in the controller — do the same for any column added to that write. A learner marked present stores no remark. `FeedingRecordAttendanceTest` guards this.
2. **At-risk is derived, never tagged.** Only attendance decides; nutritional status never auto-flags a learner. The rule lives in `App\Support\FeedingAtRiskRule`: `attendance_rate` (default, **< 80%**) or `consecutive_absences` (`config/feeding.php`). The threshold is **school-configurable** — `institutions.feeding_at_risk_threshold`, set per school by the System Admin (`dashboard.system-admin.institutions.at-risk-threshold`, audited); NULL means the app default. Always read it through **`FeedingAtRiskRule::forInstitution($institutionId)`**, never straight from config, or two screens will disagree about who is flagged. `FeedingAtRiskRuleTest` guards the rule, `FeedingAtRiskThresholdTest` the per-school override.
3. **An unconfirmed mark is not an absence (invariant).** `feeding_attendances.is_present` is **nullable**: NULL means a scanned mark no human has confirmed. `FeedingAtRiskRule` excludes NULLs from both the numerator and the denominator, so an unreadable photo can neither flag nor unflag a learner. Never aggregate attendance with `SUM(CASE WHEN is_present ...)` — that folds NULL into "absent". Unclear marks surface in the review queue (`attendanceReviewQueue` → `resolveAttendanceReview`), every resolution is attributed and audit-logged, and the scanned photo is deleted once all its marks are confirmed. `FeedingAttendanceScanTest` guards this.
4. **The dashboard is live, filtered and derived** (`FeedingCoordinatorController::dashboard` → `feed-dashboard.blade.php`). The SBFP header counts the feeding day from `App\Support\FeedingProgramCycle` (day 1 = first recorded session, shared with the Feeding Program page), with the percentage beside the bar. Below it sit four cards (Beneficiary / Attendance / At-Risk / Awaiting Enrollment), an **Attendance Monitoring** panel for today's session and a **Nutritional Status** roll.
   - **Five coordinated filters** (`readFilters`): School Year, Grade, Section, Nutritional Status, Attendance Status. Only the school year is a SQL filter (`school_year` is plain); the rest are applied in PHP because their columns are encrypted. Year/grade/section scope **every** panel; the two status filters narrow the attendance roll alone, so its headline keeps counting everyone expected today. Section options are rebuilt server-side for the chosen grade.
   - **Nutritional Status** counts beneficiaries by baseline status over `nutritionScale()` — Severely Wasted / Wasted / Normal / Obese. Underweight is folded into Wasted (`panelStatus()`) exactly as the DepEd BMI reports do, so the breakdown always sums to the Beneficiary card; Overweight is not a beneficiary status. Zero rows still render.
   - **Attendance Monitoring** shows Student / Grade / Section / Attendance / Remarks. A recorded session leaves each learner **Present or Absent**; an unread scanned mark and a learner no sheet has covered render as "—", never as an absence. The headline is always `present / expected — %` (greyed until the session is recorded), with chips for the other states.
   - **Attendance At Risk** (`buildAttendanceRisk`) lists the learners under the school's threshold — Student / Grade / Section / Attendance / Days Present / View — worst rate first, above a printed `At-risk threshold: N%`. Days present is counted over **confirmed** sessions, the same denominator the rule judged, so the fraction on screen is the one that decided the flag. The card's At-Risk figure is computed live from the same rule (not the stored `is_at_risk`), so changing the threshold shows immediately. "View" opens `feedingcor-program.attendance.learner` — one learner's sessions, keyed by **record id, never by name** (URLs are logged and shared) and re-scoped to the coordinator's school. "View At-Risk List" goes to the Feeding Program page's at-risk section.
   - **Nutritional Progress** (`App\Support\FeedingNutritionProgress`) plots baseline against endline per status and computes `Improved Nutritional Status: n / total — %`. The rule is **in the business logic, never hand-entered**: improvement means climbing the wasting scale (Severely Wasted → Wasted → Normal); overshooting into Obese/Overweight is deliberately *not* improvement, and the denominator is every beneficiary (not only those measured) so the headline cannot creep up simply because few endline readings exist. Two series on the theme's validated `--series-risk` / `--series-healthy` pair — colour carries baseline-vs-endline, the row label carries the status, every bar is direct-labelled, and a `<details>` table view repeats every number. Re-run the dataviz validator before changing either colour.
   - All five panels are `live-pane`s: the page polls `dashboard.feedingcor.metrics.pulse` (a stamp, no PII — exempt in `AuditSensitiveAccess`) every 20s and re-renders from `dashboard.feedingcor.metrics`, **forwarding its own query string** so a refresh redraws the filtered view. That endpoint returns the **same Blade partials** the first paint used (`feedingcor-dashboard/partials/*`), so live and reloaded views cannot drift. `FeedingCoordinatorDashboardTest` guards this.
5. **SBFP Forms is the coordinator's single forms page** (`FeedingCoordinatorController::sbfpForms` → `feedingcor-dashboard/sbfp-forms.blade.php`). Two kinds of templates live here:
   - **Hand-encoded, client-side (localStorage) drafts:** narrative report and masterlist. The masterlist auto-fills from adviser records grouped by grade (one grade per form; `isQualifiedForFeeding` = Wasted / Severely Wasted / Underweight). The Milk Component Forms 5/6/7/7-a were removed — do not re-add them.
   - **Auto-tabulated, read-only DepEd BMI reports:** the **Baseline** (`bmib_*`) and **Final** (`bmif_*`) Nutritional Assessment grids (Grades 7-12 + Overall — JHS 7-10 plus SHS 11-12; `bmiGradeKey()` drops anything outside that range). `buildBmiValues()` counts learners by grade × sex × BMI-for-age × height-for-age — in PHP, since those fields are encrypted at rest — and the `partials/bmi-table` cells render those server values read-only (no draft, print only). Source: sex + HFA from `student_details` (`gender`, `nutritional_status_height_for_age`), BMI-for-age from `baseline_nutritional_status` (Baseline) / `endline_nutritional_status` (Final); Final HFA is recomputed from endline height/age. The adviser's non-standard "Underweight" status is grouped under **Wasted** (the DepEd sheet has no Underweight column); the classifier never emits "Obese", so that column stays 0. `FeedingBmiReportTest` guards this.
   
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
- `MedicalCertificate` — one uploaded medical document for a learner, keyed by the plain `student_lrn` + `institution_id` columns. `student_health_condition_id` is **optional**: documents uploaded from the Medical Documents tab of the student profile carry no condition, so never assume `->condition` is present. The class adviser, school nurse, and clinic staff (`StudentMedicalDocuments::UPLOAD_ROLES`) share one list per learner and each sees what the others filed — `uploaded_by_role` records which desk did. The tab is one component (`partials/student-documents-panel` + `-script`, `resources/css/student-documents.css`) included by both profiles; its routes are deliberately under `/health-records/*`, not `/adviser/*`, because `EnsureActiveSession` would switch a prototype nurse session over to `class_adviser` on an adviser URL. An open panel polls `student-documents.pulse` every 20s — a no-PII count+timestamp stamp, exempt in `AuditSensitiveAccess` like the adviser activity pulse — and re-reads the list only when the stamp moves, so each desk sees what the others just filed. The list endpoints return that same stamp alongside `documents`, so an uploader never re-fetches what it just rendered. Files go through `EncryptedFileStorage`; `StudentMedicalDocumentTest` guards upload/preview/download/delete, the cross-role list, and the per-school scoping.
- `ParentalConsentForm` — tracks deworming/program consent per student per school year. `ParentalConsentForm::currentSchoolYear()` computes the year dynamically.

### Frontend

Blade templates with Tailwind CSS 4. No Livewire. Alpine.js is used inline in some views. Vite bundles assets (`npm run build`). The condition search (`resources/views/components/condition-search.blade.php`) is a self-contained Alpine.js component that calls `GET /api/conditions`.

**LUSOG design system.** The Feeding Coordinator, the School Head and the School Nurse share one system; every page in those roles inlines three stylesheets **in this order**: `css/lusog-theme.css`, then its own page sheet (`css/feeding-*.css`, `css/schoolhead.css`, `css/school-nurse*.css`), then its rail sheet — `css/role-sidebar.css` for the coordinator/head `.asb-*` panel, `css/nurse-sidebar.css` for the nurse's logo-led `.sb-*` rail. `lusog-theme.css` owns the palette (`--lg-*`), the app shell, KPI cards, buttons, inputs, tables, the `.badge` status scale and the alert/flash components; a page sheet carries only what is unique to that page and must not redeclare shell or component rules. Green is the brand and the "healthy" signal only — amber is monitoring, orange at-risk, coral critical, teal neutral information; never paint a component green to look on-brand.

- Chart series come from `--series-healthy` / `--series-risk` in the theme (validated pair; re-run the dataviz validator before changing them).
- Every tab's `<h1 class="page-title">` is one line in two voices: the subject upright in DM Serif Display charcoal, then `<span>` with the section in italic emerald (`Dashboard <span>Feeding Program</span>`). The serif is loaded for page titles **only** — everything else is Inter.
- **School Nurse pages** use `partials/nurse-lusog-sidebar` and must include `partials/nurse-page-transition` (or an inline equivalent): `nurse-sidebar.css` starts `.sidebar ~ .main` at `opacity: 0` under `html.js`, so a page that never adds `.page-ready` renders blank. Deworming and Data Visualization are still on the retired `.nsb-*` rail (`partials/nurse-sidebar`); `NurseLusogShellTest` covers the converted tabs and `SchoolNurseDashboardTest` tracks the two that remain.

- The theme also declares `--asb-*` (sidebar) and `--ann-*` / `--clock-*` (announcements board, live-clock pill). Those partials and `role-sidebar.css` read every colour through `var(--token, <fallback>)`; the fallbacks now hold the same LUSOG values as the tokens, so a page cannot drift off-palette by failing to load them. Retheme by changing the token — edit a fallback only to keep it in step with its token, never to give one role a different colour.

**One palette, every role.** A page is on the palette one of two ways, never both: pages migrated to the design system inline `css/lusog-theme.css`; every other page inlines `css/lusog-palette.css` **after** its own styles, which re-points the older private ramps (`--g900`, `--text-3`, `--border`, …) at LUSOG values. Loading order is the whole mechanism — the palette wins by coming last, so a page that inlines it before its own `:root` silently keeps the old greens. Legacy sheets no longer hardcode hex greens; write new rules against `--lg-*`. `SharedPaletteTest` guards both the coverage and the ordering across all seven roles.

- Every role's rail shows the full `images/lusog-logo.png` lockup — the `.sb-logo-full` (nurse) or `.asb-logo-full` (all other roles) image, not an icon-plus-wordmark pair.
- The dashboard's BMI zone colours (`.grad-under/-healthy/-over` in `feeding-dashboard.css`) are a validated set, not a palette picked by eye. Re-run the dataviz validator before changing any of them — the brief's lighter `#F2B84B` / `#43A866` both fail the CVD and contrast checks as line colours.
- `feeding-sbfp-forms.css` deliberately keeps two registers: app chrome on the system, but `.form-sheet` and everything inside it is a facsimile of the printed DepEd form (black hairlines, uppercase heads). Do not modernise the sheet.

### Test Setup

PHPUnit with an in-memory SQLite DB (configured in `phpunit.xml`). Tests use `RefreshDatabase`. Feature tests directly boot the Laravel app — session state must be set manually via `$this->withSession([...])` since there is no Auth facade to log in with.
