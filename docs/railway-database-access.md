# Accessing the deployed Railway database

The project database is deployed on Railway (project **pleasing-recreation**,
service **Postgres**, PostgreSQL 18.4).

It has **no public listener** — you cannot reach it by pasting a host and port
into pgAdmin. Every connection goes through an SSH tunnel opened by the Railway
CLI. This is deliberate: the database holds student health records.

Work through the steps in order. Steps 0–4 are one-time setup; step 5 is what
you repeat each time you want to connect.

---

## Step 0 — Get invited to the Railway project (blocking)

Ask the project owner (Dan) to invite your Railway account:

> Railway dashboard → project **pleasing-recreation** → **Settings** →
> **Members** → **Invite** → your email address

You cannot complete any step below until that invite is accepted. If
`railway list` does not show `pleasing-recreation`, the invite has not landed
yet — stop and chase it rather than continuing.

---

## Step 1 — Install the tools

You need three things: **Node.js**, the **Railway CLI**, and the **PostgreSQL
client tools** (`psql` / `pg_dump`).

```bash
# Railway CLI (needs Node.js installed first)
npm install -g @railway/cli

# check it worked
railway --version
```

For `psql`, install PostgreSQL from https://www.postgresql.org/download/ — during
setup you may untick "PostgreSQL Server" and keep only **Command Line Tools** if
you do not want a local server. Then confirm:

```bash
psql --version
```

If `psql` is not recognised on Windows afterwards, add
`C:\Program Files\PostgreSQL\18\bin` to your PATH.

---

## Step 2 — Log in to Railway

```bash
railway login
```

This opens your browser. Approve the request there, then confirm:

```bash
railway whoami
```

> If you are on a machine with no browser, use `railway login --browserless`.
> It prints a short code — enter it at https://railway.com/activate **within
> about two minutes**, or it expires and you have to start again.

---

## Step 3 — Link the repository to the project

From inside the project folder:

```bash
cd path/to/Capstone-Lusog
railway link --project pleasing-recreation --environment production
railway status
```

`railway status` should list the **Postgres** service as `● Online`.

---

## Step 4 — Register an SSH key

The tunnel authenticates with an SSH key. If you have never made one:

```bash
ssh-keygen -t ed25519
```

Press Enter at each prompt to accept the defaults. Then register it and confirm:

```bash
railway ssh keys add --name "my-laptop"
railway ssh keys list
```

---

## Step 5 — Open the tunnel

This is the command you run every time you want to reach the database:

```bash
railway connect Postgres --tunnel-only -P 55432
```

It prints the connection details and then **stays open**. Leave this terminal
window running — closing it or pressing `Ctrl+C` shuts the tunnel down. Do all
your database work in a *second* terminal.

The output looks like this:

```
PostgreSQL tunnel open — point an external client at:

  Host:     127.0.0.1
  Port:     55432
  User:     postgres
  Password: <generated password>
  Database: railway
```

Copy the password from your own output — it is not written down anywhere in
this repository, and it changes if the service is ever recreated.

---

## Step 6 — Connect

### With `psql`

In a second terminal, while the tunnel is running:

```bash
psql -h 127.0.0.1 -p 55432 -U postgres -d railway
```

Enter the password from step 5 when prompted. Quick check:

```sql
\dt                                  -- should list 29 tables
SELECT count(*) FROM institutions;   -- should return 60
\q
```

### With pgAdmin / DBeaver / TablePlus

With the tunnel running, create a new connection using exactly these values:

| Field | Value |
|---|---|
| Host | `127.0.0.1` |
| Port | `55432` |
| Database | `railway` |
| Username | `postgres` |
| Password | from the tunnel output |

Do **not** enable SSL/TLS — the tunnel already encrypts the traffic, and the
local end of it is a plain socket.

### Running the Laravel app against it

With the tunnel running, edit `.env`: comment out the five local `DB_` lines
and uncomment the Railway block, filling in the password from step 5. Then:

```bash
php artisan config:clear
php artisan migrate:status     # all migrations should read "Ran"
```

---

## The APP_KEY rule — read this before touching data

Names, contact details, medical answers, consent responses and signatures are
**encrypted at rest** with AES-256 keyed by `APP_KEY`.

- Get the `APP_KEY` from Dan **directly** — over a private channel, never in a
  commit, a group chat, or a screenshot. Paste it into your local `.env`
  verbatim, including the `base64:` prefix.
- **Never run `php artisan key:generate` on a machine pointed at this
  database.** It mints a new key, and every encrypted column instantly becomes
  permanently unreadable. There is no recovery.
- If rows come back as long `eyJpdiI6...` strings, your `APP_KEY` is wrong —
  stop and re-copy it rather than "fixing" the data.

---

## Rules for the shared database

This is the real database, shared by the whole team. There is no staging copy.

- **Never** run `php artisan migrate:fresh`, `db:wipe`, or `migrate:rollback`
  against it. `migrate:fresh` drops every table; the student records are gone.
- Treat `audit_logs` as read-only evidence — never edit or delete rows.
- Take a backup before anything destructive:
  ```bash
  pg_dump -h 127.0.0.1 -p 55432 -U postgres -d railway -f backup.sql
  ```
- Backups contain student health data. Keep them off shared drives and out of
  git, and delete them when you are done.

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| `Unauthorized. Please login` | Run `railway login` again |
| `No SSH keys found` | Do step 4 |
| `Local port 55432 is already in use` | A tunnel is already running — use it, or pick another port with `-P 55433` |
| `Device code expired` | The browserless code lasts ~2 minutes. Use plain `railway login` instead |
| `pleasing-recreation` missing from `railway list` | Step 0 is incomplete — you have not been invited |
| Connection refused on 127.0.0.1:55432 | The tunnel terminal is closed. Reopen it (step 5) |
| Data reads as `eyJpdiI6...` gibberish | Wrong `APP_KEY` — see the APP_KEY rule above |
