<?php

class SessionAuth {
    public static function start(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function login(string $account): void {
        self::start();
        // Prevents session fixation: old session id (possibly known to an attacker
        // before login) is discarded, a fresh one is issued now that the user is trusted.
        session_regenerate_id(true);
        $_SESSION['account'] = $account;
    }

    public static function logout(): void {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function currentAccount(): ?string {
        self::start();
        return $_SESSION['account'] ?? null;
    }

    /** Ends the request with 401 if there's no logged-in session. Returns the account on success. */
    public static function requireLogin(): string {
        $account = self::currentAccount();
        if ($account === null) {
            Response::error('Login required', 401);
        }
        return $account;
    }
}
