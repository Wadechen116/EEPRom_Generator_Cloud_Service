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
`UPDATE` statement above to fix it. The table also still has a `password` column
left over from manual creation; `api/login.php` never reads it, so once you're
comfortable, drop it: `ALTER TABLE users DROP COLUMN password;`

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

**Do this step directly on/for the server, or in a local copy you never commit.**
`config.php` is intentionally left out of this package (only the `.sample.php`
template is here) so there is nothing containing real credentials to accidentally
ship, zip, or paste anywhere.

## 3. Package and deploy

Zip the **contents** of `www/` (not the `www` folder itself) and upload/extract
into the remote Apache `www` folder:

```powershell
# from D:\swork\WebPage
Compress-Archive -Path www\* -DestinationPath deploy.zip -Force
```

Extract `deploy.zip` directly into the remote `www` folder. Final layout on the
server should be `www/index.html`, `www/api/...`, `www/assets/...` etc. at the web
root (or whatever sub-path the remote www folder is already served from).

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
