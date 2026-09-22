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

## Frontend

- Login screen (session-based), logout, session-status check on page load.
- Items list: search box (client-side, instant), 3D rotating tag cloud
  (auto-generated from file_format/support_mode/output_format/fps, click to
  filter), client-side pagination (10/page, switcher only once needed).
- Add/Edit as a modal dialog; "Load from file" reads a local `.ini`/`.bin` and
  pre-fills fields using the same register-pair analysis the desktop tool uses
  (fps/isPGL/output_format/support_mode), leaving anything it can't determine
  blank rather than guessing.
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
