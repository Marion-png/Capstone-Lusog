# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository. It holds the rules most tasks need; each module's full rules live in `docs/claude/` (see **Where the detail lives** at the end). Read the matching file before changing that module.

## Commands

```bash
# First-time setup. The database is the deployed Railway PostgreSQL, never a
# local one (docs/railway-database-access.md). Do NOT use `composer run setup`:
# it runs key:generate, and a new APP_KEY cannot read a single encrypted record.
composer install && npm install
cp .env.example .env        # then set DB_URL (the Postgres service's DATABASE_PUBLIC_URL) and the team's APP_KEY
php artisan config:clear && php scripts/check-railway-db.php   # read-only: connection, schema, accounts, APP_KEY
npm run build

# Development (runs concurrently: server, queue worker, vite)
composer run dev

# Testing (in-memory SQLite via phpunit.xml — never touches Railway)
composer run test           # config:clear then phpunit
php artisan test --filter ClassName::methodName   # single test

# Code style
./vendor/bin/pint           # auto-format (Laravel Pint)

# Database — pending migrations only; safe against the shared database.
php artisan migrate
# Never run migrate:fresh, db:wipe, migrate:rollback or key:generate against it
# unless explicitly asked: they destroy or lock away the team's data.
```

## Architecture

### Roles

Roles are strings in `session('active_role')`:

| session value | Description |
|---|---|
| `school_nurse` | **School Nurse** — deworming, consultations, health records |
| `clinic_staff` | Consultation logging, medicine inventory; dispenses **paracetamol only** (`DispensingRights`) |
| `clinic_teacher` | **Clinic Teacher** — reads the nurse's screens; dispenses **paracetamol only**, never writes stock |
| `class_adviser` | Student data entry, medical certificates, consent forms |
| `school_head` | **School Head** — reads, monitors, exports; writes nothing but their own session and password |
| `feeding_coor` | SBFP: attendance, enrolment, SBFP forms, BMI reports |
| `nutricor` | Nutrition analytics, consolidated reports |
| `system_admin` | Account approval/management; the only unscoped role |

### Authentication and route guard

- **No Laravel Auth.** Accounts are rows in `accounts` (usernames unique **per school**). Login writes `active_role`, `active_name`, `active_username`, `active_school_name`, `active_institution_id` (advisers also `assigned_grade_level` / `assigned_section`) into the session. Registration writes `account_requests`; the System Admin approves them. Sign-in and sign-out both `invalidate()` the session (`SessionLifecycleTest`).
- **`EnsureActiveSession` is the only login guard**, on `dashboard`, `adviser`, `nurse` and `health-records`. In `local`/`testing` it seeds a **prototype session** for whichever role a URL belongs to (`config('app.prototype_sessions')`; never on for the deployed service — `PrototypeSessionTest`). That is why a role's pages live under that role's own URL path.
- **The guard asks whether somebody is signed in, never who — so every page and every write must check the role itself (invariant).** Role checks are manual string comparisons on `session('active_role')` in the controller or closure. `RoleBoundaryTest` signs in as each role with a real session and requires a 302/403 on pages that are not theirs; add every new role-owned page to its `PAGES` list.
- **The School Head writes nothing.** `RestrictSchoolHeadWrites` refuses every POST/PUT/PATCH/DELETE for `school_head` unless its route name is in `ALLOWED_ROUTES` (sign in/out, account request, own password only). **Every write route must carry a name** — `SchoolHeadRoleTest` fails an unnamed one.
- System admin login reads `config('app.system_admin')`. **Never call `env()` outside `config/`** — every deploy caches config.
- Routes are inline closures or controller actions in `routes/web.php`, with no role groups or role middleware.

### Data layer

