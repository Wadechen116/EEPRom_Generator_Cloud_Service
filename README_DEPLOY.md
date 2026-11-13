# EEPROM Config Admin — Deployment Guide

This project has exactly one folder that gets deployed: **`www/`**. Everything under
`www/` is a straight drop-in for the remote Apache `www` folder — no Apache config
changes, no vhost edits, no `.htaccess`/`AllowOverride` dependency required for the
app to function (the `.htaccess` files included are opportunistic extra hardening
only; the app works correctly even if the server ignores them).

`sql/` and this README are **not** deployed — they're one-time setup / reference
material only.

**Note on size:** `www/assets/js/vendor/p5.min.js` (~1MB) is the p5.js library,
self-hosted (not loaded from a CDN) for the decorative fish animation in the Items
panel (`assets/js/fish.js`) — purely cosmetic, safe to delete both files plus the
`<div id="fishTank">`/`<script>` tags in `index.html` if you'd rather not ship it.

## API overview

Full request/response details, field reference, and curl examples for every
endpoint are in **[API_DOCUMENTATION.md](API_DOCUMENTATION.md)**. Summary:

| Endpoint | Method | Purpose | Auth |
|---|---|---|---|
| `public_key.php` | GET | Get the API key | none |
| `login.php` | POST | Log in, start a session | API key |
| `logout.php` | POST | Log out | API key |
| `session.php` | GET | Check login status | API key |
| `eeprom_config.php` | GET | List (paginated, `total`/`total_pages`) | API key + login |
| `eeprom_config.php?all=1` | GET | List everything, no pagination | API key + login |
| `eeprom_config.php?index=N` | GET | Get one record (full detail) | API key + login |
| `eeprom_config.php?index=N&download=1` | GET | Download `content` as a file | API key + login |
| `eeprom_config.php` | POST | Create a record | API key + login |
| `eeprom_config.php?index=N&_method=PUT` | POST | Update a record | API key + login |
| `eeprom_config.php?index=N&_method=DELETE` | POST | Delete a record | API key + login |
| `forgot_password.php` | POST | Email a one-time password-reset link | API key |
| `reset_password.php` | POST | Check a reset link / set the new password | API key (+ emailed token) |
| `collect.php` | POST | Telemetry: one application launch | none (rate-limited per IP) |
| `update.php` | GET | Update manifest for the desktop applications | none |
| `../monitor/index.php` | GET/POST | Monitor dashboard (HTML page, its own sign-in) | monitor sign-in (`monitor_admins`) |

"Login" means either a browser session (`login.php` + cookie) or per-request
`X-Account`/`X-Password` headers — see API_DOCUMENTATION.md for both. Every sign-in path has the same attempt
limit (HTTP 429 after 5 failures per account / 20 per IP in 15 minutes), and every API-key endpoint requires
HTTPS.

## 1. Database objects

The `eeprom_config` table is already created on the remote server (via phpMyAdmin).
`sql/schema.sql` documents the same structure for reference/rebuild elsewhere — it
also has commented-out `CREATE USER` / `GRANT` statements for creating an
app-specific DB user with only the privileges this app needs (not root); worth
doing if the API is currently pointed at a shared/admin account.

`sql/schema.sql` now also defines a `users` table for login accounts (`name` +
bcrypt `password_hash`, checked by `api/login.php`). Create it via phpMyAdmin the
same way you created `eeprom_config`.

### Creating / fixing a login account

Never type a real password directly into a SQL statement or paste it in chat.
Instead, run this **locally** (needs PHP on your own machine, not the server):

```bash
php tools/create_user.php <account-name> <a-strong-password>
```

It prints both an `INSERT` (new account) and an `UPDATE` (fix the `password_hash`
on a row that already exists) statement, containing only the **bcrypt hash** of the
password — paste whichever one you need into phpMyAdmin's SQL tab. `tools/` is
outside `www/`, so it's never part of the deployed package.

