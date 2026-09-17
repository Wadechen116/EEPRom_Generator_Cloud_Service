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

Every endpoint except `public_key.php` requires **two independent layers**, both must
pass:

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
        "support_mode": "Normal",
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
    "support_mode": "Normal",
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
    "output_format": "RAW10",
    "support_mode": "Normal",
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
  -d '{"support_mode":"HDR"}' \
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

## `eeprom_config` field reference

| Field | Type | Required on create | Notes |
|---|---|---|---|
| `index` | int | — | primary key, auto-increment, read-only |
| `file_format` | string(50) | yes | e.g. `INI`, `BIN` |
| `fps` | string(50) | yes | |
| `file_name` | string(150) | yes | used as the download filename |
| `MHz` | string(50) | yes | |
| `isPGL` | bool (0/1) | no | default `true` |
| `output_format` | string(50) | yes | |
| `support_mode` | string(50) | yes | |
| `content` | text | yes | the config file body; only column returned by `download=1` |
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
- **List responses are paginated by default** (`page`/`limit`, max 100) — pass
  `?all=1` to get everything in one response if you don't need paging.
- **The API key is not equivalent to login credentials.** It stops naive/automated
  direct hits; the account/password (session or per-request) is what actually
  controls read/write access to the data.
