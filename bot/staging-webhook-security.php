<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok'=>false,'error'=>'method_not_allowed'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}
if ($_GET !== []) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'query_not_allowed'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

try {
    require __DIR__ . '/core/bootstrap.php';
    require_once __DIR__ . '/helpers/TelegramWebhookSecurity.php';
    require_once __DIR__ . '/services/GitHubActionsOidcVerifier.php';

    $environment = strtolower(trim((string)($config['environment'] ?? '')));
    $baseUrl = rtrim(trim((string)($config['base_url'] ?? '')), '/');
    $baseScheme = strtolower((string)(parse_url($baseUrl, PHP_URL_SCHEME) ?: ''));
    $baseHost = strtolower((string)(parse_url($baseUrl, PHP_URL_HOST) ?: ''));
    $requestHost = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    if (str_contains($requestHost, ':')) {
        $requestHost = explode(':', $requestHost, 2)[0];
    }
    if ($environment !== 'staging'
        || $baseScheme !== 'https'
        || $baseHost === ''
        || $requestHost !== $baseHost) {
        throw new RuntimeException('Staging webhook security reconciliation is unavailable.');
    }

    $authorization = trim((string)(
        $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? ''
    ));
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $match) !== 1) {
        throw new RuntimeException('GitHub Actions OIDC authorization is required.');
    }
    (new GitHubActionsOidcVerifier($config))->verifyAndConsume(trim((string)$match[1]));

    $raw = file_get_contents('php://input');
    if (!is_string($raw) || strlen($raw) > 1024) {
        throw new RuntimeException('Invalid webhook security request.');
    }
    $payload = json_decode($raw !== '' ? $raw : '{}', true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || $payload !== []) {
        throw new RuntimeException('Webhook security request must be empty.');
    }

    $telegram = new TelegramService($config);
    $webhookUrl = $baseUrl . '/bot/webhook.php';
    $set = $telegram->api('setWebhook', [
        'url' => $webhookUrl,
        'secret_token' => TelegramWebhookSecurity::secretToken($config),
        'allowed_updates' => ['message', 'edited_message', 'callback_query'],
        'drop_pending_updates' => false,
    ]);
    if (($set['ok'] ?? false) !== true) {
        throw new RuntimeException('Telegram webhook security reconciliation failed.');
    }

    $infoResponse = $telegram->api('getWebhookInfo');
    $info = is_array($infoResponse['result'] ?? null) ? $infoResponse['result'] : [];
    $allowedUpdates = array_values(array_map('strval', (array)($info['allowed_updates'] ?? [])));
    $urlMatches = hash_equals($webhookUrl, trim((string)($info['url'] ?? '')));
    $receivesMessages = in_array('message', $allowedUpdates, true);
    $receivesEditedMessages = in_array('edited_message', $allowedUpdates, true);
    $receivesCallbacks = in_array('callback_query', $allowedUpdates, true);
    if (!$urlMatches || !$receivesMessages || !$receivesEditedMessages || !$receivesCallbacks) {
        throw new RuntimeException('Telegram webhook post-reconcile verification failed.');
    }

    echo json_encode([
        'ok'=>true,
        'service'=>'mini-games-world-staging-webhook-security',
        'environment'=>'staging',
        'webhook_url'=>$webhookUrl,
        'secret_token_configured'=>true,
        'pending_updates_preserved'=>true,
        'allowed_updates'=>$allowedUpdates,
        'server_time_utc'=>gmdate('c'),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    error_log('[MiniGamesWorld staging webhook security] denied: ' . get_class($error) . ': ' . $error->getMessage());
    http_response_code(403);
    echo json_encode([
        'ok'=>false,
        'service'=>'mini-games-world-staging-webhook-security',
        'error'=>'webhook_security_reconcile_unavailable',
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
