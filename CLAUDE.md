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

### Authentication — Session-Only, No Laravel Auth

This app does **not** use `Laravel\Auth` or the `users` table for login. Authentication is entirely session-based:

- On every visit to `/` or `/login`, six hardcoded demo accounts are injected into `session('user_accounts')` if not already present (see top of `routes/web.php`).
- Login (`POST /login`) looks up the username in `session('user_accounts')`, verifies the password hash, then writes role data into the session.
- The active session keys are: `active_role`, `active_name`, `active_username`, `active_school_name`. Class advisers also get `assigned_grade_level` and `assigned_section`.
- `session('user_accounts')` holds all accounts (demo + system-admin-approved). `session('pending_account_requests')` holds unapproved registrations.
- System admin login (`/admin-login`) uses `SYSTEM_ADMIN_USERNAME` / `SYSTEM_ADMIN_PASSWORD` env vars (defaults: `systemadmin` / `admin123`).

### Route Guard

`App\Http\Middleware\EnsureActiveSession` — the only auth middleware. It checks `session()->has('active_role')` and redirects to login if missing. It also sets `no-cache` headers on every response (authenticated or not) to prevent browser history from showing authenticated pages after logout.

Applied to all routes under: `dashboard/*`, `adviser/*`, `nurse/*`, `health-records/*`.

### Role System

Roles stored as strings in `session('active_role')`. The seven roles are:

| session value | Description |
|---|---|
| `school_nurse` | Manages deworming, consultations, health records |
| `clinic_staff` | Consultation logging, medicine inventory |
| `class_adviser` | Student data entry, medical certificates, consent forms |
| `school_head` | Reports, deworming approval |
| `feeding_coor` | SBFP feeding program attendance |
| `nutricor` | Nutrition analytics, consolidated reports |
| `system_admin` | Account approval/management |

Role checks in controllers are manual string comparisons on `session('active_role')`.

### Routing Pattern

All routes are inline closures or controller actions in `routes/web.php` — no route groups by role. Permission checks inside closures and controllers gate access manually. There is no route-level role middleware.

### Data Layer

- **Database:** SQLite (`database/database.sqlite`), 15 migrations.
- **Session storage:** `SESSION_DRIVER=file`. Session data is in `storage/framework/sessions/`.
- Some data (deworming requests, account lists) falls back to session when DB tables don't exist — the controllers check `Schema::hasTable()` before querying.
- **Uploaded files** (medical certificates, parental consent forms) are stored in `storage/app/private/` using Laravel's `private` disk.

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
