# Deployment

The app runs on Railway as the **`lusog-web`** service in project
**capstone-lusog** (workspace *Gabriel Angelo Casaña's Projects*), beside the
Postgres service it reads.

**URL:** https://lusog-web-production.up.railway.app

## Redeploying

From the repository root, on the code you mean to ship:

```bash
railway up --service lusog-web --ci
```

That uploads the working tree (minus `.gitignore` and `.railwayignore`),
builds it and prints the build log. Railpack builds it on FrankenPHP:
`composer install`, `npm run build`, then `config`/`route`/`view`/`event`
caches. A failed build leaves the previous deployment serving.

**Migrations do not run on deploy** (`RAILPACK_SKIP_MIGRATIONS=true`). The
database is shared with every developer's machine, so a new migration is
applied deliberately with `php artisan migrate`, agreed with the team, before
the code that needs it is deployed.

## Service settings

Every value lives in Railway → `lusog-web` → **Variables**, never in the repo.

| Variable | Value | Why |
|---|---|---|
| `APP_KEY` | the team key | Must be the key the student data was encrypted with. Never generate a new one. |
| `DB_URL` | `${{Postgres.DATABASE_URL}}` | Railway's private network, so no password is copied anywhere. |
| `APP_ENV` / `APP_DEBUG` | `production` / `false` | Production also switches the prototype auto-session **off**. Never set `PROTOTYPE_SESSIONS` here. |
| `SYSTEM_ADMIN_PASSWORD` | generated | The `/admin-login` password. Read it from the Variables tab. |
| `SESSION_DRIVER` | `database` | Signed-in users survive a redeploy. |
| `CACHE_STORE` | `file` | Keeps the deployment out of the shared `cache` table. |
| `QUEUE_CONNECTION` | `sync` | No queue worker runs, and nothing is queued today. |
| `LOG_CHANNEL` | `stderr` | Logs appear under `railway logs --service lusog-web`. |
| `RAILPACK_PHP_EXTENSIONS` | `pdo_pgsql,zip` | Postgres, and OpenSpout's XLSX writer. |
| `GEMINI_API_KEY` | the team key | Masterlist photo reading. Leave it empty to disable. |

**Uploaded documents** (medical certificates, consent scans, attendance
photos) live on the volume `lusog-web-volume`, mounted at `/app/storage/app`.
A file uploaded from a developer's laptop is stored on that laptop, not here,
so the deployed site cannot open it even though the shared database lists it.

## The web server (`Caddyfile`)

Railpack uses the `Caddyfile` at the repository root in place of its default.
It is that default plus:

- **Only `public/index.php` runs.** Any other `.php` file under `public/`
  (including a path-info URL such as `/debug.php/x`) answers 404. The first
  deploy served `public/phpinfo-check.php`, which printed every environment
  variable — `APP_KEY` and the database password included — to anyone.
  `PublicExposureTest` fails the build if a second script appears there.
- **Dotfiles and `build/manifest.json` answer 404.**
- **No `X-Powered-By`** (`expose_php Off`), and `nosniff` on static files.
- **Static files are cached by the browser**: fingerprinted `build/assets/*`
  for a year, `images/*` for a week.
- **Logs at INFO**, not Railpack's per-request DEBUG.
- **opcache does not re-check file timestamps** — the container's code never
  changes after the build.

The application adds its own headers to every response it sends
(`App\Http\Middleware\SecurityHeaders`): nosniff, `X-Frame-Options`,
`Referrer-Policy: same-origin`, `X-Robots-Tag: noindex`, and HSTS over HTTPS.

## Things that broke the first deploy

- **The root `php.ini` is a Windows config** (`extension_dir` on `C:\`).
  Railpack picks up a root `php.ini`, and this one stopped every extension,
  OpenSSL included, from loading. `.railwayignore` keeps it out of the upload.
- **`storage/framework/views` must exist** or `view:cache` fails, so
  `.railwayignore` excludes the *contents* of the storage directories and keeps
  their placeholder `.gitignore` files.
- **PHP version comes from `composer.json`.** `^8.2` built on PHP 8.2, which
  cannot install the lock file. It is `^8.4` now.
- **The app listens on `$PORT` (8080)**, so the public domain targets 8080.
- **Git Bash rewrites `/app/...` arguments into Windows paths.** Prefix
  `MSYS_NO_PATHCONV=1` to a `railway` command that takes a container path.

## Region

Postgres runs in **Southeast Asia**. Every page makes many database round
trips, so the web service must run in the same region. Check it under
`lusog-web` → Settings → Deploy → Regions, or move it with:

```bash
railway service scale --service lusog-web southeast-asia=1 us-east=0
```

The first deploy landed in **US East** (`iad`). Each round trip then crossed
the Pacific: a bare redirect took 4.4 s and a dashboard 8–13 s, against
milliseconds once both services share a region. The `lusog-web-volume` is
region-bound: Railway migrates it with the service, and the site is down while
it copies (seconds while it is small). A service with a volume runs one
replica, never several.
