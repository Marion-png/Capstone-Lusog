# Accessing the deployed Railway database

The project database is deployed on Railway, in the workspace
**Gabriel Angelo Casaña's Projects** (the collaborator's workspace, shared with
the team). That deployment is the only database this app runs against.

> **The old project `pleasing-recreation` is retired.** It is offline, and the
> tunnel recipe it used (`railway connect Postgres --tunnel-only -P 55432`) no
> longer reaches anything. Do not follow older notes that mention it.
>
> **Do not run against a local PostgreSQL either.** The accounts your
> collaborators created, the institutions and the student records exist only on
> the deployed database. A local server looks like it works and shows you an
> empty, parallel school.

---

## The short version

1. Open the Railway dashboard → workspace **Gabriel Angelo Casaña's Projects** →
   project **capstone-lusog** → the **Postgres** service → **Variables** tab.
2. Copy **`DATABASE_PUBLIC_URL`**.
3. Paste it into `.env` as `DB_URL=` (one line; nothing else to edit).
4. `php artisan config:clear && php scripts/check-railway-db.php`

That last command is the whole verification: it reports where the app is
pointed, whether it connects, whether the schema is migrated, which accounts you
can sign in as, and whether your `APP_KEY` can read the encrypted student data.

The endpoint as of 2026-09-07 (the password is **not** written down here — read
it off the Variables tab):

```
host: acela.proxy.rlwy.net   port: 43144   database: railway   user: postgres
```

That host and port change if the Postgres service is ever recreated or the proxy
is removed and re-added, so treat them as a convenience, not the source of
truth — `DATABASE_PUBLIC_URL` always is.

---

## Which URL — public, not private

A Railway Postgres service publishes two connection strings, and only one of
them works from a laptop.

| Variable | Host looks like | Reaches the DB from |
|---|---|---|
| `DATABASE_URL` | `postgres.railway.internal` | **Inside Railway only** — will not resolve on your machine |
| `DATABASE_PUBLIC_URL` | `<name>.proxy.rlwy.net:<port>` | Anywhere, over Railway's TCP proxy |

Use **`DATABASE_PUBLIC_URL`**. If the Variables tab does not show one, the
public listener is off — turn it on once, in the Postgres service:

> **Settings → Networking → Public Networking → TCP Proxy** (target port `5432`)

Railway then generates the proxy host and port, and `DATABASE_PUBLIC_URL`
appears alongside the private one.

---

## Step 1 — Get access to the workspace (blocking)

Ask Gabriel to invite your Railway account to the workspace:

> Railway dashboard → workspace **Gabriel Angelo Casaña's Projects** →
> **Settings → Members → Invite** → your email address

Until that invite is accepted you cannot read the service's variables, and no
step below will work.

## Step 2 — Put the URL in `.env`

Open `.env` and paste the copied string:

```env
DB_CONNECTION=pgsql
DB_URL=postgresql://postgres:PASSWORD@HOST.proxy.rlwy.net:PORT/railway
```

Notes:

- `DB_URL` **wins** over `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` /
  `DB_PASSWORD`. Those lines are left in `.env` as a filled-in-by-hand
  alternative; their host is a deliberate non-host, so an `.env` nobody
  configured fails loudly instead of quietly falling back to a local database.
- If the password contains `@`, `:`, `/` or `#`, percent-encode it (`@` → `%40`)
  or use the split-out fields instead, where no encoding is needed.
- Leave `DB_SSLMODE=prefer`. The proxy accepts TLS and `prefer` negotiates it
  without failing if it is unavailable.

Then reload the cached config — Laravel will otherwise keep serving the old one:

```bash
php artisan config:clear
```

## Step 3 — Verify

```bash
php scripts/check-railway-db.php
```

It is read-only (counts and one decryption; it writes nothing), so it is safe
against the shared database. Every line is either `OK`, `WARN` or `FAIL`, and it
exits non-zero if anything failed.

You can also use the stock Laravel checks:

```bash
php artisan migrate:status     # every migration should read "Ran"
php artisan tinker --execute="echo DB::table('accounts')->count();"
```

## Step 4 — Sign in

```bash
composer run dev        # or: php artisan serve
```

Open `/login` and use an account your collaborator created. Usernames are unique
**per school**, so if the same username exists at more than one school the form
asks which school you mean and you sign in again with that selected.

`/admin-login` is separate: it does not read the `accounts` table at all, and
checks `SYSTEM_ADMIN_USERNAME` / `SYSTEM_ADMIN_PASSWORD` from `.env`
(defaults `systemadmin` / `admin123`).

---

## Why a collaborator's account works regardless of `APP_KEY`

The `accounts` table is **plaintext by design** — `name`, `username`,
`password_hash`, `role`, `institution_id`, `school_name` and the adviser's
`assigned_grade_level` / `assigned_section` are all plain columns. Login looks
the username up in SQL and verifies the password with `Hash::check()` against a
bcrypt hash. None of that touches the encryptor.

So an account created by anyone, on any machine, signs in on yours.

