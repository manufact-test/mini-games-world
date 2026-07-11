<?php
declare(strict_types=1);

function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mgw_normalize_api_data(array $data): array {
    $operations = $data['history']['operations'] ?? null;
    if (!is_array($operations)) {
        return $data;
    }

    $seenShopOrders = [];
    $normalized = [];

    foreach ($operations as $item) {
        if (!is_array($item) || (string)($item['title'] ?? '') !== 'Заказ приза') {
            $normalized[] = $item;
            continue;
        }

        // A shop order currently has both a financial ledger row and a technical
        // shop_order row with the same timestamp and amount. Present it once.
        $key = implode('|', [
            (string)($item['created_at'] ?? ''),
            (string)($item['amount'] ?? 0),
            (string)($item['title'] ?? ''),
        ]);

        if (isset($seenShopOrders[$key])) {
            continue;
        }

        $seenShopOrders[$key] = true;
        $normalized[] = $item;
    }

    $data['history']['operations'] = $normalized;
    return $data;
}

function api_ok(array $data = []): void {
    json_response(['ok' => true] + mgw_normalize_api_data($data));
}

function api_error(string $message, int $status = 400): void {
    json_response(['ok' => false, 'error' => $message], $status);
}
