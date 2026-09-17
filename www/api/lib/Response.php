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
