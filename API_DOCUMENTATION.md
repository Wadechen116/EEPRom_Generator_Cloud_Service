# API Documentation — EEPROM Generator cloud service

Base URL (this deployment): `https://pend.soinc.com.tw/chamonix/api/`

All responses are JSON with the same envelope:

```json
// success
{ "success": true, "data": { ... } }

// failure
{ "success": false, "error": "Human-readable message" }
```

---

## Authentication

Every endpoint except `public_key.php`, `update.php` and `collect.php` requires the API key.
The data endpoint (`eeprom_config.php`) additionally requires a login — **two
independent layers**, both must pass. `forgot_password.php` and `reset_password.php`
need the API key only (there is no logged-in user in a password reset; the emailed
one-time token is the credential).

**HTTPS.** With `security.force_https` on in `config.php` (it is, on this deployment), every endpoint that
uses the API key refuses plain-http calls with HTTP 403 `{"success":false,"error":"HTTPS required"}`.
`collect.php` and `update.php` are exempt, so installed desktop applications keep working whatever URL they use.

### 1. API Key (all endpoints)

Header: `X-API-Key: <key>`

Get the current key:

```bash
curl https://pend.soinc.com.tw/chamonix/api/public_key.php
```

```json
{ "success": true, "data": { "apiKey": "..." } }
```

This key is not a secret from anyone who loads the web page (it ships in
`assets/js/app.js`) — its only job is to block naive/automated direct hits to the API.
It is unrelated to login credentials.

### 2. Login (only `eeprom_config.php`)

One of the following, in addition to the API key:

**a) Browser session** — call `login.php` once, the browser cookie covers every
request after that.

**b) Per-request credentials** — send account/password on every call, no cookie
needed. Two header styles are supported (`api/lib/CredentialAuth.php` tries both):

```bash
# Standard HTTP Basic Auth (works only if the host forwards the Authorization header to PHP)
curl -u "<account>:<password>" ...

# Plain custom headers (use this if Basic Auth doesn't work on your host --
# confirmed necessary on pend.soinc.com.tw/chamonix)
curl -H "X-Account: <account>" -H "X-Password: <password>" ...
```

**Requires HTTPS.** Both send the real password on every request (Basic Auth
base64-*encodes* it, which is not encryption).

### Sign-in attempt limit (all login paths)

`login.php`, the per-request headers above and the monitor page's sign-in share one limit
(`api/lib/LoginThrottle.php`, table `login_attempts`). Within the last 15 minutes:

- 5 failures for one **account name** (from any IP), or
- 20 failures from one **IP** (for any account names)

lock further attempts for that account / IP — **even with the correct password** — until the oldest of those
failures is 15 minutes old. A locked request gets HTTP 429 with a `Retry-After` header (seconds):

```json
{ "success": false, "error": "Too many failed sign-in attempts. Try again in 15 minute(s)." }
```

A successful sign-in clears that account's failures. Account names that do not exist are counted and locked
exactly like real ones, so the limit reveals nothing about which accounts exist. The numbers are set in
`config.php` (`login_throttle`). A tool that signs in on every request (headers) should stop and wait on 429
instead of retrying.

---

## Endpoints

### `GET public_key.php`

No authentication. Returns the API key.

```bash
curl https://pend.soinc.com.tw/chamonix/api/public_key.php
```

```json
{ "success": true, "data": { "apiKey": "9f8a2c1e..." } }
```

---

### `POST login.php`

Body: `{ "account": "...", "password": "..." }`

On success, starts a session (browser use), stamps `users.last_login_at` with the
current time, and always returns the account name — useful for non-browser callers
too, as a "did these credentials work" check.

```bash
curl -c cookies.txt -H "X-API-Key: <key>" -H "Content-Type: application/json" \
  -d '{"account":"alice","password":"hunter2"}' \
  https://pend.soinc.com.tw/chamonix/api/login.php
```

```json
{ "success": true, "data": { "account": "alice" } }
```

Failure (wrong account or password — same message either way, so the API can't be
used to enumerate valid accounts):

```json
{ "success": false, "error": "Invalid account or password" }
```
→ HTTP 401

