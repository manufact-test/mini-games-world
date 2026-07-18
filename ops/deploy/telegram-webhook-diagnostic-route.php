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
        throw new RuntimeException('Telegram webhook diagnostic routing is enabled only in staging.');
    }

    $enable = in_array('--enable', $argv, true);
    $disable = in_array('--disable', $argv, true);
    if ($enable === $disable) {
        throw new RuntimeException('Pass exactly one mode: --enable or --disable.');
    }

    $baseUrl = rtrim(trim((string)($config['base_url'] ?? '')), '/');
    if ($baseUrl === '' || !str_starts_with($baseUrl, 'https://')) {
        throw new RuntimeException('A valid HTTPS base_url is required.');
    }

    $targetUrl = $baseUrl . ($enable ? '/bot/webhook-diagnostic.php' : '/bot/webhook.php');
    $telegram = new TelegramService($config);

    $beforeResponse = $telegram->api('getWebhookInfo');
    if (empty($beforeResponse['ok'])) {
        throw new RuntimeException((string)($beforeResponse['description'] ?? 'Could not read webhook info.'));
    }

    $setResponse = $telegram->api('setWebhook', [
        'url' => $targetUrl,
        'allowed_updates' => ['message', 'edited_message', 'callback_query'],
        'drop_pending_updates' => false,
    ]);
    if (empty($setResponse['ok'])) {
        throw new RuntimeException((string)($setResponse['description'] ?? 'Could not update webhook.'));
    }

    $afterResponse = $telegram->api('getWebhookInfo');
    if (empty($afterResponse['ok'])) {
        throw new RuntimeException((string)($afterResponse['description'] ?? 'Could not verify webhook info.'));
    }

    $before = is_array($beforeResponse['result'] ?? null) ? $beforeResponse['result'] : [];
    $after = is_array($afterResponse['result'] ?? null) ? $afterResponse['result'] : [];
    $actualUrl = trim((string)($after['url'] ?? ''));
    $ok = hash_equals($targetUrl, $actualUrl);

    $result = [
        'ok' => $ok,
        'report_type' => 'mvp-14.8.3-telegram-webhook-diagnostic-route',
        'environment' => $environment,
        'mode' => $enable ? 'diagnostic' : 'normal',
        'target_url' => $targetUrl,
        'before_url' => (string)($before['url'] ?? ''),
        'after_url' => $actualUrl,
        'pending_update_count' => (int)($after['pending_update_count'] ?? 0),
        'last_error_message' => (string)($after['last_error_message'] ?? ''),
        'pending_updates_preserved' => true,
        'sensitive_identifiers_exposed' => false,
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
        'report_type' => 'mvp-14.8.3-telegram-webhook-diagnostic-route',
        'sensitive_identifiers_exposed' => false,
        'error' => $error->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
}

exit($exitCode);
