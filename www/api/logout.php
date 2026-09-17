<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib/SessionAuth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

SessionAuth::logout();
Response::json(['loggedOut' => true]);
