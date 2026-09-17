<?php
/** GET session.php -- lets the frontend check login status on page load without triggering a 401. */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib/SessionAuth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
}

$account = SessionAuth::currentAccount();
Response::json(['loggedIn' => $account !== null, 'account' => $account]);