`APP_KEY` decides something different: whether you can **read the student data**
once you are in. See the next section.

---

## The `APP_KEY` rule — read this before touching data

Names, contact details, medical answers, consent responses and signatures are
encrypted at rest with AES-256 keyed by `APP_KEY`.

> **Agree the key before anyone enters a student.** As of 2026-09-07 this
> database holds 8 accounts and 60 institutions but **zero student records**, so
> nothing has been encrypted into it yet and the check script cannot tell you
> whether your key is the right one — it has nothing to test against. The moment
> one machine saves a learner, that machine's `APP_KEY` becomes the only key that
> can read them. If teammates are running different keys, each will see the
> others' learners as `eyJpdiI6...` ciphertext, and there is no way to merge
> them afterwards.
>
> Compare keys without revealing one by having everyone run:
>
> ```bash
> php -r "echo substr(hash('sha256', trim(explode('=', preg_grep('/^APP_KEY=/', file('.env'))[array_key_first(preg_grep('/^APP_KEY=/', file('.env')))], 2)[1])), 0, 16), PHP_EOL;"
> ```
>
> Everyone must print the same 16 characters. This machine prints
> `5dc44715428d9cbd`.

- Get the `APP_KEY` from whoever seeded this database, **directly** — over a
  private channel, never in a commit, a group chat, or a screenshot. Paste it
  into your local `.env` verbatim, including the `base64:` prefix.
- **Never run `php artisan key:generate` on a machine pointed at this
  database.** It mints a new key, and every encrypted column instantly becomes
  permanently unreadable. There is no recovery.
- If rows come back as long `eyJpdiI6...` strings, your `APP_KEY` is wrong —
  stop and re-copy it rather than "fixing" the data. The custom casts in
  `App\Casts` fall back to the raw value instead of throwing, so a wrong key
  shows you ciphertext on the page rather than a 500; it is easy to miss.

To test a candidate key without putting it anywhere:

```bash
php try-app-key.php "base64:XXXXXXXX..."
```

It prints `MATCH` or `NO MATCH`. Check 5 of `scripts/check-railway-db.php` runs
the same test against the key already in your `.env`.

---

## Rules for the shared database

This is the real database, shared by the whole team. There is no staging copy.

- **Never** run `php artisan migrate:fresh`, `db:wipe`, or `migrate:rollback`
  against it. `migrate:fresh` drops every table; the student records are gone.
- `php artisan migrate` (forward only) is fine, but agree it with the team
  first — everyone's app runs on the same schema.
- Treat `audit_logs` as read-only evidence — never edit or delete rows.
- Take a backup before anything destructive:
  ```bash
  pg_dump "postgresql://postgres:PASSWORD@HOST.proxy.rlwy.net:PORT/railway" -f backup.sql
  ```
- Backups contain student health data. Keep them off shared drives and out of
  git, and delete them when you are done.

---

## Connecting with psql / pgAdmin / DBeaver

No tunnel and no CLI needed — the public proxy is a normal TCP endpoint.

```bash
psql "postgresql://postgres:PASSWORD@HOST.proxy.rlwy.net:PORT/railway"
```

```sql
\dt                                  -- the app's tables
SELECT count(*) FROM institutions;
SELECT role, school_name, username FROM accounts ORDER BY role;
\q
```

For a GUI, take Host / Port / Database / Username / Password out of the same
`DATABASE_PUBLIC_URL`. SSL may be left at the client default.

---

## Fallback: the Railway CLI tunnel

Only needed if the service has no public listener and you cannot enable one.

```bash
npm install -g @railway/cli
railway login
railway link                       # pick the workspace, project, environment
railway connect Postgres --tunnel-only -P 55432
```

Leave that terminal open and point `.env` at `127.0.0.1:55432` (the tunnel
prints the password). Do the rest of your work in a second terminal.

> **On this machine the CLI does not run.** `railway.exe` is blocked by a
> Windows Application Control policy ("An Application Control policy has blocked
> this file"), so the tunnel route is unavailable here and the public proxy URL
> is the way in. Nothing in the app depends on the CLI.

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| `could not translate host name "PASTE-RAILWAY-HOST-HERE..."` | `.env` was never filled in — paste `DATABASE_PUBLIC_URL` into `DB_URL` |
| `could not translate host name "postgres.railway.internal"` | That is the private URL — use `DATABASE_PUBLIC_URL` |
| `Connection refused` / `timeout` on `*.proxy.rlwy.net` | The public TCP proxy is off, or the service is asleep/removed — check the Railway dashboard |
| `password authentication failed` | The password rotated (it changes if the service is recreated) — re-copy `DATABASE_PUBLIC_URL` |
| Config changes seem ignored | `php artisan config:clear` |
| `Account was not found` at `/login` | You are on the wrong database (likely a local one) — run `php scripts/check-railway-db.php` |
| Data reads as `eyJpdiI6...` gibberish | Wrong `APP_KEY` — see the `APP_KEY` rule above |
| Railway CLI prints nothing, exit 1 | Application Control has blocked `railway.exe` on this machine — use the public proxy URL |