If a row was created by typing a password directly into phpMyAdmin's edit form, the
`password_hash` column almost certainly holds plaintext (or nothing) rather than a
real bcrypt hash (`$2y$...`, ~60 chars) — `password_verify()` will reject it. Use the
`UPDATE` statement above to fix it. (The plaintext `password` column the table once had from manual creation
was removed on 2026-11-13 with `sql/migrate_2026-11-13_security.sql`, part 2 — see below.)

### Forgot-password table and email addresses

The "Forgot password?" flow stores its one-time links in a new table, `password_resets`.
Run **`sql/migrate_2026-10-06_password_resets.sql`** once in phpMyAdmin's SQL tab (safe to
re-run). It holds only the SHA-256 of each emailed token, never the token itself.

A user can only reset a password if their `users.email` is filled in — the page can't
tell anyone whether an account exists, so an account without an email simply never gets a
mail. Set it with the same tool as above (`php tools/create_user.php <name> <password> <email>`
prints an `UPDATE ... SET password_hash = ..., email = ...`), or directly in phpMyAdmin:
`UPDATE users SET email = 'someone@soinc.com.tw' WHERE name = 'someone';`

### Telemetry and monitor tables

`api/collect.php` writes one row per application launch into **`telemetry_logs`** and caches IP information in
**`ip_intel_cache`**; `monitor/index.php` reads both.

- `sql/migrate_2026-11-12_telemetry.sql` — both tables, as they were created by hand on the server (copied from
  `SHOW CREATE TABLE`). On this deployment they already exist; the file is the record, and what to run on a new
  server. `telemetry_logs.created_at` is indexed (`idx_created_at`): every time range on the monitor relies on it.
- `sql/migrate_2026-11-13_ip_geo.sql` — adds `country_code`, `continent`, `continent_code`, `lat`, `lon` to
  `ip_intel_cache` for the map and the country / continent statistics (run 2026-11-13). Rows cached before it
  fill in by themselves the next time that IP reports; `DELETE FROM ip_intel_cache;` (it is only a cache) refills
  everything at once.

### Sign-in attempt limit and the old plaintext column

`sql/migrate_2026-11-13_security.sql` (run 2026-11-13):

- **Part 1** creates **`login_attempts`** (one row per failed sign-in, purged after a day by the code) for the
  attempt limit in `api/lib/LoginThrottle.php`. If the table is missing, sign-in still works, without a limit,
  and the PHP error log shows `[throttle]` lines.
- **Part 2** removed the plaintext `users.password` column (check, wipe, drop — one step at a time). Nothing in
  this project ever read it.

## 2. Fill in real config on the server (never in chat, never in source control)

1. Copy `www/api/config/config.sample.php` to `www/api/config/config.php`.
2. Edit `config.php` and fill in the real `db.host` / `db.name` / `db.user` / `db.pass`.
3. Generate a real API key and put it in `api_key`:
   ```
   php -r "echo bin2hex(random_bytes(32));"
   ```

That's it for the key — `assets/js/app.js` fetches it at page load from
`api/public_key.php` (which reads it from `config.php`), so there is nothing to keep
in sync by hand anymore. See the "Design note" section below for why that endpoint is
intentionally unauthenticated and why that's an acceptable trade-off.

### Monitor, telemetry, map and security settings

All optional — every key has a default; `config.sample.php` explains each one. Example (values as on this
deployment, except `monitor_admins`, which is up to you):

