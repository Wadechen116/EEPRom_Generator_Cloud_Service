<?php

class Auth {
    /** Ends the request with 401 if the X-API-Key header doesn't match the configured key. */
    public static function requireApiKey(string $expectedKey): void {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $provided = $headers['X-API-Key']
            ?? $headers['X-Api-Key']
            ?? ($_SERVER['HTTP_X_API_KEY'] ?? '');

        if ($provided === '' || !hash_equals($expectedKey, $provided)) {
            Response::error('Unauthorized', 401);
        }
    }
}
