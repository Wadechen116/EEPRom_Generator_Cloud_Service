<?php
/**
 * Stateless per-request authentication -- an alternative to the browser's session-based
 * login (api/login.php + SessionAuth) for non-browser callers (other frontends, scripts,
 * desktop tools) that want to authenticate on every request instead of managing a cookie.
 *
 * Two ways a client can send credentials (either works; try the second if the first
 * doesn't on your host -- see below):
 *   1. Standard Basic Auth:  Authorization: Basic base64(account:password)
 *      (curl: `curl -u account:password ...` sets this automatically)
 *   2. Plain custom headers:  X-Account: <account>   X-Password: <password>
 *
 * CONFIRMED on this deployment (pend.soinc.com.tw/chamonix): this host's CGI/FastCGI
 * setup strips the Authorization header before PHP ever sees it (checked PHP_AUTH_USER,
 * HTTP_AUTHORIZATION, REDIRECT_HTTP_AUTHORIZATION -- all absent), with no server-config
 * access available to fix that at the source. Option 2 (X-Account/X-Password) uses
 * ordinary headers, the same kind as the X-API-Key this app already relies on, which
 * are NOT subject to that stripping -- use option 2 here.
 *
 * IMPORTANT either way: this sends the real password on every single request (Basic
 * Auth base64-ENCODES it, which is not encryption). Only safe over HTTPS.
 */

class CredentialAuth {
    /** Returns the account name if the request's Basic Auth credentials check out, else null. */
    public static function verify(PDO $pdo): ?string {
        $creds = self::extractCredentials();
        if ($creds === null) {
            return null;
        }
        [$account, $password] = $creds;
        if ($account === '' || $password === '') {
            return null;
        }

        $stmt = $pdo->prepare('SELECT name, password_hash FROM users WHERE name = :name');
        $stmt->execute([':name' => $account]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return null;
        }
        return $user['name'];
    }

    /**
     * PHP_AUTH_USER/PHP_AUTH_PW are populated automatically under mod_php. Under
     * CGI/FastCGI (common on shared hosting) they're often missing even though the
     * client sent the header -- HTTP_AUTHORIZATION / REDIRECT_HTTP_AUTHORIZATION are
     * the fallbacks various server configs actually expose it under. Checking all
     * three needs no server config changes; if a given host strips the header with
     * no fallback available at all, there is no PHP-side workaround for that.
     */
    private static function extractCredentials(): ?array {
        if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
            return [$_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']];
        }

        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if ($header !== null && stripos($header, 'Basic ') === 0) {
            $decoded = base64_decode(substr($header, 6), true);
            if ($decoded !== false && strpos($decoded, ':') !== false) {
                return explode(':', $decoded, 2);
            }
        }

        // Fallback for hosts (confirmed: this deployment) that strip Authorization
        // before PHP ever sees it, with no fallback available for it at all. Plain
        // X-* headers aren't subject to that -- same mechanism as this app's X-API-Key.
        if (isset($_SERVER['HTTP_X_ACCOUNT'], $_SERVER['HTTP_X_PASSWORD'])) {
            return [$_SERVER['HTTP_X_ACCOUNT'], $_SERVER['HTTP_X_PASSWORD']];
        }

        return null;
    }
}
