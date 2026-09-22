# TODO — EEPROM Generator cloud service

Pending items only. For what's already shipped, see `CHANGELOG.md`. For API
usage see `API_DOCUMENTATION.md`, for deployment steps see `README_DEPLOY.md`.

## In progress

### Forgot-password flow
Design agreed, blocked on mail delivery.

- **Design**: token-based reset. A new `password_resets` table (user_id,
  `token_hash` = sha256 of a random 32-byte token, `expires_at`, `used_at`).
  The emailed link carries the raw token; only its hash is ever stored. On
  reset, only `password_hash` is updated — **not** the legacy `password`
  column (writing a plaintext password back there was explicitly rejected,
  see decision log below).
- **Blocked on**: SMTP relay access. Tested via a one-off `api/_diag_smtp.php`
  (already deleted, not part of the repo): connects fine to `ms.soinc.com.tw`,
  but `RCPT TO` is rejected —
  `554 5.7.1 <pend.soinc.com.tw[192.168.20.20]>: Client host rejected: Access denied`.
  The WebPage server's IP is not in the mail server's allowed-relay list.
- **Next step** (owned by IT / the mail server admin, not the app developer —
  see decision log): either (a) allow-list `192.168.20.20` for unauthenticated
  relay, or (b) issue an authenticated SMTP service account, with the
  credentials filled directly into `api/config/config.php` by whoever manages
  the mail server.
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

- **Drop the legacy `password` column** on `users` once comfortable — it's
  unused (only `password_hash` is read by `api/login.php`), but still holds
  whatever plaintext was typed in when accounts were created manually via
  phpMyAdmin: `ALTER TABLE users DROP COLUMN password;`
- **Rotate the API key** — the current one was pasted into chat once during
  earlier troubleshooting. Not a high-value secret (see API_DOCUMENTATION.md
  on what it does/doesn't protect), but cheap to rotate: generate a new one
  (`php -r "echo bin2hex(random_bytes(32));"`), update `api/config/config.php`.
  Nothing else needs to change — `assets/js/app.js` fetches it at runtime.
- **Login brute-force protection** — `api/login.php` has no failed-attempt
  rate limiting or lockout. Low priority for a small internal user base, but a
  known gap if this is ever exposed more broadly.
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
