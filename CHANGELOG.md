# Changelog — EEPROM Generator cloud service

What's shipped, grouped by area. For pending work see `TODO.md`. For full API
details see `API_DOCUMENTATION.md`; for deployment steps see `README_DEPLOY.md`.

## Backend / API

- RESTful CRUD for `eeprom_config`: list (paginated, `total`/`total_pages`),
  `?all=1` (no pagination), get one, create, update, delete, download.
- Update/delete travel as `POST ...&_method=PUT|DELETE` rather than real HTTP
  `PUT`/`DELETE` — this host blocks/mangles those verbs before PHP sees them.
- Two-layer auth on every data endpoint: `X-API-Key` header (blocks naive bot
  traffic) **and** either a browser session (`api/login.php`) or per-request
  credentials. Per-request auth tries standard `Authorization: Basic` first,
  then falls back to plain `X-Account`/`X-Password` headers — confirmed this
  host strips the `Authorization` header before PHP ever sees it, so the
  fallback is what actually works here.
- `api/public_key.php` — the one unauthenticated endpoint; hands out the API
  key at page load so `config.php` stays the single source of truth (fixed a
  real recurring bug where redeploying `app.js` silently reset a manually
  synced key back to its placeholder).
- Fixed value domains: `file_format` (INI/BIN), `output_format` (AHD/YUV422),
  `support_mode` (Master/Slave) — matched case-insensitively, stored in
  canonical spelling, rejected otherwise. Keeps this table and the desktop
  tool's sync from fighting over spelling differences.
- BIN records store `content` as hex text ("12 40 AD 01"), re-normalized to one
  canonical spacing on every write so the same file imported from either side
  produces the same string. `?download=1` converts it back to real bytes;
  refuses (409) rather than handing back a `.bin` full of hex text.
- `account` (server-stamped from the authenticated session/credentials on
  every create/update — never client-settable) and `comment` (free text)
  columns. Confirmed the Items list displays the correct account per row.
- `api/update.php` — serves version/download manifests for the desktop apps
  (E2pRom_Generator, SOICamConfig) via `www/project/`, no auth (the apps
  request it with no headers at all).
- Forgot-password endpoints (*code complete, goes live once deployed and the mail
  relay is open — see `TODO.md`*): `api/forgot_password.php` emails a one-time link,
  `api/reset_password.php` checks it / sets the new password. One generic answer to
  every request (no account enumeration, also not by response time — the reply is sent
  before the mail); throttled per account and per IP; only the SHA-256 of the token is
  stored; one atomic claim per link; only `password_hash` is written. Mail goes through
  the corporate relay with a vendored PHPMailer 6.10.0 (`api/lib/phpmailer/`), driven by
  the `mail` block of `config.php`; the relay's own refusal text is logged for the mail
  admin. New table `password_resets` (`sql/migrate_2026-10-06_password_resets.sql`).

## Telemetry and monitor dashboard (2026-11-12 / 2026-11-13)

- `api/collect.php` — telemetry endpoint for the desktop applications (no auth; one launch per POST into
  `telemetry_logs`). Reads `config.php` (no hard-coded DB credentials), trusts only `REMOTE_ADDR` unless a proxy is
  listed, per-IP limit (429), 64 KB body limit, typed/length-limited fields. IP lookup (ISP, organisation,
  country, continent, lat/lon) cached in `ip_intel_cache`, refreshed after 90 days, and best effort: a failed
  lookup never fails the request.
- `monitor/index.php` — Kibana-style dashboard: stat tiles with previous-period change, 即時動態 (per second, last
  60 s), launches / devices over time, weekday × hour heat map, organisation / country / version / OS /
  architecture rankings, license-key and organisation leaderboards with 超用 / 跨組織 / 疑似 VM 克隆 / 多機集中
  flags, event log, organisation drill-down. Light / dark theme.
- Geography (`monitor/geo.php`): Leaflet map on Geoapify tiles (dots sized by devices, red = 疑似盜用), 五大洲 and
  per-country 疑似盜用 statistics; 疑似盜用 = over-used key, VM-clone organisation or a configured crack marker.
- Time ranges 5 min … 90 days / all, with 10 s / 1 min / 1 h / 1 day buckets; live refresh every 1–60 s without
  reloading (keeps scroll, zoom and map view).
- Own sign-in for the monitor (same accounts, separate login state), limited to `config.php` `monitor_admins`.
- Demo mode (`?demo=1`, `monitor/demo_data.php`): a deterministic fake world with per-second data, so every chart
  moves; never touches the database.
- SQL: `sql/migrate_2026-11-12_telemetry.sql` (record of the hand-made tables), `sql/migrate_2026-11-13_ip_geo.sql`.

