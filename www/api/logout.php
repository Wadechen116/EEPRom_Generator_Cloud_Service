<?php
/**
 * POST logout.php -- clears the session and stamps users.last_logout_at (see
 * sql/schema.sql). The account has to be read before SessionAuth::logout()
 * clears the session data, or there'd be nothing left to stamp the row with.
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib/SessionAuth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$account = SessionAuth::currentAccount();

SessionAuth::logout();

if ($account !== null) {
    $pdo->prepare('UPDATE users SET last_logout_at = NOW() WHERE name = :account')
        ->execute([':account' => $account]);
}

Response::json(['loggedOut' => true]);