```php
    'monitor_admins' => ['alice', 'bob'],  // who may sign in to monitor/index.php; [] = every account

    'telemetry' => [
        'latest_version'       => '1.0.0', // returned to the applications by collect.php
        'max_per_minute'       => 30,      // launches accepted per client IP per minute
        'geo_lookup'           => true,    // ip-api.com lookup (free tier: non-commercial, http only)
        'geo_refresh_days'     => 90,
        'trusted_proxies'      => [],      // proxies whose X-Forwarded-For may be believed
        'license_device_limit' => 1,       // devices per license key before it counts as 授權碼超用
        'org_device_alert'     => 5,       // devices per organisation flagged as 多機集中
        'crack_markers'        => [],      // license_key values the app sends for a cracked copy
    ],

    'geoapify' => [                        // map tiles on the monitor (Leaflet)
        'api_key'     => '...',            // restrict it to this site's URL in the Geoapify project
        'style_light' => 'osm-bright-grey',
        'style_dark'  => 'dark-matter-brown',
    ],

    'security' => [
        'force_https'     => true,         // on since 2026-11-13 (monitor footer showed 伺服器偵測 HTTPS：是)
        'hsts_max_age'    => 0,            // host-wide effect: start at 300, then 31536000
        'trusted_proxies' => [],
    ],

    'login_throttle' => ['window_minutes' => 15, 'max_per_account' => 5, 'max_per_ip' => 20],
```

- **`monitor_admins`**: fill it in. With an empty list, every account that can sign in to EEPROM Config can also
  read customers' computer names, intranet IPs and hardware serials on the monitor.
- **Geoapify key**: it travels to the browser with every map tile, so it cannot be secret — in the Geoapify
  project settings allow only the referrer `https://pend.soinc.com.tw`. Without a key the map card shows setup
  text; everything else on the monitor works.
- **`force_https`**: turn on only when the monitor page footer says 「伺服器偵測 HTTPS：是」. If PHP cannot see
  HTTPS (TLS ended by a proxy in front of Apache), turning it on makes pages redirect in a loop and APIs answer
  403 — then list the proxy in `security.trusted_proxies` first.
- **`hsts_max_age`** > 0 makes browsers refuse plain http for the **whole host** `pend.soinc.com.tw` (every site on
  it, not only `/chamonix`) for that long, and it cannot be taken back early. Check nothing else on the host is
  http-only; start with 300.

**Do this step directly on/for the server, or in a local copy you never commit.**
`config.php` is intentionally left out of this package (only the `.sample.php`
template is here) so there is nothing containing real credentials to accidentally
ship, zip, or paste anywhere.

### Forgot-password email (`app_url` + `mail` in `config.php`)

An existing `config.php` keeps working as-is: without the two new blocks, "Forgot
password?" requests are accepted and quietly ignored (nothing stored, nothing sent) — see
`config.sample.php` for the blocks to add:

| Key | What to put |
|---|---|
| `app_url` | The address users type in the browser, with any sub-path, no trailing slash (`https://pend.soinc.com.tw/chamonix`). The reset link in the email is built from it. It is configured rather than read from the request on purpose: a request's `Host` header is chosen by whoever sends it. |
| `mail.enabled` | `false` until the mail relay accepts mail from this server, then `true`. |
| `mail.host` / `port` / `encryption` | The corporate relay (`ms.soinc.com.tw`, port 25 for an IP-allow-listed relay; `'tls'` for STARTTLS on 587 or `'ssl'` for 465 — both need PHP's `openssl` extension). |
| `mail.username` / `password` | Leave both empty for an allow-listed relay; fill both for an authenticated service account. **Filled in on the server by whoever manages the mail server** — they never pass through the app developer or the repo. |
| `mail.from` / `from_name` | Sender address. The relay may only accept a sender in its own domain. |
| `mail.timeout` / `reset_ttl_minutes` | Seconds to wait on the relay (default 10) / how long a link works (default 30, allowed 5–1440). |

The mail library is PHPMailer 6.10.0, vendored under `api/lib/phpmailer/` (plain PHP, no
composer, nothing to install on the server — see `VENDORED.txt` there for source,
licence and checksums). It is part of the `www/` package like everything else.

**Order to bring it live** (each step is safe on its own; nothing sends mail until the last):

1. Run `sql/migrate_2026-10-06_password_resets.sql` (phpMyAdmin).
2. Upload `www/` (new/changed: `index.html`, `assets/css/style.css`, `assets/js/app.js`,
   `api/forgot_password.php`, `api/reset_password.php`, `api/lib/Mailer.php`,
   `api/lib/Response.php`, `api/lib/phpmailer/`).
3. Mail admin: allow this server's IP (`192.168.20.20`) on the relay — or issue a service
   account — and fill the `mail` block in the server's `config.php`; set `app_url`.
4. Set `mail.enabled` to `true`. Request a reset for an account that has an email, and
   open the link from the mail.
5. If no mail arrives, the reason is in the server's PHP error log, one line per failure
   starting `[Mailer]`, including the relay's own reply (e.g.
   `[relay replied: 554 5.7.1 ... Client host rejected: Access denied]` = the server is
   still not allow-listed). `[reset] request ignored: ...` lines say why a request was
   dropped (mail disabled, no such account/email, throttled).
   `[reset] link rejected (token hash 1a2b3c4d...): ...` says why a reset link was refused while the page only shows
   "invalid or has expired": not 64 hex characters (cut off, or something appended by a mail program or link scanner),
   not in `password_resets`, already used or replaced by a newer request, or expired. The first 8 hex digits are the start of
   `password_resets.token_hash`, to find the row; the token itself is never logged.

