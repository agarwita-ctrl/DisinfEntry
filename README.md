# DisinfEntry

**Smart Disinfection and Entry Monitoring System for Poultry Farms**

A web-based monitoring and management console for an ESP32 disinfection booth. The booth
screens every person entering the farm with a PIR sensor, an ultrasonic distance sensor and
an MLX90614 infrared thermometer, runs an automatic misting cycle, and posts the result to
this application over a JSON REST API. The console stores, displays, analyses and reports on
every entry in real time.

---

## Technology stack

| Layer     | Technology |
|-----------|------------|
| Backend   | PHP 8.2+, PDO, REST API (JSON) |
| Database  | MySQL / MariaDB |
| Server    | Apache (XAMPP) |
| Frontend  | HTML5, CSS3, Bootstrap 5.3, JavaScript ES6, AJAX |
| Charts    | Chart.js 4 |
| Tables    | DataTables 1.13 (server-side processing) |
| Firmware  | Arduino C++ (ESP32) |

No Composer packages and no build step — every third-party library loads from a CDN.

> **Security note for forks and contributors:** this repository ships with *documented default
> accounts and a default booth API key* so it runs out of the box. They are rejected or flagged
> in the app, but change them before any real use. Never commit `config/database.local.php` or
> `esp32/DisinfEntry_Booth/secrets.h` (both are git-ignored).

---

## Installation

### 1. Copy the project into XAMPP

```
C:\xampp\htdocs\DisinfEntry\
```

Start **Apache** and **MySQL** from the XAMPP Control Panel.

### 2. Import the database

Open <http://localhost/phpmyadmin> → **Import** → choose `database/disinfentry.sql` → **Go**.

Or from the command line:

```powershell
C:\xampp\mysql\bin\mysql.exe -u root < database\disinfentry.sql
```

This creates the `disinfentry` database, every table and the default accounts.

> **Upgrading an existing database?** `disinfentry.sql` drops and recreates the tables. To
> keep the data you already have, run the migrations instead, in order. Both are safe to
> re-run and neither deletes or rewrites any data:
>
> ```powershell
> C:\xampp\mysql\bin\mysql.exe -u root < database\migrations\001_booth_sync.sql
> C:\xampp\mysql\bin\mysql.exe -u root < database\migrations\002_schema_tuning.sql
> ```

### 3. Check the database credentials

Credentials are not stored in `config/database.php`. Copy `config/database.local.example.php`
to `config/database.local.php` and fill in the MySQL user for this machine (the example file
shows how to create a dedicated account). For a throwaway local XAMPP you can set
`'user' => 'root', 'pass' => ''` there. There is no silent fallback to root.

### 4. Open the application

<http://localhost/DisinfEntry/>

