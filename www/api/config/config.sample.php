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
];