## 3. Package and deploy

Pack the **contents** of `www/` (not the `www` folder itself) and upload/extract
into the remote Apache `www` folder. The repo's package is `deploy.tar.gz` (it is in
`.gitignore`: a build artifact, regenerate it every time rather than reusing an old one):

```powershell
# from D:\swork\WebPage  (tar.exe ships with Windows 10 and later)
tar -czf deploy.tar.gz -C www .
```

Extract it directly into the remote `www` folder (`tar -xzf deploy.tar.gz`). If the
upload tool you use only takes zip files, this does the same job:

```powershell
Compress-Archive -Path www\* -DestinationPath deploy.zip -Force
```

Either way the final layout on the server should be `www/index.html`, `www/api/...`,
`www/assets/...` etc. at the web root (or whatever sub-path the remote www folder is
already served from). Neither package should contain `api/config/config.php` (it is
the server's own file; the repo only has `config.sample.php`) -- if you pack from a
checkout that has a local `config.php`, delete it from the package before uploading.

## 4. Verify

```bash
# 1. Fetch the API key (this endpoint is intentionally unauthenticated -- see below)
curl https://<server>/api/public_key.php
# -> {"success":true,"data":{"apiKey":"..."}}

# 2. Log in, saving the session cookie
curl -c cookies.txt -H "X-API-Key: <key from step 1>" -H "Content-Type: application/json" \
  -d '{"account":"<account>","password":"<password>"}' \
  https://<server>/api/login.php

# 3. Fetch data using that same session
curl -b cookies.txt -H "X-API-Key: <key from step 1>" https://<server>/api/eeprom_config.php
```

Should return `{"success":true,"data":{"items":[],...}}` (or existing rows) once
step 2 (the DB/config setup) is done. Then open `index.html` in a browser, log in,
and try adding/editing/deleting a record.

### Verify telemetry and the monitor

```powershell
# 1. One fake launch (PowerShell 5.1 -- its "curl" is Invoke-WebRequest, so not curl syntax)
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$body = @{ machine_id = 'TEST1'; hostname = 'PC1'; app_version = '1.0.0' } | ConvertTo-Json
Invoke-RestMethod -Method Post -Uri 'https://pend.soinc.com.tw/chamonix/api/collect.php' `
    -ContentType 'application/json; charset=utf-8' -Body $body
# -> status ok, latest_version 1.0.0
```

2. Open `https://pend.soinc.com.tw/chamonix/monitor/index.php`, sign in, choose 「最近 5 分鐘」: `PC1` is in
   最新事件. The footer shows the page version, 「伺服器偵測 HTTPS」 and 「強制 HTTPS」.
3. `SELECT public_ip, country, lat, lon FROM ip_intel_cache;` — the test IP has coordinates (unless it is a
   private intranet IP: those are never looked up, and a test from inside the company network may be one).
4. Remove the test row: `DELETE FROM telemetry_logs WHERE machine_id = 'TEST1';`
5. Security headers: `curl.exe -sI https://pend.soinc.com.tw/chamonix/index.html` shows
   `Content-Security-Policy`, `X-Frame-Options`, `X-Content-Type-Options` (from the root `.htaccess`).

### Calling the API from another frontend/script (no session/cookie needed)

`api/eeprom_config.php` accepts per-request credentials as an alternative to the
browser's session login, two ways (`api/lib/CredentialAuth.php` tries both):

```bash
# 1. Standard HTTP Basic Auth (works IF your host passes the Authorization header to PHP)
curl -u "<account>:<password>" -H "X-API-Key: <key>" https://<server>/api/eeprom_config.php

# 2. Plain X-Account / X-Password headers (use this if #1 doesn't work -- see below)
curl -H "X-Account: <account>" -H "X-Password: <password>" \
     -H "X-API-Key: <key>" https://<server>/api/eeprom_config.php
```

**On this deployment (pend.soinc.com.tw/chamonix), option 1 does NOT work** — this
host's CGI/FastCGI setup strips the `Authorization` header before PHP ever sees it
(confirmed via a diagnostic check; no server-config access exists to fix that at the
source). **Use option 2 here.** Full read/write (GET/POST/update/delete, same rules
as the web UI) works either way — see the method-override note above for how
update/delete are called.

**This requires HTTPS regardless of which option is used.** Both send the real
password on every single request (Basic Auth base64-*encodes* it, which is not
encryption — trivially reversible); over plain HTTP either is as exposed as sending
the password in cleartext. Confirm the site is served over HTTPS before using this
from anything real.

## Monitor dashboard (`monitor/index.php`)

A Kibana-style dashboard over the telemetry the desktop applications send to `api/collect.php`.
Files: `monitor/index.php` (page), `monitor/geo.php` (map / country / continent rules), `monitor/demo_data.php`
(demo mode), `monitor/echarts.min.js` (ECharts 6.1.0, self-hosted). Leaflet 1.9.4 comes from unpkg with an SRI
hash; map tiles from Geoapify.

**Sign-in.** Its own sign-in form and login state, separate from the EEPROM Config page (same accounts). Only
`monitor_admins` may sign in. Same 5-per-account / 20-per-IP attempt limit as the main page.

**What it shows** (every part follows the filter row: time range, version, organisation):
- Stat tiles: launches, active devices, organisations, license keys in use (each with the change against the
  previous period of the same length), over-used license keys, suspected VM-clone organisations.
- 即時動態: launches per second over the last 60 s, launches in the last 1 / 5 minutes, devices active in the
  last 5 minutes — always "now", only the version filter applies.
- Launches and active devices over time (10 s / 1 min / 1 h / 1 day buckets depending on the range),
  weekday × hour heat map, top organisations (click = drill-down), countries, versions, OS, architecture.
- Map of IP locations (dot size = devices, red = contains 疑似盜用 devices; click a dot for details), 五大洲
  stacked bars (亞洲 / 歐洲 / 美洲 = North + South America / 非洲 / 大洋洲) and a per-country table.
- License-key ranking (超用 / 跨組織 flags), organisation leaderboard (疑似 VM 克隆 / 多機集中), last 50 launches,
  and the drill-down table of one organisation's computers.

**How things are judged** (`monitor/geo.php`, same rules for tiles, map and tables):
- 授權碼超用: a key used on more devices than `telemetry.license_device_limit`.
- 疑似 VM 克隆: an organisation with more distinct motherboard UUIDs than disk serials (one disk image copied).
- 疑似盜用 device: on an over-used key, in a VM-clone organisation, or reporting a `telemetry.crack_markers` value.
- Organisation = AD domain, else the IP's ISP organisation, else the public IP.
- Locations are IP geolocation: city level at best, often just the ISP's site.

**Live refresh** (即時更新: off / every 1, 5, 10, 30, 60 s; remembered per browser). The page re-requests its own
URL in the background and swaps in numbers, charts, tables and map dots — table scroll positions, chart zoom and
the map view stay. One request at a time, paused while the tab is hidden, stopped (with a message) when the
sign-in has expired. Each refresh runs all of the page's queries: fine on today's data volume; on real data with
long ranges prefer 5–10 s, and keep "every second" for the short ranges.

**Demo mode** (`?demo=1`, or 看示範資料 in the filter row). Everything is generated by `monitor/demo_data.php` —
the database is not queried, and a striped banner says so. A fake world of ~150 organisations / ~2,100 machines in
~85 cities, with launches stored as daily totals (90 days), hourly totals (last ~2 days) and per-second events
(last 1–2 hours), all from one rate profile and seeded by their own timestamps (the past never changes). During
the day a new machine is "installed" every 3 minutes — sometimes a new customer (new map dot), a leaked key, a
copied disk or the `CRACKED-DEMO` marker — so even 30-day numbers move. Opens on 「最近 15 分鐘」 with refresh every
second. About 0.2 s per request. To keep it off the server, do not upload `demo_data.php` (`?demo=1` then fails
with a server error).

**Map card messages**: 「尚未設定 Geoapify 金鑰」 = no `geoapify.api_key`; 「地理資料欄位尚未建立」 = run
`sql/migrate_2026-11-13_ip_geo.sql`; 「此時間範圍內沒有可定位的裝置」 = no located devices in the range (private IPs
are never located); 「地圖圖磚載入失敗」 = Geoapify refused the tiles (key, allowed URL, or daily quota).

**Base map** (底圖 menu, remembered per browser): follow the theme, or a fixed Geoapify style.

**Troubleshooting**: a 「伺服器錯誤」 page logs one line with the prefix `[monitor]` in the PHP error log;
`collect.php` logs `[collect]`, the attempt limit `[throttle]`. The footer's version (`MONITOR_VERSION`) tells which
upload is live.

## Design note: the API key is fetched at runtime, not hardcoded in app.js

`assets/js/app.js` no longer hardcodes `API_KEY` -- it fetches it from
`api/public_key.php` on page load. That endpoint deliberately skips the API-key check
every other endpoint requires (it has to be reachable before the frontend has a key to
send at all). This is a conscious trade-off: the key was never a secret from a page
*viewer* anyway (it always shipped inside `app.js`, visible via view-source/devtools);
making it fetchable here only changes who can obtain it without loading the page at
all (e.g. a bot), which is a minor regression against the key's original job (stopping
naive direct hits) in exchange for a real, previously-recurring bug: regenerating
`app.js` to add a feature used to silently reset a manually-synced key back to its
placeholder, breaking login until someone noticed. Now `config.php` is the only place
the key lives.