> **Edit the copy Apache actually serves.** Apache runs the files under
> `C:\xampp\htdocs\DisinfEntry\`. If you also keep the project somewhere else (a Desktop
> working folder, for example), changes made there have no effect until you copy them into
> `htdocs`. When a fix appears not to work, confirm which copy you edited first.

---

## Default accounts

| Role | Username | Password | Access |
|------|----------|----------|--------|
| Administrator | `admin` | `Admin@123` | Everything |
| Farm Manager  | `manager` | `Manager@123` | Dashboard, live monitor, entries, reports, notifications |

> **Change both passwords immediately after the first sign-in** (My Profile → Change Password).

---

## Modules

### Authentication
Session-based login with bcrypt (cost 12) password hashing, CSRF protection on every write,
a 30-minute idle timeout, a per-account-and-address login lockout, forgot-password with single-use expiring
tokens, and change-password with a strength meter. Deactivating an account terminates its
session on the next request.

### Dashboard
Five KPI cards (today's entries, successful, denied, high-temperature alerts, active users)
with day-over-day trends; charts for daily / weekly / monthly volume, pass-vs-denied and
temperature distribution; and a recent-activity table that refreshes itself.

### Live Monitoring
Polls `api/live.php` on the configured interval (3 s by default) and shows the current
entrant, temperature, misting state, access decision and timestamp. New arrivals trigger an
automatic refresh, a toast, a popup for alerts, and a notification sound. Sound and polling
can be toggled; polling slows to 30 s while the browser tab is hidden.

The **Booth Status** panel adds what the booth reports about itself: its last distance and
temperature readings with their age, when it last ran a cycle, its firmware, and a warning
if it cannot find its thermometer.

> **The console polls faster than the booth reports.** The booth syncs only while idle with
> nobody in range — a blocking POST must never land inside a spray burst or a door hold — so
> a cycle arrives already finished, and sensor readings arrive batched up to a heartbeat
> (30 s) apart. The page shows how stale each reading is rather than implying a live feed.
> Mid-cycle state is not observable from the server by design.

### Entry Monitoring
Every booth record with server-side search, sort and pagination. Filters: exact date, month,
date range, temperature band, access status, disinfection status, booth and name/employee ID.

Opening a record shows the **booth cycle** behind it: the screening and ambient readings, the
threshold the booth was running *at the time*, the trigger distance, each spray burst, the
door movement, and the state-machine sequence timed from first detection. Entries created by
the legacy `api/entry.php` have no cycle and say so.
Exports to CSV and Excel, plus a print-optimised layout. Administrators can delete records.

### Reports
Daily, weekly, monthly, yearly and custom date ranges. Each report shows total entries,
passed, denied, high temperature and average temperature, plus a trend chart, temperature
spread, denial-reason breakdown, a per-person table and the full detail log. Exports to
Excel and CSV; **Export PDF** opens a print-ready A4 landscape sheet with signature blocks —
choose *Save as PDF* in the browser's print dialog.

### Notifications
Raised automatically for high temperature, denied entry, booth offline, low disinfectant
level and a booth that cannot read its thermometer. Delivered as a topbar badge, toast, and
a popup for critical alerts, with a full notification centre for filtering, marking read and
clearing.

### System Settings (administrator)
Farm name, system name, logo upload, timezone, temperature threshold, disinfection duration,
dashboard refresh interval, offline threshold and low-disinfectant warning level. Also shows
the booth API key (with regeneration), the endpoint URLs and registered booths.

**Booth Sequence** retunes the booth over the air — detection distance, presence
confirmation, spray burst and interval, door hold time and telemetry retention. The booth
adopts them on its next sync, and each registered booth shows whether it is running the
saved sequence yet or has not synced since the change.

### Audit Logs (administrator)
Every login, logout, user change, settings change, export and report generation is recorded
with user, module, activity, IP address and timestamp. Filterable, exportable to CSV and
purgeable by cut-off date.

---

## Temperature rules

The **booth** decides, because it is the only thing that can hold the door shut and it must
keep working with the network down. The server sets the threshold it decides against and
records what it did.

| Condition | Badge | Result |
|-----------|-------|--------|
| Temperature ≤ threshold (37.8 °C default) | Green | Access granted |
| Temperature > threshold | Red | **Access denied** + high-temperature notification |
| Temperature unreadable (sensor fault) | Red | **Access denied** + *Screening Failed* alert |
| Disinfection skipped | — | **Access denied** |

Changing the threshold in System Settings takes effect on the booth's next sync — no
reflashing — and until then the booth keeps screening against the value it last adopted.
It fails closed: a thermometer it cannot read denies entry rather than waving people
through. `api/entry.php`, the legacy endpoint, still decides server-side for older sketches
that report a reading and wait to be told.

---

## REST API for the ESP32

Every device endpoint authenticates with the `X-API-Key` header. The key lives in
**System Settings → Booth API Access**; the seeded default is
`DISINF-ESP32-2024-CHANGE-ME` — change it before deployment.

`booth_sync.php` is what the current firmware (1.1.0+) uses. `entry.php` and the
heartbeat remain for older sketches that post one reading at a time.

`ping.php` is the one exception to the API key: it answers `{"disinfentry":true}` to
anyone, because the booth has to find the server before it can authenticate to it. It
touches no database and discloses nothing the login page does not.

### `POST /api/booth_sync.php` — the sync the firmware speaks

One request carries everything the booth has queued: the heartbeat, the cycles it ran,
and the raw sensor streams. It is sent only while the booth is idle with nobody in
range, so a slow network can never stretch a spray burst.

```json
{
  "device_id": "ESP32-BOOTH-01", "device_name": "Main Gate Booth",
  "boot_id": "<uuid, new on every reset>", "firmware": "1.1.0",
  "uptime_ms": 600000, "state": "IDLE",

  "boot":   { "mlx_ok": true, "message": "Smart Disinfectant ready." },
  "config": { "trig_pin": 5, "pump_on_time_ms": 2000, "...": "the sketch's compiled constants" },

  "cycles": [{
    "cycle_ref": "<boot_id>:520000", "detected_uptime_ms": 520000, "duration_ms": 16500,
    "trigger_distance_cm": 41.2, "screening_temp_c": 36.6, "ambient_temp_c": 29.1,
    "threshold_c": 37.8, "outcome": "granted", "pump_bursts": 2, "pump_total_ms": 4000,
    "door_opened": true, "remarks": "Cleared for entry",
    "states": [{ "from_state": "IDLE", "to_state": "CONFIRMING", "uptime_ms": 520000 }],
    "pump":   [{ "burst_no": 1, "duration_ms": 2000 }],
    "door":   [{ "action": "open", "angle": 120, "uptime_ms": 531000 }]
  }],

  "distance":    [{ "distance_cm": 41.2, "in_range": true,  "uptime_ms": 519000 }],
  "temperature": [{ "object_temp_c": 36.6, "ambient_temp_c": 29.1, "is_screening": true, "uptime_ms": 525000 }]
}
```

`boot` and `config` appear only when they have something to say — after a reset, and
after a setting changes. A failed sensor read is sent as `null`, never as `0`.

**Everything is keyed, so a retry is a no-op rather than a duplicate.** The booth resends
its whole queue after a failed POST: cycles are keyed by `cycle_ref`, samples by
`(boot_id, uptime_ms)`.

**Response `200`:**

```json
{
  "success": true, "server_time": "2026-09-01 14:03:07",
  "stored": { "cycles": 4, "entries": 2, "distance": 3, "temperature": 3, "config": true },

  "fever_threshold_c": 37.8, "detection_distance_cm": 50,
  "presence_confirm_time_ms": 5000, "pump_on_time_ms": 2000,
  "pump_off_time_ms": 2000, "door_open_time_ms": 5000
}
```

The six flat values are the **directives**: the booth adopts them on the spot, so the
threshold and the spray sequence can be retuned from **System Settings → Booth Sequence**
without reflashing. The sketch refuses anything outside its own safety ranges, so the
Settings form enforces exactly those same bounds — a value it would ignore is rejected at
save time rather than silently leaving the booth on its old sequence.

#### What lands where

| The booth reports | Stored in | Published to `entries`? |
|---|---|---|
| `granted` / `denied` with a temperature | `booth_cycles` | Yes — one entry per cycle |
| `denied`, no temperature (MLX90614 fault) | `booth_cycles` | No — raises a *Screening Failed* alert |
| `aborted` (left before confirmation) | `booth_cycles` | No — nobody was screened |
| distance / temperature streams | `booth_*_samples` | No — pruned on a retention window |

Entries are the system of record the dashboard, live monitor and reports read. A cycle
that never produced a reading is kept for diagnostics but is never invented as an entry.
The booth has no reader, so entries it creates are named `Unidentified`.

Cycle and sample times arrive as `uptime_ms`, which is meaningful only against the
`uptime_ms` of the sync carrying it; the server converts each one to wall clock on
arrival.

### `POST /api/entry.php` — record an entry (legacy)

```json
{
  "device_id":          "ESP32-BOOTH-01",
  "employee_id":        "EMP-0003",
  "person_name":        "Juan Dela Cruz",
  "temperature":        36.7,
  "distance_cm":        42.5,
  "motion":             true,
  "disinfection":       "completed",
  "misting":            "on",
  "disinfectant_level": 62,
  "firmware":           "1.0.0"
}
```

Only `temperature` is required. Readings outside 20–50 °C are rejected as sensor faults (422).
`employee_id` is matched against the user directory to resolve the person's name.

**Response `201`:**

```json
{
  "success":      true,
  "entry_id":     982,
  "person_name":  "Juan Dela Cruz",
  "temperature":  36.7,
  "threshold":    37.8,
  "high_temp":    false,
  "access":       "granted",
  "allow_entry":  true,
  "mist_seconds": 8,
  "remarks":      "Cleared for entry",
  "timestamp":    "2026-08-06 11:51:09"
}
```

The booth drives its gate and indicators from `allow_entry`, and its misting cycle from
`mist_seconds`.

### `POST /api/device_status.php?action=heartbeat` — keep the booth online (legacy)

```json
{ "device_id": "ESP32-BOOTH-01", "firmware": "1.0.0", "disinfectant_level": 58 }
```

Returns the current threshold, misting duration and poll interval. Send it every ~30 s; a
booth that stops reporting for longer than the configured offline threshold raises a
*Booth Offline* notification.

### Quick test with PowerShell

Simulate a booth that boots, screens someone and is cleared — no hardware needed:

```powershell
$body = '{"device_id":"ESP32-BOOTH-01","device_name":"Main Gate Booth",
  "boot_id":"11111111-2222-4333-8444-555555555555","firmware":"1.1.0",
  "uptime_ms":75000,"state":"IDLE",
  "cycles":[{"cycle_ref":"11111111-2222-4333-8444-555555555555:50000",
    "detected_uptime_ms":50000,"duration_ms":16500,"trigger_distance_cm":38.4,
    "screening_temp_c":36.4,"ambient_temp_c":29.2,"threshold_c":37.8,
    "outcome":"granted","pump_bursts":2,"pump_total_ms":4000,"door_opened":true,
    "remarks":"Cleared for entry","states":[],"pump":[],"door":[]}]}'

