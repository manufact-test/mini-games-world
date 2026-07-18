<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/core/bootstrap.php';

$exitCode = 0;

try {
    $environment = strtolower(trim((string)($config['environment'] ?? 'production')));
    if ($environment !== 'staging') {
        throw new RuntimeException('Telegram webhook repair is enabled only in staging.');
    }

    $token = trim((string)($config['bot_token'] ?? ''));
    if ($token === '' || $token === 'PASTE_BOT_TOKEN_HERE') {
        throw new RuntimeException('Telegram bot token is not configured.');
    }

    $baseUrl = rtrim(trim((string)($config['base_url'] ?? '')), '/');
    if ($baseUrl === '' || !str_starts_with($baseUrl, 'https://')) {
        throw new RuntimeException('A valid HTTPS base_url is required.');
    }

    $expectedUrl = $baseUrl . '/bot/webhook.php';
    $repair = in_array('--repair', $argv, true);
    $telegram = new TelegramService($config);

    $request = static function (TelegramService $telegram, string $method, array $params = []): array {
        $response = $telegram->api($method, $params);
        if (empty($response['ok'])) {
            $description = trim((string)($response['description'] ?? 'Telegram API request failed.'));
            throw new RuntimeException($description !== '' ? $description : 'Telegram API request failed.');
        }
        return $response;
    };

    $beforeResponse = $request($telegram, 'getWebhookInfo');
    $before = is_array($beforeResponse['result'] ?? null) ? $beforeResponse['result'] : [];

    if ($repair) {
        $request($telegram, 'setWebhook', [
            'url' => $expectedUrl,
            'allowed_updates' => ['message', 'edited_message', 'callback_query'],
            'drop_pending_updates' => false,
        ]);
    }

    $afterResponse = $request($telegram, 'getWebhookInfo');
    $after = is_array($afterResponse['result'] ?? null) ? $afterResponse['result'] : [];
    $allowedUpdates = array_values(array_map('strval', (array)($after['allowed_updates'] ?? [])));

    $urlMatches = hash_equals($expectedUrl, trim((string)($after['url'] ?? '')));
    $receivesMessages = in_array('message', $allowedUpdates, true);
    $receivesCallbacks = in_array('callback_query', $allowedUpdates, true);
    $ok = $urlMatches && $receivesMessages && $receivesCallbacks;

    $sanitize = static function (array $info): array {
        return [
            'url' => (string)($info['url'] ?? ''),
            'pending_update_count' => (int)($info['pending_update_count'] ?? 0),
            'last_error_date' => isset($info['last_error_date']) ? (int)$info['last_error_date'] : null,
            'last_error_message' => (string)($info['last_error_message'] ?? ''),
            'max_connections' => isset($info['max_connections']) ? (int)$info['max_connections'] : null,
            'allowed_updates' => array_values(array_map('strval', (array)($info['allowed_updates'] ?? []))),
        ];
    };

    $result = [
        'ok' => $ok,
        'changed' => $repair,
        'report_type' => 'mvp-14.8.3-telegram-webhook-repair',
        'environment' => $environment,
        'expected_url' => $expectedUrl,
        'before' => $sanitize($before),
        'after' => $sanitize($after),
        'url_matches' => $urlMatches,
        'receives_messages' => $receivesMessages,
        'receives_callbacks' => $receivesCallbacks,
        'pending_updates_preserved' => true,
        'sensitive_identifiers_exposed' => false,
        'execution_mode' => $repair ? 'repair' : 'read-only',
        'generated_at_utc' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
    ];
    $result['report_fingerprint'] = hash('sha256', json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ));

    fwrite(STDOUT, json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL);
    $exitCode = $ok ? 0 : 1;
} catch (Throwable $error) {
    $exitCode = 1;
    fwrite(STDERR, json_encode([
        'ok' => false,
        'changed' => false,
        'report_type' => 'mvp-14.8.3-telegram-webhook-repair',
        'sensitive_identifiers_exposed' => false,
        'error' => $error->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
}

exit($exitCode);
