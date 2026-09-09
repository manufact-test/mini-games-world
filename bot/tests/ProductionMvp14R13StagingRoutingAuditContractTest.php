<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/bot/staging-routing-audit.php');
if (!is_string($source)) {
    throw new RuntimeException('Staging routing audit endpoint is missing.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($source, "['GET', 'HEAD']")
    && str_contains($source, 'http_response_code(405)')
    && str_contains($source, "header('Allow: GET, HEAD')")
    && str_contains($source, 'if ($_GET !== [])'),
    'The public routing audit must be GET/HEAD-only and reject query parameters.');

$assert(str_contains($source, "MGW_R13_ROUTING_STAGING_HOST = 'seashell-okapi-889488.hostingersite.com'")
    && str_contains($source, "MGW_R13_ROUTING_PRODUCTION_HOST = 'lemonchiffon-gerbil-545102.hostingersite.com'")
    && str_contains($source, "\$environment !== 'staging'")
    && str_contains($source, 'Live payment mode is forbidden.'),
    'The endpoint must fail closed outside the exact non-live staging environment.');

foreach (['getMe', 'getWebhookInfo', 'getChatMenuButton'] as $method) {
    $assert(str_contains($source, "api('{$method}')"), 'Missing read-only Telegram inspection method: ' . $method);
}

$assert(!str_contains($source, "api('setWebhook'")
    && !str_contains($source, "api('deleteWebhook'")
    && !str_contains($source, "api('setChatMenuButton'")
    && !str_contains($source, "api('sendMessage'"),
    'The routing audit must not mutate Telegram state or send messages.');

foreach ([
    'telegram_bot_identity_matches_staging',
    'webhook_host_is_staging',
    'webhook_path_is_expected',
    'menu_host_is_staging',
    'menu_path_is_app',
    'start_button_uses_staging_base_url',
    'cron_target_file_present',
    'cron_uses_same_staging_bootstrap',
    'cron_http_access_secret_guarded',
    'cron_code_avoids_production',
    'cron_heartbeat_integrated',
    'cron_successful_run_observed',
    'cron_heartbeat_fresh',
] as $check) {
    $assert(str_contains($source, "'{$check}'"), 'Missing routing evidence check: ' . $check);
}

$assert(str_contains($source, "MGW_R13_ROUTING_WEBHOOK_PATH = '/bot/webhook.php'")
    && str_contains($source, "MGW_R13_ROUTING_APP_PATH_PREFIX = '/app/'")
    && str_contains($source, "MGW_R13_ROUTING_CRON_PATH = '/bot/cron/weekly-match.php'"),
    'The audit must publish the exact expected staging routes.');

$assert(str_contains($source, "file_get_contents(__DIR__ . '/services/TelegramService.php')")
    && str_contains($source, "__DIR__ . '/cron/weekly-match.php'")
    && str_contains($source, "require dirname(__DIR__) . '/core/bootstrap.php'")
    && str_contains($source, "\$config['setup_secret']")
    && str_contains($source, 'hash_equals($secret, $key)'),
    'The audit must inspect the real start-button and weekly cron route contracts.');

$assert(!str_contains($source, "\$config['bot_token']")
    && !str_contains($source, "\$config['database']")
    && !str_contains(strtolower($source), 'password')
    && !str_contains(strtolower($source), 'dsn'),
    'The endpoint must not access or expose secret values or private database coordinates directly.');

$assert(str_contains($source, 'StagingCronHeartbeat::status($config)')
    && str_contains($source, "'hostinger_schedule_visibility' => 'proved_by_successful_target_execution'")
    && str_contains($source, "'hostinger_cron_task_exists_and_targets_this_staging_site' => !\$cronSuccessfulRunObserved"),
    'The report must replace manual scheduler trust with safe successful-execution proof.');

$assert(str_contains($source, "'error' => 'routing_audit_unavailable'")
    && !str_contains($source, "'message' => \$error->getMessage()"),
    'Public failures must remain generic while details stay in server logs.');

fwrite(STDOUT, "ProductionMvp14R13StagingRoutingAuditContractTest: {$assertions} assertions passed\n");
