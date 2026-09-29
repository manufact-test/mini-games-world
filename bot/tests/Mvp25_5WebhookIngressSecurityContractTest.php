<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;

$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};

require_once $root . '/bot/helpers/TelegramWebhookSecurity.php';

$config = ['setup_secret' => str_repeat('s', 64)];
$tokenA = TelegramWebhookSecurity::secretToken($config);
$tokenB = TelegramWebhookSecurity::secretToken($config);
$assert($tokenA === $tokenB, 'Derived webhook token must be deterministic for one private setup secret.');
$assert($tokenA !== $config['setup_secret'], 'Webhook token must not expose the raw setup secret.');
$assert(strlen($tokenA) >= 32 && strlen($tokenA) <= 256, 'Webhook token must satisfy Telegram length bounds.');
$assert(preg_match('/^[A-Za-z0-9_-]+$/', $tokenA) === 1, 'Webhook token must satisfy Telegram character bounds.');

$assert(
    TelegramWebhookSecurity::incomingAuthorized($config, ['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'=>$tokenA]),
    'Exact Telegram secret header must authorize webhook ingress.'
);
$assert(!TelegramWebhookSecurity::incomingAuthorized($config, []), 'Missing Telegram secret header must be rejected.');
$assert(
    !TelegramWebhookSecurity::incomingAuthorized($config, ['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'=>'wrong']),
    'Wrong Telegram secret header must be rejected.'
);
$assert(
    TelegramWebhookSecurity::setupBearerAuthorized($config, ['HTTP_AUTHORIZATION'=>'Bearer ' . $config['setup_secret']]),
    'Setup utilities must accept the private secret only through Authorization bearer.'
);
$assert(
    !TelegramWebhookSecurity::setupBearerAuthorized($config, ['HTTP_AUTHORIZATION'=>'Bearer wrong']),
    'Wrong setup bearer must be rejected.'
);

$override = 'Webhook_Secret_' . str_repeat('A', 32);
$assert(
    TelegramWebhookSecurity::secretToken(['setup_secret'=>str_repeat('s', 64),'telegram_webhook_secret'=>$override]) === $override,
    'Explicit private webhook secret override must remain supported.'
);

$webhook = $read('bot/webhook.php');
$diagnostic = $read('bot/webhook-diagnostic.php');
$setup = $read('bot/setup-webhook.php');
$check = $read('bot/check-webhook.php');
$staging = $read('bot/staging-webhook-security.php');
$repair = $read('ops/deploy/telegram-webhook-repair.php');
$diagnosticRoute = $read('ops/deploy/telegram-webhook-diagnostic-route.php');
$readiness = $read('bot/staging-e2e-readiness.php');
$fingerprint = $read('bot/helpers/staging-e2e-runtime-files.txt');
$stagingWorkflow = $read('.github/workflows/staging-playwright-e2e.yml');

foreach (['bot/webhook.php'=>$webhook, 'bot/webhook-diagnostic.php'=>$diagnostic] as $path=>$source) {
    $authPos = strpos($source, 'TelegramWebhookSecurity::incomingAuthorized');
    $bodyPos = strpos($source, "file_get_contents('php://input')");
    $assert($authPos !== false, $path . ' must use the canonical webhook security owner.');
    $assert($bodyPos !== false && $authPos < $bodyPos, $path . ' must authenticate before decoding or handling the update.');
}

foreach (['bot/setup-webhook.php'=>$setup, 'bot/check-webhook.php'=>$check] as $path=>$source) {
    $assert(!str_contains($source, "\$_GET['key']"), $path . ' must not read setup_secret from a URL query.');
    $assert(!str_contains($source, '?key='), $path . ' must not document a URL-carried secret.');
    $assert(str_contains($source, 'TelegramWebhookSecurity::setupBearerAuthorized'), $path . ' must use Authorization bearer.');
}

$assert(str_contains($setup, "REQUEST_METHOD") && str_contains($setup, "'POST'"), 'Webhook setup must be POST-only.');
$assert(str_contains($setup, "'secret_token' => TelegramWebhookSecurity::secretToken(\$config)"), 'Webhook setup must configure Telegram secret_token.');
$assert(str_contains($setup, "'drop_pending_updates' => false"), 'Webhook setup must preserve pending Telegram updates.');
$assert(str_contains($repair, "'secret_token' => TelegramWebhookSecurity::secretToken(\$config)"), 'CLI webhook repair must preserve secret_token.');
$assert(str_contains($diagnosticRoute, "'secret_token' => TelegramWebhookSecurity::secretToken(\$config)"), 'Diagnostic route switch must preserve secret_token.');

$assert(str_contains($staging, 'GitHubActionsOidcVerifier'), 'Staging webhook reconciler must reuse the existing GitHub Actions OIDC owner.');
$assert(str_contains($staging, "'secret_token' => TelegramWebhookSecurity::secretToken(\$config)"), 'Staging reconciler must configure secret_token.');
$assert(str_contains($staging, "'drop_pending_updates' => false"), 'Staging reconciler must not drop pending updates.');
$assert(str_contains($staging, "'secret_token_configured'=>true"), 'Staging proof must expose only a boolean secret-token status.');

$assert(str_contains($readiness, "'telegram_webhook_security_owner'"), 'Staging readiness must require the webhook security owner.');
$assert(str_contains($readiness, "'staging_webhook_security_reconciler'"), 'Staging readiness must require the OIDC reconciler.');

foreach ([
    'bot/helpers/TelegramWebhookSecurity.php',
    'bot/staging-webhook-security.php',
    'bot/webhook.php',
    'bot/webhook-diagnostic.php',
    'bot/setup-webhook.php',
    'bot/check-webhook.php',
    'ops/deploy/telegram-webhook-repair.php',
    'ops/deploy/telegram-webhook-diagnostic-route.php',
] as $path) {
    $assert(str_contains($fingerprint, $path), 'Exact staging fingerprint must include ' . $path . '.');
}

$assert(substr_count($stagingWorkflow, '/bot/staging-webhook-security.php') === 2, 'Both staging E2E routes must reconcile webhook security.');
$assert(substr_count($stagingWorkflow, 'WEBHOOK_SECURITY_OUTCOME') === 4, 'Both staging E2E classifiers must gate on webhook security.');
$assert(str_contains($stagingWorkflow, 'id-token: write'), 'Canonical staging E2E must retain GitHub OIDC permission.');

fwrite(STDOUT, "Mvp25_5WebhookIngressSecurityContractTest: {$assertions} assertions passed\n");