## Design note: listing is paginated by default (`?all=1` to get everything)

`GET eeprom_config.php` defaults to the first 20 rows (`?page=`/`?limit=`, capped at
100) to avoid an unbounded response on a large table. Pass `?all=1` to bypass
pagination and get every row in one response — the frontend uses this since it has
no pagination UI; fine for a table this size, but if it grows very large, prefer
paging through with `?page=`/`?limit=` instead.

## Design note: update/delete travel as POST, not PUT/DELETE

`api/eeprom_config.php` and the frontend both use a `_method` override
(`POST .../eeprom_config.php?index=5&_method=PUT`) instead of real HTTP `PUT`/`DELETE`
requests. Some shared-hosting Apache setups (WebDAV modules, WAF/security rules,
certain PHP-CGI configs) silently block or mangle those verbs before they ever reach
PHP, and we have no access to server config to fix that at the source — plain `POST`
always gets through. If a future endpoint needs the same pattern, follow the same
convention (`?_method=PUT` / `?_method=DELETE` on a POST request).

## Security notes (why this is safe to leave under a public www folder)

- **DB credentials** live only in `api/config/config.php`, a plain PHP file. Since
  Apache already executes `.php` through PHP (existing server config, untouched by
  us), a direct browser request to that URL runs the file and returns an **empty**
  response — the array is `return`ed, never `echo`ed, so nothing leaks even without
  any `.htaccess`/deny rule working.
