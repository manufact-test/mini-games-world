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

    // Старые версии магазина создавали две записи на один заказ:
    // 1) реальное balance_change со списанием;
    // 2) техническую shop_order с той же датой и суммой.
    // Сопоставляем их попарно, а не через простой unique-key, чтобы два
    // настоящих одинаковых заказа в одну секунду не схлопнулись в один.
    $groups = [];
    foreach ($operations as $index => $item) {
        if (!is_array($item) || (string)($item['title'] ?? '') !== 'Заказ приза') {
            continue;
        }

        $description = (string)($item['description'] ?? '');
        $kind = null;
        if (str_starts_with($description, 'Заказ приза:')) {
            $kind = 'financial';
        } elseif (str_starts_with($description, 'Магазин призов ·')) {
            $kind = 'technical';
        }

        if ($kind === null) {
            continue;
        }

        $key = implode('|', [
            (string)($item['created_at'] ?? ''),
            (string)($item['amount'] ?? 0),
        ]);
        $groups[$key][$kind][] = $index;
    }

    $dropIndexes = [];
    foreach ($groups as $group) {
        $financial = $group['financial'] ?? [];
        $technical = $group['technical'] ?? [];
        $pairs = min(count($financial), count($technical));
        for ($i = 0; $i < $pairs; $i++) {
            $dropIndexes[$technical[$i]] = true;
        }
    }

    $normalized = [];
    foreach ($operations as $index => $item) {
        if (!isset($dropIndexes[$index])) {
            $normalized[] = $item;
        }
    }

    // Older cached Mini App clients used created_at + amount to hide the old
    // duplicate pair. Keep two genuine same-second/same-amount shop orders
    // distinct for those clients by adding response-only milliseconds.
    $seenShopKeys = [];
    foreach ($normalized as &$item) {
        if (!is_array($item) || (string)($item['title'] ?? '') !== 'Заказ приза') {
            continue;
        }

        $createdAt = (string)($item['created_at'] ?? '');
        $key = implode('|', [
            $createdAt,
            (string)($item['amount'] ?? 0),
            (string)($item['title'] ?? ''),
        ]);

        $occurrence = (int)($seenShopKeys[$key] ?? 0);
        $seenShopKeys[$key] = $occurrence + 1;

        if ($occurrence > 0
            && preg_match('/^(.*:\d{2})(Z|[+-]\d{2}:\d{2})$/', $createdAt, $matches)) {
            $milliseconds = min(999, $occurrence);
            $item['created_at'] = $matches[1] . sprintf('.%03d', $milliseconds) . $matches[2];
        }
    }
    unset($item);

    $data['history']['operations'] = $normalized;
    return $data;
}

function api_ok(array $data = []): void {
    json_response(['ok' => true] + mgw_normalize_api_data($data));
}

function api_error(string $message, int $status = 400): void {
    json_response(['ok' => false, 'error' => $message], $status);
}
