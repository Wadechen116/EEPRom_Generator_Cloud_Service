<?php
/**
 * POST forgot_password.php   { "identifier": "<account name or email address>" }
 *
 * Starts the password-reset flow: emails the user a one-time link (see api/reset_password.php for
 * the other half). Needs the API key only -- there is no logged-in user, that is the whole point.
 *
 * It answers EVERY well-formed request with the same 200, whether or not that account exists, has
 * an email address on file, is being throttled, or the mail could not be sent. The reply is sent
 * first and the work happens after the connection is closed (Response::jsonThenContinue), so neither
 * the body nor the response time can be used to find out which account names are real -- the same
 * rule api/login.php follows for a wrong account vs. a wrong password.
 *
 * What happens after the reply, in order (each "stop" is silent to the client, logged on the server):
 *   1. mail not enabled in config.php            -> stop. Nothing is stored: a link nobody can receive is
 *                                                   only a valid credential sitting in the database.
 *   2. no user matches the name/email, or the user has no (valid) email -> stop.
 *   3. throttled: one request per user per 60 s, 5 per user per hour, 20 per client IP per hour -> stop.
 *   4. a random 32-byte token is made; ONLY its sha256 is stored (password_resets.token_hash), older
 *      unused links of that user are retired, and the raw token goes into the emailed link.
 *   5. the mail is sent; if that fails the row is deleted again (so a retry isn't throttled by a link
 *      that was never delivered) and the reason is logged. This is where the SMTP relay's
 *      "554 5.7.1 ... Client host rejected: Access denied" shows up until the mail admin allows the server.
 *
 * The link is  <app_url>/#reset=<token>.  The token sits in the URL FRAGMENT on purpose: browsers never
 * send a fragment to the server, so it stays out of Apache access logs, proxy logs and the Referer header.
 * The base comes from config ('app_url'), never from the request's Host header, which a client controls.
 *
 * Timestamps are UTC, generated and compared by PHP -- see sql/migrate_2026-10-06_password_resets.sql.
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib/Mailer.php';

const RESET_COOLDOWN_SECONDS = 60;
const RESET_MAX_PER_USER_PER_HOUR = 5;
const RESET_MAX_PER_IP_PER_HOUR = 20;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    Response::error('Invalid JSON body', 400);
}

$identifier = trim((string) ($body['identifier'] ?? ''));
if ($identifier === '') {
    Response::error('Enter your account name or email address', 400);
}
if (strlen($identifier) > 255) {
    Response::error('Invalid account name or email address', 400);
}

// The only thing a client ever learns. Wording matches the claim it makes: "if", never "has been".
Response::jsonThenContinue([
    'requested' => true,
    'message'   => 'If that account exists and has an email address on file, a reset link has been sent.',
]);

try {
    processForgotPassword($pdo, $config, $identifier);
} catch (Throwable $e) {
    error_log('[reset] forgot_password failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
}
exit;

function processForgotPassword(PDO $pdo, array $config, string $identifier): void {
    if (!Mailer::isEnabled($config)) {
        error_log('[reset] request ignored: mail is not enabled/complete in config.php (the relay has not been set up yet)');
        return;
    }

    // Exact account name wins over an email that happens to equal someone else's name.
    $stmt = $pdo->prepare(
        'SELECT id, name, email FROM users WHERE name = :by_name OR email = :by_email '
        . 'ORDER BY (name = :prefer_name) DESC LIMIT 1'
    );
    $stmt->execute([':by_name' => $identifier, ':by_email' => $identifier, ':prefer_name' => $identifier]);
    $user = $stmt->fetch();

    if (!$user || (string) $user['email'] === '' || filter_var($user['email'], FILTER_VALIDATE_EMAIL) === false) {
        error_log('[reset] request ignored: no account with a usable email address');
        return;
    }

    $userId = (int) $user['id'];
    $now = gmdate('Y-m-d H:i:s');
    $hourAgo = gmdate('Y-m-d H:i:s', time() - 3600);
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

    $perUser = $pdo->prepare('SELECT COUNT(*) AS n, MAX(created_at) AS last_at FROM password_resets WHERE user_id = :u AND created_at > :t');
    $perUser->execute([':u' => $userId, ':t' => $hourAgo]);
    $recent = $perUser->fetch();
    if ((int) $recent['n'] >= RESET_MAX_PER_USER_PER_HOUR
        || ($recent['last_at'] !== null && strtotime($recent['last_at'] . ' UTC') > time() - RESET_COOLDOWN_SECONDS)) {
        error_log("[reset] request throttled for user id {$userId}");
        return;
    }

    if ($ip !== '') {
        $perIp = $pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE requested_ip = :ip AND created_at > :t');
        $perIp->execute([':ip' => $ip, ':t' => $hourAgo]);
        if ((int) $perIp->fetchColumn() >= RESET_MAX_PER_IP_PER_HOUR) {
            error_log("[reset] request throttled for client {$ip}");
            return;
        }
    }

    $ttl = Mailer::resetTtlMinutes($config);
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);

    $pdo->beginTransaction();
    try {
        // Only the newest link may work: retire this user's older, still-unused ones.
        $pdo->prepare('UPDATE password_resets SET used_at = :now WHERE user_id = :u AND used_at IS NULL')
            ->execute([':now' => $now, ':u' => $userId]);
        $pdo->prepare(
            'INSERT INTO password_resets (user_id, token_hash, requested_ip, created_at, expires_at) '
            . 'VALUES (:u, :h, :ip, :created, :expires)'
        )->execute([
            ':u'       => $userId,
            ':h'       => $tokenHash,
            ':ip'      => $ip !== '' ? $ip : null,
            ':created' => $now,
            ':expires' => gmdate('Y-m-d H:i:s', time() + $ttl * 60),
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $link = rtrim((string) $config['app_url'], '/') . '/#reset=' . $token;
    if (!Mailer::sendPasswordReset($config, (string) $user['email'], (string) $user['name'], $link, $ttl)) {
        // Never delivered: drop the link so it is not a live credential nobody holds, and so the
        // user can ask again straight away instead of waiting out the cooldown. Reason is already logged.
        $pdo->prepare('DELETE FROM password_resets WHERE token_hash = :h')->execute([':h' => $tokenHash]);
        return;
    }
    error_log("[reset] reset link mailed for user id {$userId}");
}
