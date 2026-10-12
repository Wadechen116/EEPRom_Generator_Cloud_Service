<?php
/**
 * POST reset_password.php   { "token": "<64 hex chars from the emailed link>" }                      -> check
 * POST reset_password.php   { "token": "...", "password": "<new password>" }                          -> reset
 *
 * The second half of the forgot-password flow (api/forgot_password.php sends the link). Needs the API
 * key only: the one-time token is the credential.
 *
 * check  tells the page whether the link is still usable BEFORE the user types a password
 *        -> {"valid": true}, or 400 "This reset link is invalid or has expired..."
 * reset  sets the new password
 *        -> {"reset": true}. It does not log the user in; they sign in with the new password.
 *
 * A token is usable when its password_resets row exists, was neither used nor superseded (used_at IS
 * NULL), has not expired, and still belongs to an existing user. "Invalid", "expired", "already used"
 * and "superseded by a newer link" all get the SAME answer, so the endpoint says nothing about why.
 *
 * Only users.password_hash is written. The legacy plaintext `password` column is never touched --
 * writing a password back into it was explicitly rejected (see TODO.md, decision log).
 *
 * The token is claimed with a single atomic UPDATE ... WHERE used_at IS NULL inside the transaction, so
 * two simultaneous submissions of the same link cannot both succeed. A successful reset also retires every
 * other unused link of that user.
 *
 * Known limit: an already-open login SESSION of that user is not ended (PHP sessions can't be listed or
 * revoked by user). It lapses when the browser closes; see TODO.md.
 */

require __DIR__ . '/bootstrap.php';

const PASSWORD_MIN_BYTES = 8;
const PASSWORD_MAX_BYTES = 72;   // bcrypt silently ignores everything past 72 bytes -- refuse instead of truncating

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    Response::error('Invalid JSON body', 400);
}

const INVALID_LINK_MESSAGE = 'This reset link is invalid or has expired. Request a new one.';

$token = (string) ($body['token'] ?? '');
if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
    Response::error(INVALID_LINK_MESSAGE, 400);
}
$tokenHash = hash('sha256', $token);
$now = gmdate('Y-m-d H:i:s');

$find = $pdo->prepare(
    'SELECT pr.id AS reset_id, pr.user_id FROM password_resets pr '
    . 'JOIN users u ON u.id = pr.user_id '
    . 'WHERE pr.token_hash = :h AND pr.used_at IS NULL AND pr.expires_at > :now'
);
$find->execute([':h' => $tokenHash, ':now' => $now]);
$row = $find->fetch();
if (!$row) {
    Response::error(INVALID_LINK_MESSAGE, 400);
}

if (!array_key_exists('password', $body)) {
    Response::json(['valid' => true]);
}

$password = $body['password'];
if (!is_string($password) || strlen($password) < PASSWORD_MIN_BYTES) {
    Response::error('The new password must be at least ' . PASSWORD_MIN_BYTES . ' characters long.', 400);
}
if (strlen($password) > PASSWORD_MAX_BYTES) {
    Response::error('The new password is too long (at most ' . PASSWORD_MAX_BYTES . ' bytes).', 400);
}
if (trim($password) === '') {
    Response::error('The new password cannot be only spaces.', 400);
}

$userId = (int) $row['user_id'];

$pdo->beginTransaction();
try {
    $claim = $pdo->prepare('UPDATE password_resets SET used_at = :now WHERE id = :id AND used_at IS NULL');
    $claim->execute([':now' => $now, ':id' => (int) $row['reset_id']]);
    if ($claim->rowCount() !== 1) {
        // Someone used this very link between our SELECT and now.
        $pdo->rollBack();
        Response::error(INVALID_LINK_MESSAGE, 400);
    }

    $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
        ->execute([':hash' => password_hash($password, PASSWORD_DEFAULT), ':id' => $userId]);

    // Any other link this user still holds is now meaningless.
    $pdo->prepare('UPDATE password_resets SET used_at = :now WHERE user_id = :u AND used_at IS NULL')
        ->execute([':now' => $now, ':u' => $userId]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;   // the global handler logs it and answers 500
}

error_log("[reset] password changed for user id {$userId}");
Response::json(['reset' => true]);