Invoke-WebRequest -Uri "http://localhost/DisinfEntry/api/booth_sync.php" -Method POST `
  -Headers @{"X-API-Key"="DISINF-ESP32-2024-CHANGE-ME"; "Content-Type"="application/json"} `
  -Body $body
```

Send it twice: the second call returns `"cycles":0` rather than recording the person again.

---

## ESP32 firmware

`esp32/DisinfEntry_Booth/DisinfEntry_Booth.ino`

**Wiring**

| Component | GPIO |
|-----------|------|
| HC-SR04 TRIG / ECHO | 5 / 18 |
| MLX90614 SDA / SCL | 21 / 22 |
| Misting relay (active LOW) | 26 |
| Door servo | 25 |

> **ECHO needs a divider.** The HC-SR04 drives ECHO at 5 V and ESP32 GPIOs are 3.3 V only.
> Use a voltage divider (1 kΩ + 2 kΩ) or a level shifter, or the pin can be damaged.

**Libraries:** ESP32Servo, Adafruit MLX90614. WiFi and HTTPClient ship with the ESP32
board package; the sketch builds its JSON by hand and needs no JSON library.

Before flashing, copy `esp32/DisinfEntry_Booth/secrets.example.h` to `secrets.h` (same
folder) and set `WIFI_SSID`, `WIFI_PASSWORD`, `SERVER_HOSTNAME` (the Windows computer name
of the XAMPP machine — run `hostname` in cmd) and `API_KEY`. `secrets.h` is git-ignored, so
your WiFi password and API key never reach the repository.

The server's **IP address is not configured**: the booth finds it at run time, so a new
DHCP lease never stops a sync. It tries the address that worked last time (kept in flash
across reboots), then `SERVER_HOSTNAME` over mDNS, then a sweep of the local subnet —
adopting the first host that answers `api/ping.php` with the DisinfEntry signature. To
override the search entirely, set `SERVER_PIN_IP`.

Everything else — the fever threshold, detection distance, spray timings and door hold —
is adopted from the server on each sync, so retuning the booth does not mean reflashing
it. The sketch fails closed: if the MLX90614 cannot be read, entry is denied rather than
granted.

### Bringing a booth online

1. **Set `SERVER_HOSTNAME`** to the XAMPP machine's computer name — `hostname` in cmd.
   No IP address is needed; the booth resolves the name over mDNS, which Windows answers
   for itself with nothing to install, and falls back to sweeping the subnet.
2. **Put the booth and the server on the same network** — and note the ESP32 radio is
   **2.4 GHz only**, so it cannot join a 5 GHz SSID even when the server is on one. Use the
   router's 2.4 GHz SSID. Windows Firewall must allow inbound TCP 80 (a fresh XAMPP install
   usually prompts once) and UDP 5353 for mDNS.
3. **Match the API key** to **System Settings → Booth API Access**. A mismatch is a `401`,
   which the sketch logs to serial as a failed sync.
4. **Flash, then watch the serial monitor at 115200.** A healthy booth prints its boot id,
   `>> WiFi connected`, `>> Server found at <ip>`, then `>> Synced N cycle(s)` — and
   **System Settings → Registered Booths** shows it Online with its firmware version.

`!! Sync failed (HTTP -1)` means the TCP connection never opened — the booth is on Wi-Fi
but cannot reach the server. Check the two devices are on the same subnet (5 GHz vs 2.4 GHz
SSIDs on the same router usually are; guest networks are not), and that the router does not
have AP/client isolation enabled, which blocks device-to-device traffic entirely.

If syncs fail, the sketch keeps screening and keeps queueing: detection and the access
decision never depend on the network. It holds 5 cycles and resends them when the server
comes back, dropping the oldest first and reporting how many it lost.

---

## Project structure

```
DisinfEntry/
├── api/                        REST + AJAX endpoints
│   ├── booth_sync.php          ESP32 sync: cycles, telemetry, directives (API key)
│   ├── entry.php               Single-reading ingest, legacy (API key)
│   ├── ping.php                Discovery beacon the booth sweeps for (no auth, no DB)
│   ├── device_status.php       Heartbeat + booth status
│   ├── dashboard.php           KPI cards, charts, recent activity
│   ├── live.php                Live monitoring feed
│   ├── entries.php             Entry listing (DataTables server-side)
│   ├── filters.php             Shared filter/WHERE builder
│   ├── export.php              Entry log CSV / .xlsx export
│   ├── reports.php             Report data
│   ├── report_export.php       Report PDF / .xlsx / CSV
│   ├── notifications.php       Notification centre
│   ├── settings.php            System settings
│   └── audit.php               Audit log
├── assets/
│   ├── css/style.css
│   ├── js/                     app.js + one module per page
│   └── uploads/                Logo uploads (created on first upload)
├── config/
│   ├── config.php              Bootstrap, session, timezone, BASE_URL
│   ├── database.php            PDO connection (credentials live in database.local.php)
│   └── database.local.example.php   Template for the git-ignored database.local.php
├── database/
│   ├── disinfentry.sql         Schema + seed data
│   └── migrations/             Incremental SQL for an existing database
├── esp32/DisinfEntry_Booth/    Arduino firmware (+ secrets.example.h → secrets.h)
├── includes/
│   ├── auth.php                Sessions, guards, RBAC, password policy
│   ├── functions.php           Settings, escaping, CSRF, audit, notifications
│   ├── XlsxWriter.php          Minimal OOXML (.xlsx) writer — no Composer needed
│   ├── header.php / footer.php / 403.php
├── index.php                   Dashboard
├── live.php                    Live monitoring
├── entries.php                 Entry monitoring
├── reports.php                 Reports
├── notifications.php           Notification centre
├── settings.php                System settings (admin)
├── audit_logs.php              Audit logs (admin)
├── profile.php                 My profile / change password
├── login.php · logout.php · forgot_password.php · reset_password.php
└── .htaccess                   Blocks config/ and database/, adds security headers
```

---

## Database schema

| Table | Purpose |
|-------|---------|
| `users` | Accounts, roles, status, credentials |
| `entries` | Booth entry records — what the dashboard and reports read |
| `devices` | Registered booths: heartbeat, boot, state, disinfectant level (NULL = never reported) |
| `notifications` | Alerts raised by the system |
| `audit_logs` | Full activity trail |
| `settings` | Key/value configuration |
| `password_resets` | Single-use, expiring reset tokens |

Booth telemetry, written by `api/booth_sync.php`:

| Table | Purpose |
|-------|---------|
| `booth_cycles` | One detection-to-reset pass, keyed by `cycle_ref` for idempotent retries |
| `booth_cycle_states` | The state-machine transitions of a cycle |
| `booth_pump_bursts` | The spray bursts of a cycle |
| `booth_door_actions` | Servo open/close of a cycle |
| `booth_config` | The constants each booth reports it was flashed with |
| `booth_distance_samples` · `booth_temperature_samples` | Raw sensor streams, pruned on a retention window |

---

## Notes and known constraints

- **Notification sound** is synthesised with the Web Audio API rather than shipped as an
  audio file, so there is no binary asset to manage. Browsers require one click or keypress
  on the page before audio can play — this happens naturally during use.

- **Export PDF** relies on the browser's print engine (choose *Save as PDF*). This keeps the
  project free of Composer dependencies, which suits a XAMPP deployment. Swapping in
  Dompdf or TCPDF later would only require changing `api/report_export.php`.

- **Forgot password** never shows a reset link. With `MAIL_ENABLED` false (the default, since
  XAMPP has no mail transport) the request is raised to the administrators as a notification.

- **Excel export** produces a genuine `.xlsx` workbook, written by
  [`includes/XlsxWriter.php`](includes/XlsxWriter.php) — a small OOXML writer that uses
  `ext-zip` when it is loaded and a built-in zip writer when it is not, so there is no
  Composer dependency and no php.ini change needed. Temperatures are
  written as real numbers, header rows are frozen with auto-filter enabled, and
  above-threshold / denied cells are colour-coded.

- The application is built for a **trusted LAN deployment**. Put it behind HTTPS before
  exposing it beyond the farm network, and change the API key and default passwords first.

---

## Deployment checklist

1. **Database** — start MySQL, import `database/disinfentry.sql`, then each file in
   `database/migrations/` in order.
2. **Credentials** — copy `config/database.local.example.php` to `config/database.local.php`
   and fill in the MySQL user. Create a dedicated account limited to this schema (the example
   file has the SQL) rather than using `root`. There is no fallback account.
3. **Passwords** — sign in as each default account once; you are held on My Profile until the
   password is changed. The shipped defaults are rejected as new passwords.
4. **Booth API key** — System Settings → regenerate it, then put the new key in
   `esp32/DisinfEntry_Booth/secrets.h` and re-flash. Until you do, an admin banner
   says the key is still the public default. The key is accepted in the `X-API-Key` header only.
5. **Timezone** — set it in System Settings. The app pins MySQL's session timezone to match, so
   booth online/offline status and "today" are right whatever the database server is set to.
6. **Apache** — `AllowOverride All` for this folder (the `.htaccess` files block `config/`,
   `includes/`, `database/`, `esp32/` and source files, and set the security headers); `mod_rewrite`
   and `mod_headers` enabled. Verify: `/config/database.php` and `/database/disinfentry.sql`
   must return 403.
7. **HTTPS** — if the site is reachable beyond the farm network, serve it over HTTPS; the session
   cookie turns `Secure` and HSTS is sent automatically.
8. **Firmware** — put the WiFi credentials, server name and API key in `secrets.h`. That file is
   git-ignored; never commit it or share it.
9. **Backups** — schedule `mysqldump disinfentry` (entries and audit logs are the record of
   compliance) and keep `config/database.local.php` with it.

---

## Testing

Verified end to end against PHP 8.2.4 and MariaDB 10.4.28 — 80 automated checks covering
authentication, RBAC, all nine pages, every API endpoint, all report presets, every entry
filter, all five export formats, CSRF enforcement, user CRUD, last-administrator protection,
settings validation, API-key rotation and audit logging.
