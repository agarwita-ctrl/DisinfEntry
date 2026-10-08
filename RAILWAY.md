# Deploying DisinfEntry on Railway - step by step

This takes you from this repository on GitHub to a working site at
`https://<something>.up.railway.app`. It assumes Windows and no Docker or Railway CLI.

Railway's screens and plans change; menu names below are a guide, not a promise. The Docker
image in this repository has been tested for routing and access control locally, but a full
Railway deploy is only confirmed by the checks in **Part 8**.

**What gets deployed**

| Piece | What it is |
|-------|------------|
| Web app | The `Dockerfile` in this repo: PHP 8.2 running PHP's built-in web server with 8 workers. `docker/router.php` re-creates the `.htaccess` protections (private folders, source files, dotfiles, headers), because the built-in server does not read `.htaccess`. |
| Database | A Railway **MySQL** service in the same project. |
| Web address | A free `*.up.railway.app` domain with HTTPS, or your own domain. |

An earlier Apache-based image crashed on Railway with `AH00534: More than one MPM loaded`; that
is why the image uses the built-in server. It suits one farm's traffic. For heavy load, run the
app behind Apache or nginx on a VPS instead.

---

## Part 0 - Before you start

1. **A GitHub repository** with this project pushed to `main`, **private** recommended.
   Check on github.com that `config/database.local.php` and `esp32/DisinfEntry_Booth/secrets.h`
   are **not** in the file list (they are git-ignored; if either shows up, stop and remove it).
2. **A Railway account** (railway.com), signed in with the same GitHub account. Railway gives
   a trial credit; after that it is paid by usage. Check their current pricing before relying
   on it.
3. **A database client** for the import in Part 5: **HeidiSQL** or **DBeaver Community** (both
   free); the guide covers both. XAMPP's own `mysql.exe` may fail against Railway's MySQL 9 with an
   authentication-plugin error, so do not rely on it.

---

## Part 1 - Create the project and deploy the app

1. Railway dashboard -> **New Project** -> **Deploy from GitHub repo**.
2. If asked, allow Railway to access your GitHub repositories, then pick **DisinfEntry**.
3. Railway starts a build from the `Dockerfile`. Wait for it to finish (a few minutes).

**Check it is building the right thing:** open the service -> **Deployments**. The newest entry
must show your **latest commit message** (the top commit on GitHub). If it shows an older one,
see "Railway keeps deploying an old commit" in Part 9.

At this point the app will start but show *"Database connection failed"* - that is expected; the
database is added next.

---

## Part 2 - Add the MySQL database

1. In the same project: **New** (or the `+` button) -> **Database** -> **Add MySQL**.
2. Wait until it shows as running. Its **Variables** tab lists `MYSQLHOST`, `MYSQLPORT`,
   `MYSQLDATABASE`, `MYSQLUSER`, `MYSQLPASSWORD`. Do not edit anything here.

The service is named **MySQL** by default. If yours is named differently, use that name in
Part 3 instead of `MySQL`.

---

## Part 3 - Connect the app to the database

On the **web service** (DisinfEntry, *not* the MySQL one) -> **Variables** -> **Raw Editor**, and
paste:

```
DISINFENTRY_DB_HOST=${{MySQL.MYSQLHOST}}
DISINFENTRY_DB_PORT=${{MySQL.MYSQLPORT}}
DISINFENTRY_DB_NAME=${{MySQL.MYSQLDATABASE}}
DISINFENTRY_DB_USER=${{MySQL.MYSQLUSER}}
DISINFENTRY_DB_PASS=${{MySQL.MYSQLPASSWORD}}
DISINFENTRY_BEHIND_PROXY=1
```

Save and let it redeploy.

- The `${{MySQL.…}}` values are live links to the database service - never type the real
  password, and it stays correct if Railway changes it.
- `DISINFENTRY_BEHIND_PROXY=1` makes the app trust Railway's proxy headers, so the session cookie
  is `Secure`, HSTS is sent, and the login lockout and audit log see the visitor's real address.
  Set it **only** when running behind Railway (or another proxy you control).
- Do **not** add a `config/database.local.php` on Railway: it would override these variables.

---

