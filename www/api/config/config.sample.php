<?php
/**
 * Template for api/config/config.php (the real config is NOT included in this package).
 *
 * Deployment steps on the server:
 *   1. Copy this file to config.php (same folder).
 *   2. Fill in the real DB credentials and generate a real API key below.
 *   3. Never commit config.php to source control or paste its contents into chat/tickets.
 *
 * Why this is safe even though it lives under the web root:
 *   - This file only "return"s a PHP array; it never echoes/prints anything.
 *   - Apache already executes .php files through the PHP handler (that's existing,
 *     unrelated server config -- we don't touch it), so a direct browser request to
 *     this URL executes the file and returns an EMPTY response body, not the source
 *     or the values. Nothing here is ever sent to a client.
 */

if (!defined('APP_ENTRY')) {
    http_response_code(403);
    exit;
}

return [
    'db' => [
        'host'    => '127.0.0.1',   // TODO: real MariaDB host/IP -- fill in on the server only
        'port'    => 3306,
        'name'    => 'your_database',
        'user'    => 'your_db_user',
        'pass'    => 'your_db_password',
        'charset' => 'utf8mb4',
    ],

    // Shared secret the frontend/clients must send as the "X-API-Key" header.
    // Generate a real one on the server with:  php -r "echo bin2hex(random_bytes(32));"
    'api_key' => 'REPLACE_WITH_A_LONG_RANDOM_STRING',

    // ---- Forgot-password email (api/forgot_password.php, api/lib/Mailer.php) -------------------
    //
    // Public address of THIS site, as users reach it, with any sub-path and no trailing slash: ONLY the address,
    // e.g. 'https://pend.soinc.com.tw/chamonix'. Not the reset link (no "index.html#reset=<token>" here: the
    // program adds the page name and the token itself). The reset
    // link in the email is built from it, as <app_url>/index.html#reset=<token> (the page is named so the
    // web server's DirectoryIndex order cannot send the link to another index file). It is configured, not read from the request, because the Host
    // header is chosen by whoever sends the request -- and a forged one would put an attacker's address
    // into a real, signed-looking email.
    'app_url' => 'https://pend.soinc.com.tw/chamonix',   // CONFIRM: the URL users type in the browser

    // The corporate SMTP relay. Owned by the mail admin: whoever manages the mail server fills this in
    // directly on the server; the developer never needs to hold these values (see TODO.md).
    'mail' => [
        // Stays false until the relay accepts mail from this server. While false, "Forgot password?"
        // requests are accepted but nothing is stored or sent, so no usable link is ever created.
        'enabled'    => false,

        'host'       => 'ms.soinc.com.tw',   // CONFIRM with the mail admin
        'port'       => 25,                  // 25 (relay), 587 (submission + STARTTLS), 465 (implicit TLS)
        'encryption' => '',                  // '' = none, 'tls' = STARTTLS, 'ssl' = implicit TLS (both need PHP openssl)

        // Leave both empty when the relay allow-lists this server's IP and needs no login
        // (option (a) in TODO.md). Fill both for an authenticated service account (option (b)).
        'username'   => '',
        'password'   => '',

        // Envelope/header sender. The relay may only accept a sender in its own domain -- CONFIRM.
        'from'       => 'noreply@soinc.com.tw',
        'from_name'  => 'EEPROM Generator cloud service',

        'timeout'           => 10,   // seconds to wait on the relay before giving up
        'reset_ttl_minutes' => 30,   // how long an emailed link works (5..1440)
    ],

    // ---- Monitor dashboard (monitor/index.php) --------------------------------------------------
    // users.name values allowed to sign in to the dashboard (on its own sign-in form, with their usual account).
    // Empty array = every account may sign in there.
    'monitor_admins' => [],

    // ---- Telemetry endpoint (api/collect.php, called by the desktop app) -----------------------
    'telemetry' => [
        'latest_version'  => '1.0.0',   // returned to the app as "latest_version"
        'max_per_minute'  => 30,        // records accepted per client IP per minute (0 = no limit)
        'geo_lookup'      => true,      // look up ISP/org at ip-api.com (free tier: non-commercial, HTTP only)
        // REMOTE_ADDR of a reverse proxy / Cloudflare in front of this server. Only requests coming
        // from these IPs may set the client IP via CF-Connecting-IP / X-Forwarded-For. Empty = none.
        'trusted_proxies' => [],

        // monitor/index.php thresholds
        'license_device_limit' => 1,    // devices one license key may run on; more = flagged "超用"
        'org_device_alert'     => 5,    // devices per organisation flagged as "多機集中"
        'geo_refresh_days'     => 90,   // re-look-up a cached IP's location after this many days
        // license_key values the desktop app sends for a cracked / unlicensed copy (exact match).
        // Devices reporting one of these count as "疑似盜用" on the map and in the country statistics,
        // together with devices on an over-used license key and devices in a VM-clone organisation.
        'crack_markers'        => [],
    ],

    // ---- Security (api/lib/SecurityHeaders.php) ---------------------------------------------------
    'security' => [
        // true = pages over http are redirected to https and password-carrying API calls over http are refused.
        // Turn on ONLY after the monitor page footer shows 「伺服器偵測 HTTPS：是」: if PHP cannot see HTTPS
        // (TLS ended by a proxy in front of Apache), turning it on would redirect in a loop / refuse every call.
        'force_https'     => false,
        // > 0 sends Strict-Transport-Security: browsers then refuse plain http for this HOST (all of
        // pend.soinc.com.tw, not only /chamonix) for that many seconds. Start with 300; 31536000 once happy.
        'hsts_max_age'    => 0,
        // REMOTE_ADDR of a TLS-terminating proxy whose X-Forwarded-Proto header may be trusted. Empty = none.
        'trusted_proxies' => [],
    ],

    // ---- Login attempt limit (api/lib/LoginThrottle.php; table from sql/migrate_2026-11-13_security.sql) ----
    // Applies to the main page, header login (X-Account/X-Password) and the monitor page.
    'login_throttle' => [
        'window_minutes'  => 15,   // look-back window, and how long a lock lasts at most
        'max_per_account' => 5,    // failures for one account name (from any IP) before it is locked
        'max_per_ip'      => 20,   // failures from one IP (any account names) before that IP is locked
    ],

    // ---- Map on monitor/index.php: Geoapify tiles (drawn with Leaflet) ------------------------
    // API key of your Geoapify project (myprojects.geoapify.com -> project -> API keys). Only the key is needed;
    // the project id is not used by the tile URL. The key is sent to the browser with every tile request, so it
    // cannot be secret: in the project's API key settings, allow only the HTTP referrer
    // https://pend.soinc.com.tw/* (or the exact site). Empty = the map card shows setup instructions instead.
    'geoapify' => [
        'api_key'     => '',
        // Map styles (https://apidocs.geoapify.com/docs/maps/map-tiles/): light / dark page theme.
        'style_light' => 'osm-bright-grey',     // others: positron, klokantech-basic, osm-bright, ...
        'style_dark'  => 'dark-matter-brown',   // dark-matter is almost black
    ],
];
