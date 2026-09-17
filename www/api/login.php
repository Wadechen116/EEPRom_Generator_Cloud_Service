<?php
/**
 * POST login.php  { "account": "...", "password": "..." }
 * On success, starts a PHP session (cookie) the browser will send on subsequent
 * requests; api/eeprom_config.php requires this session via SessionAuth::requireLogin().
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib/SessionAuth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    Response::error('Invalid JSON body', 400);
}

$account = trim((string) ($body['account'] ?? ''));
$password = (string) ($body['password'] ?? '');

if ($account === '' || $password === '') {
    Response::error('account and password are required', 400);
}

// `users.name` is the account/login-name column in the actual remote table
// (aliased to "account" here so the rest of this file / the JSON API contract
// doesn't need to know about that naming choice).
$stmt = $pdo->prepare('SELECT name AS account, password_hash FROM users WHERE name = :account');
$stmt->execute([':account' => $account]);
$user = $stmt->fetch();

// Same generic error whether the account doesn't exist or the password is wrong --
// never reveal which one it was.
if (!$user || !password_verify($password, $user['password_hash'])) {
    Response::error('Invalid account or password', 401);
}

SessionAuth::login($user['account']);
Response::json(['account' => $user['account']]);