Too many recent failures for this account or IP (see "Sign-in attempt limit") → HTTP 429 with `Retry-After`.

---

### `POST logout.php`

No body. Clears the session and stamps `users.last_logout_at` with the current time.

```bash
curl -b cookies.txt -H "X-API-Key: <key>" -X POST https://pend.soinc.com.tw/chamonix/api/logout.php
```

```json
{ "success": true, "data": { "loggedOut": true } }
```

---

### `GET session.php`

Checks whether the current session (cookie) is logged in. Used by the frontend on
page load; doesn't fail with a 401 either way.

```bash
curl -b cookies.txt -H "X-API-Key: <key>" https://pend.soinc.com.tw/chamonix/api/session.php
```

```json
{ "success": true, "data": { "loggedIn": true, "account": "alice" } }
```

---

### `POST forgot_password.php`

Starts a password reset: emails the user a one-time link. API key only.

Body: `{ "identifier": "<account name or email address>" }`

```bash
curl -H "X-API-Key: <key>" -H "Content-Type: application/json" \
  -d '{"identifier":"alice"}' \
  https://pend.soinc.com.tw/chamonix/api/forgot_password.php
```

```json
{ "success": true, "data": { "requested": true, "message": "If that account exists and has an email address on file, a reset link has been sent." } }
```

**The answer is the same 200 for every well-formed request** — account unknown, account
without an email address, throttled, mail switched off, mail relay refused: all look
identical, in body *and* in response time (the reply is sent first; the mail is sent after
the connection closes). The API therefore can't be used to find out which account names
exist, the same rule `login.php` follows. The only errors are `400` (empty or over-long
`identifier`, bad JSON), `401` (API key) and `405`.

What happens after the reply, silently, in this order:

1. Mail not enabled in `config.php` → nothing. (Nothing is stored either.)
2. No account matches the name/email, or it has no valid email address → nothing.
3. Throttled → nothing: at most one request per account per 60 s, 5 per account per
   hour, 20 per client IP per hour.
4. A random 32-byte token is created; **only its SHA-256 is stored**
   (`password_resets.token_hash`). Older unused links of that account are retired — only
   the newest link works.
5. The mail is sent through the corporate relay (PHPMailer). If that fails the stored row
   is deleted again and the reason — including the relay's own status line, e.g.
   `554 5.7.1 ... Client host rejected: Access denied` — is written to the server's PHP
   error log.