- DB credentials are **never** sent to the browser, logged, or included in any API
  response — errors are caught and only a generic message ("Internal server error" /
  "Database unavailable") goes to the client; full details go to the server's
  existing PHP error log via `error_log()`.
- All SQL uses PDO **prepared statements** with bound parameters — no string-built
  queries, so no SQL injection surface.
- Write endpoints (`POST`/`PUT`/`DELETE`) require the `X-API-Key` header, checked
  with `hash_equals()` (constant-time compare).
- **The API key is not equivalent to the DB password.** It ships inside
  `assets/js/app.js`, so anyone who loads the page can read it in devtools. Its job
  is only to stop random bots/scanners from hitting the API directly without ever
  loading the page — it is *not* a barrier against a real user of the page itself.
- **Real access control is the login in `api/login.php`.** `api/eeprom_config.php`
  requires both the API key *and* a logged-in PHP session (`SessionAuth::requireLogin()`).
  Passwords are verified with `password_verify()` against a bcrypt hash — plaintext
  passwords are never stored, logged, or sent back to the client. The session cookie
  is `HttpOnly` (unreadable from JS) and `SameSite=Lax` (mitigates basic CSRF); it is
  also marked `Secure` automatically when the request is HTTPS.
- Login responses use the same generic "Invalid account or password" message whether
  the account doesn't exist or the password is wrong, so the API can't be used to
  enumerate valid account names.
