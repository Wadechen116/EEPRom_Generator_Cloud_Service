<?php

class Response {
    public static function json($data, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => $status >= 200 && $status < 300,
            'data'    => $data,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Sends a 200 JSON response, closes the connection to the client, and RETURNS so the
     * script can keep working. Used by api/forgot_password.php: whether a mail is sent
     * (SMTP round trip, or a timeout when the relay is blocked) must not change how long
     * the client waits, or the response time would reveal whether an account exists.
     *
     * Everything after this call is invisible to the client -- output is discarded and
     * a failure can only be logged (error_log), never reported.
     */
    public static function jsonThenContinue($data): void {
        ignore_user_abort(true);
        set_time_limit(60);

        $payload = json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Length: ' . strlen($payload));
        header('Connection: close');
        echo $payload;

        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();   // PHP-FPM / FastCGI: hands the response off immediately
        }
    }

    public static function error(string $message, int $status = 400): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => $message,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
