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

/**
 * Why a link was refused, for the SERVER LOG only. The browser gets INVALID_LINK_MESSAGE for every one of these
 * (so the endpoint says nothing about accounts or links); the operator needs the real reason, or "the link does
 * not work" cannot be told apart from "expired" or "the mail program altered the address".
 * Never includes the token: for a malformed one only its length and what follows the first 64 characters.
 *
 * @param string     $token raw value received
 * @param array|null $row   password_resets row for sha256($token) with used_at, expires_at, user_exists, or null
 */
function describeRejectedLink(string $token, ?array $row, string $now): string {
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        $why = 'the token is not 64 lowercase hex characters (received ' . strlen($token) . ' characters';
        if (strlen($token) > 64 && preg_match('/^[0-9a-f]{64}/', $token)) {
            $why .= '; the first 64 are fine and are followed by ' . json_encode(substr($token, 64), JSON_UNESCAPED_SLASHES)
                . ' -- a mail program or link scanner appended it';
        } elseif (strlen($token) > 0 && strlen($token) < 64) {
            $why .= '; too short -- the address was cut off';
        } elseif (strlen($token) === 0) {
            $why .= '; empty';
        }
        return $why . ')';
    }
    if ($row === null) {
        return 'no such link: this token is not in password_resets (the address was altered, or it is from an older database)';
    }
    if ($row['used_at'] !== null) {
        return 'link already used or replaced by a newer one (used_at ' . $row['used_at'] . ' UTC)';
    }
    if ($row['expires_at'] <= $now) {
        return 'link expired (expires_at ' . $row['expires_at'] . ' UTC, now ' . $now . ' UTC)';
    }
    if (!$row['user_exists']) {
        return 'the account this link was issued for no longer exists';
    }
    return 'UNEXPECTED: the row looks valid but was not accepted';
}

$token = (string) ($body['token'] ?? '');
$now = gmdate('Y-m-d H:i:s');
if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
    error_log('[reset] link rejected: ' . describeRejectedLink($token, null, $now));
    Response::error(INVALID_LINK_MESSAGE, 400);
}
$tokenHash = hash('sha256', $token);

$find = $pdo->prepare(
    'SELECT pr.id AS reset_id, pr.user_id FROM password_resets pr '
    . 'JOIN users u ON u.id = pr.user_id '
    . 'WHERE pr.token_hash = :h AND pr.used_at IS NULL AND pr.expires_at > :now'
);
$find->execute([':h' => $tokenHash, ':now' => $now]);
$row = $find->fetch();
if (!$row) {
    // Same answer to the browser as always; the real reason goes to the log (never the token itself, only
    // the first 8 hex digits of its hash, which is what you look for in password_resets.token_hash).
    try {
        $why = $pdo->prepare(
            'SELECT pr.used_at, pr.expires_at, (u.id IS NOT NULL) AS user_exists FROM password_resets pr '
            . 'LEFT JOIN users u ON u.id = pr.user_id WHERE pr.token_hash = :h'
        );
        $why->execute([':h' => $tokenHash]);
        $found = $why->fetch();
        error_log('[reset] link rejected (token hash ' . substr($tokenHash, 0, 8) . '...): '
            . describeRejectedLink($token, $found ?: null, $now));
    } catch (Throwable $e) {
        error_log('[reset] link rejected; could not work out why: ' . $e->getMessage());
    }
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
