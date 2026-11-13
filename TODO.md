# TODO — EEPROM Generator cloud service

Pending items only. For what's already shipped, see `CHANGELOG.md`. For API
usage see `API_DOCUMENTATION.md`, for deployment steps see `README_DEPLOY.md`.

## In progress

### Forgot-password flow
**Code complete and tested; not live yet.** What's left is deploying it and the mail
relay access below. Everything is written so that deploying it *before* the relay is
open is harmless: with `mail.enabled` false (or the `mail` block missing) a request is
accepted and quietly ignored — nothing stored, nothing sent.

- **Built**: "Forgot password?" on the login screen → `api/forgot_password.php` →
  emailed link `<app_url>/index.html#reset=<token>` → reset form → `api/reset_password.php`.
  Token-based: `password_resets` table (`token_hash` = sha256 of a random 32-byte token,
  `expires_at`, `used_at`); the raw token only ever exists in the email. On reset, only
  `password_hash` is updated — **not** the legacy `password` column (writing a plaintext
  password back there was explicitly rejected, see decision log below). Details in
  `API_DOCUMENTATION.md`; how to bring it live in `README_DEPLOY.md`.
- **Blocked on**: SMTP relay access. Tested via a one-off `api/_diag_smtp.php`
  (already deleted, not part of the repo): connects fine to `ms.soinc.com.tw`,
  but `RCPT TO` is rejected —
  `554 5.7.1 <pend.soinc.com.tw[192.168.20.20]>: Client host rejected: Access denied`.
  The WebPage server's IP is not in the mail server's allowed-relay list. The app now
  logs exactly this line (`[Mailer] ... [relay replied: 554 5.7.1 ...]`) whenever the
  relay refuses, so it is visible in the server's PHP error log without a diag script.
- **Next step** (owned by IT / the mail server admin, not the app developer —
  see decision log): either (a) allow-list `192.168.20.20` for unauthenticated
  relay, or (b) issue an authenticated SMTP service account, with the
  credentials filled directly into `api/config/config.php` by whoever manages
  the mail server (the `mail` block; template in `config.sample.php`).
- **To confirm with IT while doing that**: the sender address the relay accepts
  (`mail.from` — may have to be in its own domain), and whether it needs TLS (587/465,
  which also needs PHP's `openssl` extension on the web server) or plain port 25.
- **Then verify on the live server**, once: run `sql/migrate_2026-10-06_password_resets.sql`,
  set `mail.enabled` to `true`, request a reset for an account that has an email, open the
  link from the mail, set a password, log in with it. (All of this has been run against a
  local MariaDB + a stand-in mail relay; the one thing that has never been run is the real
  relay, because it refuses this server.)
- **Known limits, accepted for now**: users need `users.email` filled in (no UI for that —
  phpMyAdmin / `tools/create_user.php`); a reset does not end login sessions that are already
  open in other browsers (PHP sessions can't be revoked per user); no "your password was
  changed" notification email; `password_resets` rows are never purged (see the housekeeping
  query at the end of the migration file).
- **Decision log**:
  - Reset should update only `password_hash`, never the plaintext `password`
    column — confirmed with the project owner; re-introducing a stored
    plaintext password would undo the whole point of hashing it.
  - Don't build a mail server (MTA) for this — relay through the existing
    corporate mail server (Zimbra/Postfix) via PHPMailer, self-hosted like
    p5.js/particles.js, not a CDN dependency.
  - The developer implementing this app does not want to personally hold SMTP
    credentials; the mail-relay access/credential is IT's to own and configure,
    the app only calls it.
  - Not a separate project/service — a small `api/lib/Mailer.php` inside this
    same repo is enough; splitting mail-sending into its own deployable service
    would only make sense with multiple unrelated apps needing to share one.

## Not started

### Monitor / telemetry follow-ups
- **Deploy the event / client_ip / rate-limit change** (2026-11-13): run `sql/migrate_2026-11-13_event.sql`, upload
  `api/collect.php` + `monitor/index.php`, and in the server's `config.php` replace `telemetry.max_per_minute` (no longer
  read) with `max_per_machine_per_minute` / `max_per_ip_per_minute` if it was set.
- **Fill in `monitor_admins`** in the server's `config.php` — until then every account can open the monitor.
- **Delete the test row** `DELETE FROM telemetry_logs WHERE machine_id = 'TEST1';`.
- **IP lookup provider** — ip-api.com's free tier is non-commercial only and plain http. Options: Geoapify's IP
  Geolocation API (https, same key as the map) or a self-hosted MaxMind GeoLite2 database.
- **Privacy / retention** — mention the telemetry (computer names, AD domains, intranet IPs, hardware serials) in
  the license terms / privacy notice; decide a retention period and purge older `telemetry_logs` rows.
- **Signed telemetry** (optional) — anyone can post to `collect.php`; an HMAC signature from the application would
  stop casual forgery (a key inside the executable can still be extracted, so not a guarantee).

### Security follow-ups
- **HSTS** — set `security.hsts_max_age` to 300, later 31536000, once it is certain no site on the host
  `pend.soinc.com.tw` is http-only (HSTS covers the whole host).
- **phpMyAdmin exposure** — `cosmos.soinc.com.tw/phpmyadmin` should be reachable from the company network / VPN
  only (IT).
- **Code-signing of update packages** — `update.php`'s SHA-256 lives on the same server as the packages; an
  Authenticode signature checked by the applications would survive a compromised server.

### Older items

- **Rotate the API key** — the current one was pasted into chat once during
  earlier troubleshooting. Not a high-value secret (see API_DOCUMENTATION.md
  on what it does/doesn't protect), but cheap to rotate: generate a new one
  (`php -r "echo bin2hex(random_bytes(32));"`), update `api/config/config.php`.
  Nothing else needs to change — `assets/js/app.js` fetches it at runtime.
- **CSRF** — the session cookie is `SameSite=Lax`, which blocks most
  cross-site POST forgeries but isn't a dedicated CSRF token. Acceptable
  trade-off for the current scope; revisit if this ever needs a stricter
  security posture.
- **In-app user management** — creating/disabling login accounts is entirely
  manual (`tools/create_user.php` + phpMyAdmin), no API/UI for it. Fine at the
  current handful of users; would need real design work (who's allowed to
  create accounts, etc.) before adding an endpoint for it.

## Housekeeping reminders

- Any `api/_diag_*.php` file is a temporary, unauthenticated diagnostic script
  (see CHANGELOG.md for the pattern) — upload only while actively debugging,
  delete from the server immediately after, never commit to the repo.
- Bump the `?v=` cache-busting query param in `index.html` whenever
  `style.css`/`app.js`/etc. change (see the comment above the `<link>` tag) —
  a redeploy without bumping it can silently keep serving the old file to
  browsers/caches that already loaded the page once.
