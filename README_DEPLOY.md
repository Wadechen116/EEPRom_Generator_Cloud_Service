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

"Login" means either a browser session (`login.php` + cookie) or per-request
`X-Account`/`X-Password` headers — see API_DOCUMENTATION.md for both.

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

### Forgot-password table and email addresses

The "Forgot password?" flow stores its one-time links in a new table, `password_resets`.
Run **`sql/migrate_2026-10-06_password_resets.sql`** once in phpMyAdmin's SQL tab (safe to
re-run). It holds only the SHA-256 of each emailed token, never the token itself.

A user can only reset a password if their `users.email` is filled in — the page can't
tell anyone whether an account exists, so an account without an email simply never gets a
mail. Set it with the same tool as above (`php tools/create_user.php <name> <password> <email>`
prints an `UPDATE ... SET password_hash = ..., email = ...`), or directly in phpMyAdmin:
`UPDATE users SET email = 'someone@soinc.com.tw' WHERE name = 'someone';`

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