## Part 4 - Open a public connection to the database (temporary)

The import runs from your PC, so the database needs a public address for a few minutes.

1. **MySQL service -> Settings -> Networking -> TCP Proxy** (wording may differ: "Public
   Networking") -> add a proxy for port **3306**.
2. Note the **host** and **port** it shows (like `something.proxy.rlwy.net` and a number).
3. Gather the login from the MySQL service's **Variables** tab (click the eye icon):
   - user: `MYSQLUSER` (usually `root`)
   - password: `MYSQLPASSWORD`
   - database: `MYSQLDATABASE` (usually `railway`)

---

## Part 5 - Import the database

Use the file **`database/disinfentry_deploy.sql`** from this repository. It already includes the
three migrations, the tables in the right order and the default accounts, and it has no
`USE` / `CREATE DATABASE` lines - so it imports into Railway's pre-made database as is. (Do not
use `disinfentry.sql` plus the migrations: the migrations start with `USE disinfentry;`, which
fails here.)

With DBeaver:

1. **Database -> New Database Connection -> MySQL.**
2. Fill in **Server Host** and **Port** from Part 4, **Database** (`railway`), **Username** and
   **Password**. On the *Driver properties* tab set `allowPublicKeyRetrieval` to `true`
   if DBeaver complains about it. Click **Test Connection**, then **Finish**.
3. Right-click the connection -> **SQL Editor -> New SQL script**.
4. **File -> Open File…** is awkward here; instead drag `database/disinfentry_deploy.sql` into the
   editor, or open it in Notepad, copy everything and paste it.
5. Run the **whole script** with **Alt + X** (*Execute SQL Script*), not Ctrl + Enter.
6. When it finishes, refresh the connection's tables. You should see **14 tables**; `users`
   has 3 rows and `settings` has 16.

With **HeidiSQL** instead:

1. **New** session -> network type *MariaDB or MySQL (TCP/IP)*; **Hostname** and **Port** from
   Part 4; **User** and **Password** from the MySQL service's Variables; **Databases**: `railway`.
2. Click **Open**. If it fails with *authentication plugin 'caching_sha2_password' cannot be
   loaded*, edit the session and on the **Settings** tab change **Library** to the newest
   `libmysql-…dll` in the list (Railway runs MySQL 9).
3. Select `railway` in the left list, then **Tools -> Run SQL file…** and choose
   `database/disinfentry_deploy.sql`. (Or **File -> Load SQL file…** and press **F9**.)
4. Right-click `railway` -> **Refresh**: you should see 14 tables, 3 rows in `users`, 16 in `settings`.

If it fails partway, run the script again - it begins by dropping its own tables.

**When done: close the public door.** Back in **MySQL -> Settings -> Networking**, **remove the
TCP proxy**. The app reaches the database over Railway's private network and does not need it.

---

## Part 6 - Publish the site

