<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    if ($method !== 'HEAD') {
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.'], JSON_THROW_ON_ERROR);
    }
    exit;
}

if ($_GET !== []) {
    http_response_code(400);
    if ($method !== 'HEAD') {
        echo json_encode(['ok' => false, 'error' => 'Invalid request.'], JSON_THROW_ON_ERROR);
    }
    exit;
}

const MGW_R13_ROUTING_STAGING_HOST = 'seashell-okapi-889488.hostingersite.com';
const MGW_R13_ROUTING_PRODUCTION_HOST = 'lemonchiffon-gerbil-545102.hostingersite.com';
const MGW_R13_ROUTING_WEBHOOK_PATH = '/bot/webhook.php';
const MGW_R13_ROUTING_APP_PATH_PREFIX = '/app/';
const MGW_R13_ROUTING_CRON_PATH = '/bot/cron/weekly-match.php';

$stage = 'bootstrap';

try {
    require_once __DIR__ . '/core/bootstrap.php';

    $stage = 'validate_environment';
    $environment = strtolower(trim((string)($config['environment'] ?? '')));
    $baseUrl = rtrim((string)($config['base_url'] ?? ''), '/');
    $baseHost = strtolower((string)(parse_url($baseUrl, PHP_URL_HOST) ?: ''));
    $allowedHosts = array_values(array_unique(array_filter(array_map(
        static fn(mixed $value): string => strtolower(trim((string)$value)),
        is_array($config['allowed_hosts'] ?? null) ? $config['allowed_hosts'] : []
    ))));

    if ($environment !== 'staging'
        || $baseHost !== MGW_R13_ROUTING_STAGING_HOST
        || !in_array(MGW_R13_ROUTING_STAGING_HOST, $allowedHosts, true)
        || in_array(MGW_R13_ROUTING_PRODUCTION_HOST, $allowedHosts, true)) {
        throw new RuntimeException('Routing audit is unavailable outside isolated staging.');
    }
    if (!empty($config['external_payments_enabled'])
        || strtolower(trim((string)($config['payment_mode'] ?? ''))) === 'live') {
        throw new RuntimeException('Live payment mode is forbidden.');
    }

    $stage = 'telegram_read_only';
    $telegram = new TelegramService($config);
    $bot = $telegram->api('getMe');
    $webhook = $telegram->api('getWebhookInfo');
    $menu = $telegram->api('getChatMenuButton');

    $expectedUsername = strtolower(ltrim(trim((string)($config['staging_bot_username'] ?? '')), '@'));
    $actualUsername = strtolower(ltrim(trim((string)($bot['result']['username'] ?? '')), '@'));
    $botIdentityMatches = ($bot['ok'] ?? false) === true
        && ($bot['result']['is_bot'] ?? false) === true
        && $expectedUsername !== ''
        && hash_equals($expectedUsername, $actualUsername);

    $webhookUrl = trim((string)($webhook['result']['url'] ?? ''));
    $webhookHost = strtolower((string)(parse_url($webhookUrl, PHP_URL_HOST) ?: ''));
    $webhookPath = (string)(parse_url($webhookUrl, PHP_URL_PATH) ?: '');
    $webhookConfigured = ($webhook['ok'] ?? false) === true && $webhookUrl !== '';
    $webhookHostMatches = $webhookConfigured && $webhookHost === MGW_R13_ROUTING_STAGING_HOST;
    $webhookPathMatches = $webhookConfigured && $webhookPath === MGW_R13_ROUTING_WEBHOOK_PATH;
    $webhookAvoidsProduction = $webhookHost !== MGW_R13_ROUTING_PRODUCTION_HOST;

    $menuResult = is_array($menu['result'] ?? null) ? $menu['result'] : [];
    $menuType = strtolower(trim((string)($menuResult['type'] ?? '')));
    $menuUrl = trim((string)($menuResult['web_app']['url'] ?? ''));
    $menuHost = strtolower((string)(parse_url($menuUrl, PHP_URL_HOST) ?: ''));
    $menuPath = (string)(parse_url($menuUrl, PHP_URL_PATH) ?: '');
    $menuWebAppConfigured = ($menu['ok'] ?? false) === true && $menuType === 'web_app' && $menuUrl !== '';
    $menuHostMatches = $menuWebAppConfigured && $menuHost === MGW_R13_ROUTING_STAGING_HOST;
    $menuPathMatches = $menuWebAppConfigured && str_starts_with($menuPath, MGW_R13_ROUTING_APP_PATH_PREFIX);
    $menuAvoidsProduction = $menuHost !== MGW_R13_ROUTING_PRODUCTION_HOST;

    $stage = 'inspect_code_routes';
    $telegramServiceSource = file_get_contents(__DIR__ . '/services/TelegramService.php');
    $cronFile = __DIR__ . '/cron/weekly-match.php';
    $cronSource = is_file($cronFile) ? file_get_contents($cronFile) : false;

    $startButtonUsesBaseUrl = is_string($telegramServiceSource)
        && str_contains($telegramServiceSource, "rtrim((string)\$this->config['base_url'], '/')")
        && str_contains($telegramServiceSource, "'/app/?v=85'");
    $startButtonAvoidsProductionLiteral = is_string($telegramServiceSource)
        && !str_contains(strtolower($telegramServiceSource), MGW_R13_ROUTING_PRODUCTION_HOST);

    $cronFilePresent = is_string($cronSource);
    $cronUsesSameBootstrap = $cronFilePresent
        && str_contains($cronSource, "require dirname(__DIR__) . '/core/bootstrap.php'");
    $cronHttpSecretGuarded = $cronFilePresent
        && str_contains($cronSource, "\$config['setup_secret']")
        && str_contains($cronSource, 'hash_equals($secret, $key)');
    $cronAvoidsProductionLiteral = $cronFilePresent
        && !str_contains(strtolower($cronSource), MGW_R13_ROUTING_PRODUCTION_HOST);
    $cronTargetHostFromThisDeployment = $baseHost === MGW_R13_ROUTING_STAGING_HOST;

    $checks = [
        'isolated_staging_environment' => true,
        'telegram_bot_identity_matches_staging' => $botIdentityMatches,
        'webhook_configured' => $webhookConfigured,
        'webhook_host_is_staging' => $webhookHostMatches,
        'webhook_path_is_expected' => $webhookPathMatches,
        'webhook_avoids_production' => $webhookAvoidsProduction,
        'menu_web_app_configured' => $menuWebAppConfigured,
        'menu_host_is_staging' => $menuHostMatches,
        'menu_path_is_app' => $menuPathMatches,
        'menu_avoids_production' => $menuAvoidsProduction,
        'start_button_uses_staging_base_url' => $startButtonUsesBaseUrl && $startButtonAvoidsProductionLiteral,
        'cron_target_file_present' => $cronFilePresent,
        'cron_uses_same_staging_bootstrap' => $cronUsesSameBootstrap,
        'cron_http_access_secret_guarded' => $cronHttpSecretGuarded,
        'cron_code_avoids_production' => $cronAvoidsProductionLiteral,
        'cron_target_host_from_staging_deployment' => $cronTargetHostFromThisDeployment,
    ];

    $report = [
        'ok' => !in_array(false, $checks, true),
        'service' => 'mini-games-world-staging-routing-audit',
        'environment' => 'staging',
        'base_host' => MGW_R13_ROUTING_STAGING_HOST,
        'server_time_utc' => gmdate('c'),
        'telegram' => [
            'bot_identity_matches_staging' => $botIdentityMatches,
            'webhook' => [
                'configured' => $webhookConfigured,
                'host' => $webhookHost,
                'path' => $webhookPath,
                'pending_update_count' => max(0, (int)($webhook['result']['pending_update_count'] ?? 0)),
                'last_error_present' => isset($webhook['result']['last_error_date']),
            ],
            'menu_button' => [
                'type' => $menuType !== '' ? $menuType : 'unknown',
                'web_app_configured' => $menuWebAppConfigured,
                'host' => $menuHost,
                'path' => $menuPath,
            ],
        ],
        'application_routes' => [
            'expected_webhook_path' => MGW_R13_ROUTING_WEBHOOK_PATH,
            'expected_app_path_prefix' => MGW_R13_ROUTING_APP_PATH_PREFIX,
            'start_button_uses_base_url' => $startButtonUsesBaseUrl,
        ],
        'cron' => [
            'expected_target_path' => MGW_R13_ROUTING_CRON_PATH,
            'target_file_present' => $cronFilePresent,
            'uses_same_staging_bootstrap' => $cronUsesSameBootstrap,
            'http_access_secret_guarded' => $cronHttpSecretGuarded,
            'production_host_literal_absent' => $cronAvoidsProductionLiteral,
            'hostinger_schedule_visibility' => 'not_available_to_application',
        ],
        'checks' => $checks,
        'manual_check_remaining' => [
            'hostinger_cron_task_exists_and_targets_this_staging_site' => true,
        ],
    ];

    http_response_code($report['ok'] ? 200 : 409);
    if ($method !== 'HEAD') {
        echo json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
} catch (Throwable $error) {
    error_log('MGW staging routing audit failure at ' . $stage . ': ' . $error->getMessage());
    http_response_code(404);
    if ($method !== 'HEAD') {
        echo json_encode([
            'ok' => false,
            'service' => 'mini-games-world-staging-routing-audit',
            'stage' => $stage,
            'error' => 'routing_audit_unavailable',
            'server_time_utc' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
