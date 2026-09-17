<?php
/**
 * Public, UNAUTHENTICATED endpoint: hands the frontend the API key it needs to send
 * on every other request. Deliberately skips both the API-key check and the login
 * check every other endpoint requires -- it has to be reachable before the frontend
 * has a key to send at all (chicken-and-egg otherwise).
 *
 * This is a deliberate trade-off, not an oversight: the API key was never a secret
 * from a page *viewer* (it always shipped inside assets/js/app.js, visible via
 * view-source/devtools regardless). Making it fetchable here only changes who can
 * get it without loading the page at all (e.g. a bot) -- it does not expose the DB
 * credentials or the login system, which stay server-side only.
 *
 * The real win: api_key now lives in exactly one place (api/config/config.php).
 * Regenerating assets/js/app.js (e.g. when adding a feature) can never again
 * silently reset a manually-edited key back to a placeholder.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/lib/Response.php';

define('APP_ENTRY', true);

$configFile = __DIR__ . '/config/config.php';
if (!file_exists($configFile)) {
    error_log('[API] Missing api/config/config.php -- copy config.sample.php and fill in real values.');
    Response::error('Server not configured', 500);
}

$config = require $configFile;

header('Content-Type: application/json; charset=utf-8');
Response::json(['apiKey' => $config['api_key']]);
