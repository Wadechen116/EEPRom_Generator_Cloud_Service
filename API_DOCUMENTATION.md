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

Every endpoint except `public_key.php` and `update.php` requires **two independent
layers**, both must pass:

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

On success, starts a session (browser use) and always returns the account name —
useful for non-browser callers too, as a "did these credentials work" check.

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

---

### `POST logout.php`

No body. Clears the session.

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

### `eeprom_config.php` — the `eeprom_config` table

Requires API key **and** login (session or per-request credentials, see above).

#### `GET eeprom_config.php` — list (paginated)

Query params: `page` (default 1), `limit` (default 20, max 100).
Returns summary columns only (no `content`/`ext_txt*` — see detail view below).

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
        "modify_time": "2026-09-17 12:33:08"
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

```bash
curl -H "X-Account: alice" -H "X-Password: hunter2" -H "X-API-Key: <key>" \
  "https://pend.soinc.com.tw/chamonix/api/eeprom_config.php?all=1"
```

```json
{ "success": true, "data": { "items": [ /* every row, summary columns */ ], "total": 6 } }
```

#### `GET eeprom_config.php?index=N` — single record (full detail)

Includes `content` and all `ext_*` columns.

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
    "modify_time": "2026-09-17 12:33:08",
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
`support_mode`, `content`; optional `isPGL` (bool, default true), `ext_str1`,
`ext_str2`, `ext_int1`, `ext_txt1`–`ext_txt5`.

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
| 400 | bad request — missing/invalid field, invalid `index` |
| 401 | API key and/or login check failed |
| 404 | record not found |
| 405 | method not allowed |
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