## Telemetry for the MonitorSoftDevice library (2026-11-13)

- `collect.php` replies with `client_ip` (the caller's public IP as the server sees it).
- Report types: `event` = `start` / `heartbeat` / `manual` stored in `telemetry_logs.event`
  (`sql/migrate_2026-11-13_event.sql`; missing / unknown = `start`). The monitor counts only `start` as launches,
  every report still counts for active devices; the event log shows the type.
- Rate limit per device (10 / minute per `machine_id`) plus a high per-IP ceiling (600 / minute) instead of 30 per IP,
  so companies behind one NAT address are not blocked; 429 now carries `Retry-After: 60`.

## Security hardening (2026-11-13)

- Plaintext `users.password` column wiped and dropped (`sql/migrate_2026-11-13_security.sql`, part 2).
- Sign-in attempt limit on every login path (`api/lib/LoginThrottle.php`, table `login_attempts`): 5 failures
  per account / 20 per IP in 15 minutes → 429 with `Retry-After`, correct password included.
- HTTPS enforced (`security.force_https`, on): page redirects built from `app_url`, 403 for API calls over http;
  `collect.php` / `update.php` exempt. `app.js` moves pages opened by host name to https and refuses framing.
- Security headers on every PHP response (`api/lib/SecurityHeaders.php`) and, via the root `.htaccess`, on static
  files: nosniff, `X-Frame-Options: DENY`, Referrer-Policy, Permissions-Policy, COOP, Content-Security-Policy
  (nonce-based on the monitor; `index.html` also carries its CSP as `<meta>`). HSTS available, off by default.

## Frontend

- Login screen (session-based), logout, session-status check on page load.
- "Forgot password?" on the login screen → request panel → (from the emailed link,
  `#reset=<token>` in the URL fragment so it stays out of server logs, removed from the
  address bar once read) reset panel. The page checks the link before showing the form,
  ends its own session after a reset, and handles a link opened while already logged in.
- Items list: search box (client-side, instant), 3D rotating tag cloud
  (auto-generated from file_format/support_mode/output_format/fps, click to
  filter), client-side pagination (10/page, switcher only once needed).
- Add/Edit as a modal dialog; "Load from file" reads a local `.ini`/`.bin` and
  pre-fills fields using the same register-pair analysis the desktop tool uses
  (fps/isPGL/output_format/support_mode), leaving anything it can't determine
  blank rather than guessing.
- `[hidden] { display: none !important }`: an element with the `hidden` attribute now stays hidden even when a class gives it a
  `display` (the Logout button showed on the login screen because `.user-box` is `display:flex`).
- Icon buttons (inline SVG, no icon-font dependency) for edit/download/delete;
  the actions column is `position: sticky` so it stays visible while the wide
  content columns scroll underneath it.
- Toast notifications for save/update/delete success.
- Decorative: p5.js fish tank and a particles.js background, both self-hosted
  (no CDN dependency), both pause when the tab isn't visible.

## Layout fixes

- Root cause of an Items-panel centering/overflow bug: `main.container` is
  `display: grid` with no explicit `grid-template-columns`, so its implicit
  track sized to the 13-column table's natural width instead of respecting
  `max-width` — the table (and the tag-cloud/fish-tank side rail riding along
  with it) visually overflowed the page. Fixed with
  `grid-template-columns: minmax(0, 1fr)` (the grid equivalent of `min-width:
  0` on a flex item), plus a `min-width: 0` chain through `.items-layout` →
  `.panel` → `.table-wrap`, plus `overflow-x: hidden` on `<body>` as a backstop.
- Added `?v=` cache-busting query params on `style.css`/`app.js`/etc. after
  this bug took multiple redeploys to actually reach the browser.

## Deployment / infra

- Everything under `www/` is a drop-in for the remote Apache `www` folder — no
  server config changes, ever (confirmed repeatedly necessary: this host has
  several non-default restrictions — PUT/DELETE blocked, `Authorization`
  header stripped, `WindowsTargetPlatformVersion`-style SDK floating-version
  resolution not applicable here but same "assume nothing about the host"
  lesson).
- `api/config/config.php` (real DB credentials) is never part of the repo or
  the deploy package — only `config.sample.php` is. Same for `deploy.tar.gz`
  itself and any `api/_diag_*.php` temporary diagnostic script.
- Git history: this repo diverged when two commits (`7e78a66` value-domain
  validation, `dc7969d` update-manifest serving) were pushed directly to
  GitHub from elsewhere while local work (account/comment fields, the layout
  fix) was still uncommitted. Reconciled with a proper `git merge` rather than
  overwriting either side — see commit `79bc39f`.