- **Database:** Railway PostgreSQL only, through `DB_URL` (`docs/railway-database-access.md`). Never add a local database. Tests alone use in-memory SQLite. Migrations do not run on deploy; applying one is a deliberate `php artisan migrate`. Deployment: `docs/deployment.md` (never put another `.php` file or a secret under `public/` — `PublicExposureTest`).
- **Sessions** are file-backed and encrypted (`SESSION_ENCRYPT=true`). Some data falls back to the session when its table is missing — check `Schema::hasTable()` before querying it.
- **Uploads** go through `App\Support\EncryptedFileStorage` (never `storeAs()` + `Storage::response()`). A request that waits on another server calls `App\Support\LongRequest::allow()` first.
- **Time:** the server clock is UTC and timestamps are stored in UTC. Any date a person reads, types or is greeted by comes from **`App\Support\SchoolClock`** (`config('app.school_timezone')`, Asia/Manila) — never `now()` or `before_or_equal:today` for that.

### Student roster persistence (invariant)

The database is the source of truth; `session('school_health_card_records')` is a working copy. `AdviserController::store` persists the full entry into the encrypted `student_health_records.student_details`. `StudentRosterSync::syncToSession()` rebuilds the session roster from the DB — any new page reading `school_health_card_records` must call it first. Never store a new student field in the session only. `AdviserRosterPersistenceTest` guards it.

### Encryption at rest (invariant)

- Personal and sensitive data (names, contact details, health data, consent answers, signatures) is encrypted with AES-256 keyed by `APP_KEY`, through the casts in `App\Casts` — `EncryptedString`, `EncryptedArray`, `EncryptedBoolean`. They tolerate legacy plaintext on read. **Do not use Laravel's built-in `encrypted` casts** (they throw on plaintext).
- **Never reference an encrypted column in SQL** — no `WHERE`, `ORDER BY`, `GROUP BY`, `DISTINCT` or aggregate. Fetch, then filter/sort/group in PHP. Plain lookup keys: `student_id` (LRN), `student_lrn`, `school_name`, `section`, `school_year`, `token`, `status`, `institution_id`, `is_at_risk`, `attendance_sessions_count`.
- **Upserts bypass casts**: encrypt in the controller with `Crypt::encryptString` for any personal column written through one.
- Losing or rotating `APP_KEY` loses the data. `EncryptionAtRestTest` guards this.

### Audit trail (invariant)

- Models holding personal data use the `App\Models\Concerns\Auditable` trait; any new such model must add it. Writes that bypass Eloquent call `App\Support\AuditTrail::record()`.
- `AuditSensitiveAccess` logs sensitive reads; add new sensitive URL prefixes to `SENSITIVE_PATTERNS` (no-PII polling endpoints go in its exemptions).
- `audit_logs` is append-only and enforced by triggers and an HMAC seal. **Never update or delete audit rows, and never clear them in a test** — count from a baseline. `AuditTrailTest` and `AuditLogImmutabilityTest` guard this.

### Multi-school separation (invariant)

Every query that reads or writes school-owned data is scoped by the session's `active_institution_id`, and new rows are stamped with it. Use `StudentHealthRecord::forActiveInstitution()` for LRN lookups. Child rows inherit scope through their parent — check the parent's `institution_id` before serving them. **A missing scope reads nothing, never every school.** `InstitutionScope` ejects a scoped role with no school.

### Per-section adviser scope (invariant)

A class adviser sees one class. **`App\Support\AdviserClassScope` is the one place that applies it** (`rosterRows()`, `records()`, `coversRecord()`); every adviser surface reads through it. The session roster is the whole school, so each surface must narrow it. Narrowing is decided **by role** (nurse and clinic roles see the whole school). An adviser with no assignment sees **no** learners. Writes are scoped too: `AdviserController::store` uses the session's grade/section. Enrolment has one write path: `AdviserController::enrolLearner()`, fed by `placeRows()`. `AdviserClassScopeTest` guards it.

### A record is never overwritten across school years (invariant)

`student_health_records` is one row per learner per school year (`student_id` + `institution_id` + `school_year`). Placing a learner in a new year writes a new row. Learners move by LRN, never by name, and are never re-encoded. **Never widen a write to span school years.** The only deletion is the scheduled purge of archived learners (`StudentRetention`, `StudentRecordPurge`) — see `docs/claude/school-year-and-retention.md`.