- `config.php`/`config.sample.php` are guarded with `defined('APP_ENTRY') or die`,
  and the `api/config/` and `api/lib/` folders each ship a blank `index.html` (works
  with zero server config) plus an opportunistic `Require all denied` `.htaccess`.
- **Sign-in attempt limit** (`api/lib/LoginThrottle.php`): 5 failures per account name or 20 per IP in
  15 minutes lock further attempts — correct password included — on `login.php`, the X-Account/X-Password
  header login and the monitor page. Locks lift by themselves; nothing is stored about successful sign-ins.
  Someone who knows an account name can keep it locked by failing on purpose (accepted trade-off).
- **HTTPS is enforced** (`security.force_https`): pages over http are redirected (to the host in `app_url`, never
  to the request's Host header), API calls over http are refused with 403. `app.js` also moves a page opened by
  host name to https. `collect.php` / `update.php` are exempt for the desktop applications.
- **Security headers** on every PHP response (`api/lib/SecurityHeaders.php`): `X-Content-Type-Options: nosniff`,
  `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy` and a
  `Content-Security-Policy` (API: `default-src 'none'`; monitor: only this site, unpkg for Leaflet, Geoapify
  tiles, inline scripts only with the per-response nonce). Static files get the same from the root `.htaccess`
  (`IfModule mod_headers`; delete that block if the server ever answers 500 because of it), and `index.html`
  repeats its CSP as a `<meta>` tag. HSTS is sent only when `security.hsts_max_age` > 0.
- **Telemetry is untrusted input.** `collect.php` is open to everyone: values are length-limited, stored with
  prepared statements, HTML-escaped on output and embedded as escaped JSON, and limited per IP — but their
  content is whatever a client claims. The monitor's flags are leads, not evidence.
- **Personal data.** Telemetry holds computer names, AD domains, intranet IPs and hardware serials of
  customers' machines: disclose it in the license terms / privacy notice and decide how long to keep it
  (e.g. delete `telemetry_logs` rows older than a year).
