<?php
declare(strict_types=1);

function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_ok(array $data = []): void {
    json_response(['ok' => true] + $data);
}

function api_error(string $message, int $status = 400): void {
    json_response(['ok' => false, 'error' => $message], $status);
}