### Round trips are the cost (invariant)

Every query crosses the network to hosted Postgres, so the number of queries is what makes a page slow:
- A change-detection pulse is **one** query (`ChangeStamp::forTables()`).
- A page reads the roster **once** (`StudentHealthRecord::rosterFor()`, memoized).
- A list costs a fixed number of queries whatever its length — never query per row.
- Anything memoized that a request can also change must be invalidated on write (`RequestMemo::forgetPrefix()`).

`DashboardQueryBudgetTest` and `AdviserDashboardQueryBudgetTest` guard this. Never put a timeout between a click and its navigation.

### Live refresh never throws away typing

`App\Support\SchoolPulse` stamps the school's write surface; `partials/workspace-live` polls `workspace.pulse`. A page reloads only when nothing on it would be lost (`hasWorkInProgress()`: open dialog, focused field, edited form) — never an unconditional `location.reload()`. `WorkspaceSyncTest` guards it.

### Removed — do not re-add without asking

The HL7 FHIR exchange (2026-10-29), Parent SMS via Semaphore (2026-11-02), the SBFP consent gate on enrolment (2026-11-03), the coordinator's Feeding Program tab, and the head's Approve / Return / Lock.

### Frontend

Blade + Tailwind CSS 4, inline Alpine.js in places, Vite (`npm run build`). No Livewire.
- **LUSOG design system:** pages inline `css/lusog-theme.css`, then their page sheet, then their rail sheet, **in that order**. Other pages inline `css/lusog-palette.css` **after** their own styles (`SharedPaletteTest`). Write new rules against the `--lg-*` tokens. Green means brand/healthy only; amber is monitoring, orange at-risk, coral critical.
- Chart and status colours are validated sets: re-run the dataviz validator before changing any.
- **Nothing in the app is underlined**, except the DepEd form facsimiles.
- **Every `@media print` block must reopen the app shell** (`html, body`, `.main`, `.content` to `height: auto; overflow: visible`), or it prints one screenful.
- School Nurse pages must include `partials/nurse-page-transition`, or they render blank.
- A changed logo or image gets a **new file name** (Caddy caches `/images/*` for a week).

### Test setup

PHPUnit on in-memory SQLite (`phpunit.xml` blanks `DB_URL`, `GEMINI_API_KEY`, `ANTHROPIC_API_KEY`), so tests never reach Railway or a provider. `tests/TestCase.php` calls `Http::preventStrayRequests()`. Tests use `RefreshDatabase`; set the session with `$this->withSession([...])` (there is no Auth facade).

## Where the detail lives

Read the file for the module you are changing before changing it. Each one is the full set of rules and the tests that guard them.

| File | Covers |
|---|---|
| `docs/claude/feeding-coordinator.md` | SBFP workflow: enrolment, attendance, at-risk flag flow, cycle length, school calendar, Beneficiaries / Attendance / At-Risk tabs, SBFP forms, printing |
| `docs/claude/school-head.md` | School Head tabs, the two overview readings, reports and exports, consent and inventory oversight |
| `docs/claude/clinic.md` | Clinic Teacher, dispensing rights, incident reports, data visualization, RxNorm allergy check, consultations, vital signs, Sheet 2, MLAT, medicine stock and batches |
| `docs/claude/consent-forms.md` | Sulat-Pahibalo: school list vs parent's answer, upload dialog, photo reader, paper recorder, the nurse's consent list |
| `docs/claude/adviser-and-enrolment.md` | Adviser scope detail, class-list import and masterlist photo reader, WHO BMI classification, section catalogue |
| `docs/claude/school-year-and-retention.md` | School-year handover (close year, carry over, archive), scheduled deletion |
| `docs/claude/platform.md` | The full text of every rule this file condenses (authentication, route guard, data layer, encryption, audit, multi-school, round trips, frontend), plus account settings, Health Services Offered, cross-role live refresh, and the core models (`StudentHealthRecord`, `Condition`, `MedicalCertificate`, `NutritionalHealthStatus`) |
