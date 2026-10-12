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
    // Public address of THIS site, as users reach it, with any sub-path and no trailing slash. The reset
    // link in the email is built from it. It is configured, not read from the request, because the Host
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
];