1. Web service -> **Settings -> Networking -> Generate Domain**.
2. If asked for a port, use the one Railway suggests (the app listens on Railway's `PORT`).
3. Open the `https://….up.railway.app` address. You should see the sign-in page.

First sign-in:

| Account | Username | Password |
|---------|----------|----------|
| Administrator | `admin` | `Admin@123` |
| Farm manager | `manager` | `Manager@123` |

1. The app holds you on **My Profile** until you set a new password. Do it now, for each account
   you will keep.
2. **System Settings**: set farm name, system name and **timezone**.
3. **System Settings -> Booth API Access -> Regenerate** the API key and keep it for the booth
   firmware. Until you do, an admin banner warns that the key is still the public default.

---

## Part 7 - Keep the logo across deploys (optional)

The container's disk is wiped on every deploy, which would delete an uploaded logo. To keep it:
web service -> **Settings -> Volumes -> New Volume** -> mount path **`/app/assets/uploads`**.

Sessions also live on the container's disk, so everyone is signed out after each deploy. That is
expected.

---

## Part 8 - Verify it works

Open these addresses on your Railway domain (replace the host):

| Address | Should show |
|---------|-------------|
| `/config/database.php` | 403 Forbidden |
| `/includes/functions.php` | 403 Forbidden |
| `/database/disinfentry_deploy.sql` | 403 Forbidden |
| `/.htaccess` | 403 Forbidden |
| `/api/ping.php` | `{"disinfentry":true}` |
| `/login.php` | the sign-in page |

Then, signed in:

- Dashboard and **Entry Monitoring** load (empty is normal - no booth has reported yet).
- **Export CSV** and **Export Excel** both download.
- In the browser's developer tools -> Application -> Cookies, the session cookie
  `DISINFENTRY_SID` has the **Secure** flag.

---

## Part 9 - Updating the site later

1. Change code, commit, `git push`.
2. Railway builds and deploys the new commit automatically.
3. **Database changes:** put new SQL in `database/migrations/`, then run that file against Railway's
   database the same way as Part 5 (open the TCP proxy, run it in DBeaver, close the proxy).

---

## Part 10 - The ESP32 booth

The firmware supports both a local XAMPP server and a hosted one. Which it uses is decided by one
line in `esp32/DisinfEntry_Booth/secrets.h` (git-ignored; copy `secrets.example.h` if you do not
have it):

1. Uncomment and fill in your Railway address - `https`, no trailing slash, **no folder**:
   ```
   #define SERVER_URL_VALUE "https://your-app.up.railway.app"
   ```
2. Set `API_KEY` to the **new key you regenerated on Railway** (Part 6), not the old local one.
3. Re-flash. The Serial Monitor (115200 baud) should print
   `Server: https://… (fixed address, TLS)` and then `>> Synced …` after the first sync.

With `SERVER_URL_VALUE` left out, the booth behaves as before: it finds a XAMPP server on the local
network by mDNS name or subnet sweep.

**Known limitation:** the connection is encrypted but the server's certificate is **not verified**
(the ESP32 has no clock until given NTP, and no CA certificate is bundled). Someone actively
impersonating your site on the booth's own WiFi could read the API key. Treat the key as disposable
- it can be regenerated in System Settings - and never reuse it. Verified TLS (NTP + a pinned CA)
is a worthwhile next step.

**Build notes:** compiled for `esp32:esp32:esp32` (core 3.3.x) with ESP32Servo and Adafruit
MLX90614: 85% of flash and 16% of RAM in both modes. If you add features and run out of flash, pick
the *Huge APP* partition scheme in the Arduino IDE.

---

## Troubleshooting

| What you see | Cause and fix |
|--------------|---------------|
| **Railway keeps deploying an old commit**; the Deployments list is missing your latest commit | Railway did not pick up the push. **Settings -> Source**: confirm repo `…/DisinfEntry`, branch `main`, **Wait for CI** off, automatic deploys on. If still stuck, disconnect and reconnect the repo. **Restart/Redeploy rebuild the same old commit** - they do not help. |
| Log shows `apache2` / `AH00534: More than one MPM loaded` | You are looking at a deployment from the old Apache-based image. Only a deployment built from a recent commit (Dockerfile says `php:8.2-cli`) is current. |
| **Crashed** immediately, log says a command was not found | Clear **Settings -> Custom Start Command**. The image starts itself; an old `apache2-foreground` command no longer exists. |
| **Build failed** | Open that deployment's **Build Logs** and read the last 20 lines; the error is there. |
| Site shows **"Database connection failed"** | Part 3 variables are missing or wrong, or the database was not imported (Part 5). Check the names match your database service. |
| 502 / "Application failed to respond" | The service crashed or is not listening on Railway's `PORT`. Open **Deploy Logs**; with the built-in server you should see a line like `Development Server (http://0.0.0.0:…) started`. |
| Signed in, then bounced back to login | `DISINFENTRY_BEHIND_PROXY=1` is missing, so the cookie is not treated as secure over HTTPS. Add it and redeploy. |
| Import: `Unknown database` or `Access denied` | Wrong database name or password in the DBeaver connection; copy them again from the MySQL service's Variables tab. |
| Import: `Authentication plugin 'caching_sha2_password' cannot be loaded` | Used XAMPP's `mysql.exe`: use HeidiSQL or DBeaver instead. In HeidiSQL, if it still appears, set the session's **Library** to the newest `libmysql-…dll`. |
| Logo disappears after a deploy | Add the volume in Part 7. |
