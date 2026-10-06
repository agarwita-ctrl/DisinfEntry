# Deploying DisinfEntry on Railway

The repository ships a `Dockerfile` (PHP 8.2 + Apache), so Railway builds and runs it as is.
The image is **not** tested on Railway from this repository's history - follow the checks at the
end. Railway's screens and plan details change; treat the menu names below as a guide.

## 1. Create the project

1. Push this repository to GitHub (private is fine).
2. Railway -> **New Project** -> **Deploy from GitHub repo** -> pick it. Railway detects the
   `Dockerfile` and builds it.
3. In the same project: **New** -> **Database** -> **Add MySQL**.

## 2. Connect the app to the database

On the **web service** -> *Variables*, add these. They use Railway's reference syntax; replace
`MySQL` with the name of your database service if it differs.

| Variable | Value |
|----------|-------|
| `DISINFENTRY_DB_HOST` | `${{MySQL.MYSQLHOST}}` |
| `DISINFENTRY_DB_PORT` | `${{MySQL.MYSQLPORT}}` |
| `DISINFENTRY_DB_NAME` | `${{MySQL.MYSQLDATABASE}}` |
| `DISINFENTRY_DB_USER` | `${{MySQL.MYSQLUSER}}` |
| `DISINFENTRY_DB_PASS` | `${{MySQL.MYSQLPASSWORD}}` |
| `DISINFENTRY_BEHIND_PROXY` | `1` |

`DISINFENTRY_BEHIND_PROXY=1` makes the app trust Railway's `X-Forwarded-Proto` and
`X-Forwarded-For` headers, so the session cookie is `Secure`, HSTS is sent, and the login
throttle and audit log see the visitor's address instead of the proxy's. Set it **only** when
the app runs behind a proxy you trust.

Do not create `config/database.local.php` on Railway - it would override these variables.

## 3. Import the database

Use the MySQL service's *Connect* tab (public/TCP proxy details) with any MySQL client:

```
mysql -h <host> -P <port> -u <user> -p <database> < database/disinfentry.sql
mysql -h <host> -P <port> -u <user> -p <database> < database/migrations/001_booth_sync.sql
mysql -h <host> -P <port> -u <user> -p <database> < database/migrations/002_schema_tuning.sql
mysql -h <host> -P <port> -u <user> -p <database> < database/migrations/003_audit_hardening.sql
```

The migration files begin with `USE \`disinfentry\`;` - remove that line (or import into a
database named `disinfentry`) because Railway's database has its own name. DBeaver or
phpMyAdmin work equally well.

## 4. Open the site

*Settings* -> *Networking* -> **Generate Domain**. Open the `https://....up.railway.app`
address and sign in.

- Default accounts and the first-login password change are described in the README.
- System Settings -> regenerate the **booth API key**.
- Set the timezone.

## 5. Keep the logo across deploys (optional)

The container's disk is wiped on every deploy. To keep an uploaded logo, add a **Volume** to
the web service mounted at `/var/www/html/assets/uploads`. Sessions also live on disk, so
everyone is signed out after a deploy; that is expected.

## 6. The ESP32 booth

The firmware currently finds a server on the local network and speaks plain `http`. For a
Railway deployment it must instead use your public hostname over `https` (port 443) with a
TLS-capable client, and `SERVER_PATH` becomes empty (the site is at the domain root, not
under `/DisinfEntry`). That is a firmware change, not covered here.

## Checks after the first deploy

- `/config/database.php`, `/includes/functions.php` and `/database/` must return 403 or 404.
- Sign-in works and the session cookie shows the `Secure` flag in the browser's dev tools.
- Entry Monitoring loads; Export CSV and Excel download.
