<?php
/**
 * Included by every endpoint under api/. Handles error hygiene, config loading,
 * and API-key auth in one place so individual endpoints stay focused on their resource.
 */

// Never let a raw PHP error/stack trace (which could include DB details) reach the client.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/lib/Response.php';
require __DIR__ . '/lib/Auth.php';
require __DIR__ . '/lib/Validator.php';
require __DIR__ . '/lib/Database.php';
require __DIR__ . '/lib/SecurityHeaders.php';

set_exception_handler(function (Throwable $e) {
    // Full detail goes to the server's existing PHP error log (never to the client).
    error_log('[API] Uncaught: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    Response::error('Internal server error', 500);
});

define('APP_ENTRY', true);

$configFile = __DIR__ . '/config/config.php';
if (!file_exists($configFile)) {
    error_log('[API] Missing api/config/config.php -- copy config.sample.php and fill in real values.');
    Response::error('Server not configured', 500);
}

$config = require $configFile;

header('Content-Type: application/json; charset=utf-8');
SecurityHeaders::send($config, SecurityHeaders::API_CSP);
// These endpoints carry passwords / session cookies: with security.force_https on, refuse them over http.
SecurityHeaders::requireHttps($config, true);

Auth::requireApiKey($config['api_key']);

try {
    $pdo = Database::connection($config['db']);
} catch (PDOException $e) {
    error_log('[API] DB connection failed: ' . $e->getMessage());
    Response::error('Database unavailable', 500);
}
