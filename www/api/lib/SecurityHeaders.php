<?php
/**
 * Security headers for every PHP response, and the HTTPS rule for pages and APIs that handle passwords.
 *
 * Static files (index.html, JS, CSS) get the same headers from the root .htaccess when the server honours it;
 * PHP sends them itself so they are there either way for everything PHP produces.
 *
 * config.php 'security' (all optional, see config.sample.php):
 *   force_https     false  true = page requests over http are redirected to https, API calls over http are refused.
 *                          Turn it on only after the monitor page footer shows "HTTPS：是" -- if PHP cannot see
 *                          HTTPS (TLS ended by a proxy in front), turning it on would loop / refuse everything.
 *   hsts_max_age    0      > 0 sends Strict-Transport-Security (browsers then always use https for this HOST --
 *                          the whole pend.soinc.com.tw, not only /chamonix). Start small (300), then 31536000.
 *   trusted_proxies []     REMOTE_ADDR of a TLS-terminating proxy whose X-Forwarded-Proto may be believed.
 */

class SecurityHeaders {
    /** CSP for JSON / text API responses: they never load anything and must never be framed. */
    public const API_CSP = "default-src 'none'; frame-ancestors 'none'";

    public static function isHttps(array $config = []): bool {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        $proxies = $config['security']['trusted_proxies'] ?? [];
        return in_array($_SERVER['REMOTE_ADDR'] ?? '', $proxies, true)
            && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public static function send(array $config, string $csp): void {
        if (headers_sent()) {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Content-Security-Policy: ' . $csp);
        $hsts = (int) ($config['security']['hsts_max_age'] ?? 0);
        if ($hsts > 0 && self::isHttps($config)) {
            header('Strict-Transport-Security: max-age=' . $hsts);
        }
    }

    /**
     * With security.force_https on: a GET/HEAD over http is redirected to the same path over https; anything else
     * (a POST carrying a password) is refused with 403 instead of being accepted in clear text.
     * The redirect target is built from config 'app_url', never from the request's Host header.
     */
    public static function requireHttps(array $config, bool $json): void {
        if (empty($config['security']['force_https']) || self::isHttps($config)) {
            return;
        }
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $app = parse_url((string) ($config['app_url'] ?? ''));
        if (($method === 'GET' || $method === 'HEAD') && !empty($app['host'])) {
            $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
            header('Location: https://' . $app['host'] . (isset($app['port']) ? ':' . $app['port'] : '') . $uri, true, 301);
            exit;
        }
        http_response_code(403);
        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'HTTPS required'], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo "HTTPS required.\n";
        }
        exit;
    }
}