The link in the mail is `<app_url>/index.html#reset=<64 hex chars>` (the page is named, not left to the directory address, so the web server's `DirectoryIndex` order cannot send it to another index file; if `app_url` already ends in `.html`/`.htm`/`.php` it is used as is). The mail is sent as `multipart/alternative`: an HTML part where the address sits in an `href` behind the label "Choose a new password", and a plain-text part with the address on a line of its own. The address is over 100 characters, wider than most mail programs show a plain-text line in, which folded it into two pieces with only the first one linked. The token is in the URL
**fragment**, which browsers never send to a server, so it stays out of Apache access
logs and `Referer` headers. `app_url` comes from `config.php`, never from the request.
A link works once and expires after `mail.reset_ttl_minutes` (default 30).

---

### `POST reset_password.php`

Second half of the reset. API key only; the token from the emailed link is the credential.

**Check** — is this link still usable? (the page does this before showing the form)

```bash
curl -H "X-API-Key: <key>" -H "Content-Type: application/json" \
  -d '{"token":"<64 hex chars>"}' \
  https://pend.soinc.com.tw/chamonix/api/reset_password.php
```
```json
{ "success": true, "data": { "valid": true } }
```

**Reset** — set the new password:

```bash
curl -H "X-API-Key: <key>" -H "Content-Type: application/json" \
  -d '{"token":"<64 hex chars>","password":"<new password>"}' \
  https://pend.soinc.com.tw/chamonix/api/reset_password.php
```
```json
{ "success": true, "data": { "reset": true } }
```

It does not log the user in; they sign in with the new password.

| Case | Answer |
|---|---|
| Token malformed, unknown, expired, already used, superseded by a newer link, or its account was deleted | `400` `"This reset link is invalid or has expired. Request a new one."` — one message for all of them, so the endpoint says nothing about *why* |
| Password shorter than 8 characters | `400` |
| Password longer than 72 bytes | `400` (bcrypt ignores everything past 72 bytes; refused rather than silently truncated) |
| Password only whitespace / not a string | `400` |

Only `users.password_hash` is written — never the legacy plaintext `password` column. A
successful reset also retires every other unused link of that account. The token is
claimed with one atomic `UPDATE … WHERE used_at IS NULL`, so submitting the same link
twice at the same instant produces exactly one success.

**Not covered:** a login session that is already open (in any browser) is not ended by a
reset — PHP sessions can't be listed or revoked per user. The web page ends *its own* session
after a reset; other sessions lapse when their browser closes.

---

### `eeprom_config.php` — the `eeprom_config` table

Requires API key **and** login (session or per-request credentials, see above).

#### `GET eeprom_config.php` — list (paginated)

Query params: `page` (default 1), `limit` (default 20, max 100).
Returns summary columns plus a `content_preview` (first 200 characters of
`content`) — not the full `content`, and not `ext_*` — see detail view below.

```bash
curl -H "X-Account: alice" -H "X-Password: hunter2" -H "X-API-Key: <key>" \
  "https://pend.soinc.com.tw/chamonix/api/eeprom_config.php?page=1&limit=20"
```

```json
{
  "success": true,
  "data": {
    "items": [
      {
        "index": 1,
        "file_format": "INI",
        "fps": "25",
        "file_name": "sensor_config_25fps_v1.ini",
        "MHz": "24",
        "isPGL": 1,
        "output_format": "AHD",
        "support_mode": "Master",
        "create_time": "2026-09-17 11:29:25",
        "modify_time": "2026-09-18 13:22:10",
        "account": "s008",
        "comment": "production build",
        "content_preview": "[Sensor]\r\nModel=IMX178\r\nGain=100\r\n..."
      }
    ],
    "page": 1,
    "limit": 20,
    "total": 6,
    "total_pages": 1
  }
}
```

#### `GET eeprom_config.php?all=1` — list (everything, no pagination)

Same columns as the paginated list above (summary + `content_preview`); no
`page`/`limit`/`total_pages` in the response, just `items` and `total`.

```bash
curl -H "X-Account: alice" -H "X-Password: hunter2" -H "X-API-Key: <key>" \
  "https://pend.soinc.com.tw/chamonix/api/eeprom_config.php?all=1"
```

```json
{
  "success": true,
  "data": {
    "items": [
      {
        "index": 1,
        "file_format": "INI",
        "fps": "25",
        "file_name": "sensor_config_25fps_v1.ini",
        "MHz": "24",
        "isPGL": 1,
        "output_format": "AHD",
        "support_mode": "Master",
        "create_time": "2026-09-17 11:29:25",
        "modify_time": "2026-09-18 13:22:10",
        "account": "s008",
        "comment": "production build",
        "content_preview": "[Sensor]\r\nModel=IMX178\r\nGain=100\r\n..."
      }
    ],
    "total": 15
  }
}
```

#### `GET eeprom_config.php?index=N` — single record (full detail)

Everything from the list view (including `account`/`comment`, but this time
without `content_preview`) plus the full `content` and all `ext_*` columns.

```bash
curl -H "X-Account: alice" -H "X-Password: hunter2" -H "X-API-Key: <key>" \
  "https://pend.soinc.com.tw/chamonix/api/eeprom_config.php?index=1"
```

```json
{
  "success": true,
  "data": {
    "index": 1,
    "file_format": "INI",
    "fps": "25",
    "file_name": "sensor_config_25fps_v1.ini",
    "MHz": "24",
    "isPGL": 1,
    "output_format": "AHD",
    "support_mode": "Master",
    "create_time": "2026-09-17 11:29:25",
    "modify_time": "2026-09-18 13:22:10",
    "account": "s008",
    "comment": "production build",
    "content": "[Sensor]\r\nModel=IMX178\r\nGain=100",
    "ext_str1": null,
    "ext_str2": null,
    "ext_int1": null,
    "ext_txt1": null,
    "ext_txt2": null,
    "ext_txt3": null,
    "ext_txt4": null,
    "ext_txt5": null
  }
}
```

Not found → `{ "success": false, "error": "Record not found" }`, HTTP 404.

#### `GET eeprom_config.php?index=N&download=1` — download `content` as a file

Not JSON — returns the raw `content` with `Content-Disposition: attachment;
filename="<file_name>"`.

```bash
curl -H "X-Account: alice" -H "X-Password: hunter2" -H "X-API-Key: <key>" \
  "https://pend.soinc.com.tw/chamonix/api/eeprom_config.php?index=1&download=1" \
  -o downloaded.ini
```

#### `POST eeprom_config.php` — create

Body (JSON): required `file_format`, `fps`, `file_name`, `MHz`, `output_format`,
`support_mode`, `content`; optional `isPGL` (bool, default true), `comment`,
`ext_str1`, `ext_str2`, `ext_int1`, `ext_txt1`–`ext_txt5`. `account` is not
accepted here — the server stamps it from whoever is authenticated, ignoring
anything sent under that name (see field reference below).

```bash
curl -H "X-Account: alice" -H "X-Password: hunter2" -H "X-API-Key: <key>" \
  -H "Content-Type: application/json" \
  -d '{
    "file_format": "INI",
    "fps": "25",
    "file_name": "test.ini",
    "MHz": "24",
    "output_format": "AHD",
    "support_mode": "Master",
    "content": "...",
    "isPGL": true
  }' \
  https://pend.soinc.com.tw/chamonix/api/eeprom_config.php
```

Returns the full created record (same shape as the single-record GET above), HTTP 201.

Missing a required field → `{ "success": false, "error": "Missing required field: file_name" }`, HTTP 400.

#### `POST eeprom_config.php?index=N&_method=PUT` — update

> Travels as `POST` with a `_method=PUT` query param, not a real HTTP `PUT` — some
> hosts (confirmed: this one) block/mangle `PUT` before it reaches PHP. Always use
> this form.

Body (JSON): any subset of the writable fields (same list as create). Only the
fields you send are changed.

```bash
curl -H "X-Account: alice" -H "X-Password: hunter2" -H "X-API-Key: <key>" \
  -H "Content-Type: application/json" \
  -d '{"support_mode":"Slave"}' \
  "https://pend.soinc.com.tw/chamonix/api/eeprom_config.php?index=1&_method=PUT"
```

Returns the full updated record. Not found → HTTP 404. No fields sent → HTTP 400.

#### `POST eeprom_config.php?index=N&_method=DELETE` — delete

> Same `_method` override as update, same reason.

```bash
curl -H "X-Account: alice" -H "X-Password: hunter2" -H "X-API-Key: <key>" \
  -X POST "https://pend.soinc.com.tw/chamonix/api/eeprom_config.php?index=1&_method=DELETE"
```

```json
{ "success": true, "data": { "index": 1 } }
```

Not found → HTTP 404.

---

### `POST collect.php` — telemetry from the desktop applications

**No authentication** (no API key, no login): the applications report on start-up, before anyone signs in.
One request = one launch, stored as one row of `telemetry_logs`. The data is shown on the monitor page
(`monitor/index.php`, see below).

```
POST collect.php
Content-Type: application/json
```

| Field | Required | Max length | Meaning |
|---|---|---|---|
| `machine_id` | **yes** | 64 | Device identity: hash of the hardware IDs below |
| `hostname` | | 128 | Computer name |
| `local_ips` | | 255 | Intranet IP list, e.g. `"192.168.1.50, 10.0.0.12"` |
| `ad_domain` | | 128 | Windows AD domain (empty when not in a domain) |
| `cpu_id` | | 64 | `Win32_Processor.ProcessorId` |
| `mb_uuid` | | 64 | `Win32_ComputerSystemProduct.UUID` |
| `disk_serial` | | 128 | `Win32_DiskDrive.SerialNumber` of the system disk |
| `os_version` | | 64 | e.g. `Windows 11 23H2` |
| `arch` | | 16 | `x64` / `x86` / `ARM64` |
| `app_version` | | 32 | Application version |
| `license_key` | | 128 | License key in use, or the application's "cracked" marker |

Longer values are cut to the maximum length (in characters); fields that are not strings or numbers are
stored as empty. The client's public IP is **not** a field: the server takes it from the connection
(`REMOTE_ADDR`; `CF-Connecting-IP` / `X-Forwarded-For` are believed only from proxies listed in
`telemetry.trusted_proxies`).

```bash
curl -H "Content-Type: application/json" \
  -d '{"machine_id":"3f2a...","hostname":"PC-01","app_version":"2.1.0","license_key":"SOI-XXXX-XXXX-XXXX"}' \
  https://pend.soinc.com.tw/chamonix/api/collect.php
```

```powershell
# Windows PowerShell 5.1: "curl" there is Invoke-WebRequest, so use this instead
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$body = @{ machine_id = 'TEST1'; hostname = 'PC1'; app_version = '1.0.0' } | ConvertTo-Json
Invoke-RestMethod -Method Post -Uri 'https://pend.soinc.com.tw/chamonix/api/collect.php' `
    -ContentType 'application/json; charset=utf-8' -Body $body
```

**This endpoint has its own response shape** (not the `success`/`data` envelope), because that is what the
applications parse:

```json
{ "status": "ok", "latest_version": "1.0.0" }
```

`latest_version` comes from `telemetry.latest_version` in `config.php`.

| HTTP | Body | When |
|---|---|---|
| 200 | `{"status":"ok","latest_version":"..."}` | stored |
| 400 | `{"status":"error"}` | body is not JSON, or `machine_id` missing / empty |
| 405 | `{"status":"error"}` | not a POST |
| 413 | `{"status":"error"}` | body larger than 64 KB |
| 429 | `{"status":"error"}` | more than `telemetry.max_per_minute` (default 30) launches from this IP in the last minute |
| 500 | `{"status":"error"}` | database problem (details in the server's PHP error log, prefix `[collect]`) |

After storing the launch, the server looks up ISP / organisation / country / continent / latitude-longitude of
public IPs at ip-api.com and caches them in `ip_intel_cache` (new IPs, IPs cached without coordinates, and
entries older than `telemetry.geo_refresh_days`, default 90). That lookup is best effort: if it fails, the
launch is still stored and the application still gets `ok`. Private / reserved IPs are never looked up.
`telemetry.geo_lookup = false` turns it off. Note: ip-api.com's free tier allows non-commercial use only and
uses plain http.

**Trust:** anyone can post here, so a row only says what *some client* claimed — the monitor page treats it
as a lead, never as proof (see "Design decisions" below).

---

### `GET update.php` — update manifest for the desktop applications

**No authentication.** The applications request their manifest with no headers at
all, so there is nothing to authenticate with; the packages themselves are static
files under `project/`, which Apache serves to anyone who knows the path. Not
being linked from anywhere is the whole of the protection — appropriate for a test
server, and not a security design.

```
GET update.php?project=<name>&channel=release|debug
    [&format=text|json] [&version=<pinned>] [&list=1]
```

| Parameter | Meaning |
|---|---|
| `project` | Folder name under `project/<channel>/`, e.g. `E2pRom_Generator` |
| `channel` | `release` or `debug` |
| `format` | `text` (default, what the applications parse) or `json` |
| `version` | Pin a specific version instead of the newest — for a rollback |
| `list` | `1` returns every published version as JSON |

One endpoint serves every product: adding an application means adding a folder,
not an endpoint.

#### Text response (default)

```bash
curl "https://pend.soinc.com.tw/chamonix/api/update.php?project=E2pRom_Generator&channel=release"
```

```
1.0.2
https://pend.soinc.com.tw/chamonix/project/release/E2pRom_Generator/E2pRom_Generator-1.0.2-setup.exe
SQL Database cloud sync; Rule 29 writes {0x96,0x07}
9f8a2c1e...64 hex chars...
```

| Line | Content |
|---|---|
| 1 | Latest version, dotted |
| 2 | Absolute URL of the package |
| 3 | Release notes, one line — **never empty**, because the client's parser drops blank lines and the checksum would shift up into the notes |
| 4 | SHA-256 of the package, hex |

Nothing published answers **404** with `0.0.0` on line 1, which every client
compares against its own version and reads as "no update".

#### JSON response

```bash
curl "https://pend.soinc.com.tw/chamonix/api/update.php?project=SOICamConfig&channel=debug&format=json"
```

```json
{
  "success": true,
  "data": {
    "project": "SOICamConfig", "channel": "debug",
    "version": "1.0.7", "file": "SOICamConfig-1.0.7-setup.exe",
    "url": "https://pend.soinc.com.tw/chamonix/project/debug/SOICamConfig/SOICamConfig-1.0.7-setup.exe",
    "notes": "...", "sha256": "...", "size": 27216464, "built": "2026-09-18 13:24:00"
  }
}
```

#### Publishing a version

Upload the installer into `project/<channel>/<project>/`, named
`<anything>-<version>[-setup].exe`. That is the whole procedure: the version
lives in the file name, so there is no index to update and no index to forget.
Optional release notes go in a file of the same name with a `.txt` extension.
`<file>.sha256` is written automatically on the first request and refreshed when
the package is newer.

A file whose name does not match the pattern is ignored **silently** — after an
upload, check `?list=1` to confirm it is really published. See
`www/project/README.md`.

---

## Monitor dashboard — `monitor/index.php`

Not an API but a page (HTML), documented here because it is the reader of `collect.php`'s data.
URL: `https://pend.soinc.com.tw/chamonix/monitor/index.php`. Setup and operation: `README_DEPLOY.md`,
section "Monitor dashboard".

- **Its own sign-in.** Same accounts as the EEPROM Config page (`users`, bcrypt), but a separate login state
  (session key `monitor_account`): signing in or out on one page does not affect the other. Only accounts in
  `config.php` `monitor_admins` may sign in (empty list = every account). Same attempt limit as `login.php`.
- **Query parameters** (all optional; they are also what the filter row writes):

| Parameter | Values | Default |
|---|---|---|
| `range` | `5m`, `15m`, `1h`, `24h`, `7d`, `30d`, `90d`, `all` | `7d` (`15m` in demo mode) |
| `ver` | an `app_version` | all versions |
| `target` | an organisation name (AD domain, else ISP organisation, else public IP) | none = no drill-down |
| `demo` | `1` = fake data from `monitor/demo_data.php`; the database is not queried | off |

- **Live refresh** (the 即時更新 menu: off / 1 / 5 / 10 / 30 / 60 s) re-requests the **same URL** in the
  background and swaps in the new numbers; there is no separate JSON API. Each refresh runs the page's full set
  of queries, so prefer 5–10 s on real data and long ranges.
- **Security:** every response sends a Content-Security-Policy with a per-response nonce for its inline
  scripts; collected values are HTML-escaped and embedded as JSON with `<`, `>`, `&`, quotes escaped.

---

## Value domains and `content` encoding

This table is synced into the desktop tool (E2pRom_Generator, **Sync Cloud**), which
stores the same columns with the same values. Two sides that spell a value differently
would make the same row flip back and forth on every sync, so three fields have fixed
domains:

| Field | Allowed values |
|---|---|
| `file_format` | `INI`, `BIN` |
| `output_format` | `AHD`, `YUV422` |
| `support_mode` | `Master`, `Slave` |
| `isPGL` | `1`, `0` |

Input is matched case-insensitively and stored in the spelling above (`ini` → `INI`).
Anything else is rejected:

```json
{ "success": false, "error": "Invalid support_mode: \"HDR\". Allowed: Master, Slave" }
```
→ HTTP 400

### `content` for a BIN record

`content` is a MySQL `MEDIUMTEXT` column and cannot carry raw binary, so a BIN record's
image travels and is stored as **hex text**:

```
12 40 AD 01 00 12 34 56 78 9A BC DE F0 11 22 33
44 55 66 77
```

16 bytes per line. The API re-normalizes whatever hex you send into exactly this
spacing (`0x12`, `12,40`, one long line — all accepted), so the same file uploaded
here and imported in the desktop tool produce the same string and a sync sees no
difference. Content that is not hex is rejected with HTTP 400.

`?download=1` converts it back: the file you get is the image, not the hex. A BIN
record whose stored content is not valid hex returns HTTP 409 instead of a file.

An `INI` record's `content` is the file's own text, unchanged.

---

## `eeprom_config` field reference

| Field | Type | Required on create | Notes |
|---|---|---|---|
| `index` | int | — | primary key, auto-increment, read-only |
| `file_format` | string(50) | yes | **`INI` or `BIN` only** |
| `fps` | string(50) | yes | |
| `file_name` | string(150) | yes | used as the download filename |
| `MHz` | string(50) | yes | |
| `isPGL` | bool (0/1) | no | default `true` |
| `output_format` | string(50) | yes | **`AHD` or `YUV422` only** |
| `support_mode` | string(50) | yes | **`Master` or `Slave` only** |
| `content` | mediumtext | yes | the config file body — **hex text when `file_format` is `BIN`**; the only column returned by `download=1` |
| `account` | string(100) | — | **read-only**: stamped server-side from the authenticated session/credentials on every create/update; sending it in a request body has no effect |
| `comment` | text | no | free text — what this version is for (production/sample/test/...) |
| `ext_str1` | string(100) | no | free-form extension field |
| `ext_str2` | string(255) | no | free-form extension field |
| `ext_int1` | int | no | free-form extension field |
| `ext_txt1`–`ext_txt5` | text | no | free-form extension fields |
| `create_time` | datetime | — | set automatically, read-only |
| `modify_time` | datetime | — | updated automatically on every write, read-only |

---

## HTTP status codes used

| Code | Meaning |
|---|---|
| 200 | success (GET/PUT/DELETE) |
| 201 | created (POST create) |
| 400 | bad request — missing/invalid field, invalid `index`; for `reset_password.php` also an invalid/expired link or a rejected new password |
| 401 | API key and/or login check failed |
| 403 | `HTTPS required` — a plain-http call while `security.force_https` is on |
| 404 | record not found |
| 405 | method not allowed |
| 413 | `collect.php`: request body over 64 KB |
| 429 | too many attempts: sign-in limit (`login.php`, header login, monitor) with `Retry-After`, or `collect.php`'s per-IP limit |
| 500 | server error (DB unavailable, missing config — details go to the server's PHP error log, never to the client) |

---

## Design decisions worth knowing before integrating

- **Update/delete use `POST` + `?_method=`, not real `PUT`/`DELETE`** — this host
  (and many shared-hosting setups) blocks or mangles those verbs before PHP sees
  them. Always use the `_method` form shown above.
- **Basic Auth may not work on your host** — this deployment's PHP-CGI setup strips
  the `Authorization` header entirely; use the `X-Account`/`X-Password` headers
  instead (confirmed to work here). If integrating from a different server, try
  Basic Auth first and fall back to the custom headers if you get `Login required`
  with correct credentials.
- **Three fields have fixed value domains and a BIN `content` is hex text** — see the
  section above. This is what lets the desktop tool copy rows in without translating
  anything; a conversion layer on either side would be one more place for a row to come
  out subtly wrong.
- **List responses are paginated by default** (`page`/`limit`, max 100) — pass
  `?all=1` to get everything in one response if you don't need paging.
- **The API key is not equivalent to login credentials.** It stops naive/automated
  direct hits; the account/password (session or per-request) is what actually
  controls read/write access to the data.
- **Expect 429 on sign-in.** After 5 wrong passwords for an account (or 20 from one IP) in 15 minutes, every
  sign-in for it is refused for a while, correct password included. A tool must back off on 429 (honour
  `Retry-After`), not retry in a loop — retrying keeps the lock alive.
- **HTTPS only.** Calls over plain http get 403 on every endpoint that takes the API key; use `https://`.
- **Telemetry is self-reported.** `collect.php` is open to anyone, so a client can send any hostname, AD domain or
  license key — including someone else's, to make another company look like a pirate. Treat the monitor's
  rankings and "疑似盜用" flags as leads to check, not evidence.
