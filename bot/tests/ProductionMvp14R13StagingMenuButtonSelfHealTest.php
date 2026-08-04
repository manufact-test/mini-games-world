<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$reconciler = file_get_contents($root . '/bot/helpers/StagingMenuButtonReconciler.php');
$webhook = file_get_contents($root . '/bot/webhook.php');
if (!is_string($reconciler) || !is_string($webhook)) {
    throw new RuntimeException('Staging menu button self-heal sources are unavailable.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($reconciler, "(\$this->config['environment'] ?? '') !== 'staging'")
    && str_contains($reconciler, "STAGING_HOST = 'seashell-okapi-889488.hostingersite.com'"),
    'Menu button reconciliation must be restricted to the exact staging environment and host.');

$assert(str_contains($reconciler, "\$scheme !== 'https'")
    && str_contains($reconciler, "\$host !== self::STAGING_HOST"),
    'The staging Mini App URL must require HTTPS and the protected staging host.');

$assert(str_contains($reconciler, "\$webAppUrl = \$baseUrl . '/app/'")
    && str_contains($reconciler, "'type' => 'web_app'")
    && str_contains($reconciler, "'text' => 'Открыть игру'")
    && str_contains($reconciler, "'web_app' => ['url' => \$webAppUrl]"),
    'The Telegram menu button must point only to the staging app route.');

$assert(str_contains($reconciler, "api('getChatMenuButton'")
    && str_contains($reconciler, "api('setChatMenuButton'")
    && !str_contains($reconciler, "api('setWebhook'")
    && !str_contains($reconciler, "api('deleteWebhook'")
    && !str_contains($reconciler, "api('sendMessage'"),
    'The reconciler may inspect and repair only the deterministic menu button setting.');

$assert(str_contains($reconciler, "(\$button['type'] ?? null) === 'web_app'")
    && str_contains($reconciler, "(string)(\$button['web_app']['url'] ?? '') === \$webAppUrl"),
    'A cached success must be based on the live Telegram menu button type and exact staging URL.');

$assert(str_contains($reconciler, "MARKER_PREFIX = '.staging-menu-button-v2-'")
    && str_contains($reconciler, 'MARKER_TTL_SECONDS = 3600')
    && str_contains($reconciler, 'markerIsFresh($markerFile)'),
    'The old blind marker must be invalidated and future verification must be bounded.');

$assert(str_contains($reconciler, "Telegram did not persist the staging Mini App menu button.")
    && strpos($reconciler, "Telegram did not persist the staging Mini App menu button.")
        < strpos($reconciler, '$this->writeMarker($markerFile, $identity);', strpos($reconciler, "Telegram did not persist")),
    'The repair must be re-read from Telegram before publishing a success marker.');

$assert(str_contains($reconciler, "staging_bot_username")
    && str_contains($reconciler, "hash('sha256', \$expectedUsername . \"\\n\" . \$webAppUrl)"),
    'The idempotency marker must be bound to the expected staging bot and app URL.');

$assert(str_contains($reconciler, 'file_put_contents($temporary, $identity . PHP_EOL, LOCK_EX)')
    && str_contains($reconciler, 'rename($temporary, $markerFile)')
    && str_contains($reconciler, 'chmod($markerFile, 0600)'),
    'Successful reconciliation must be cached atomically with private file permissions.');

$assert(!str_contains($reconciler, 'lemonchiffon-gerbil-545102.hostingersite.com')
    && !str_contains($reconciler, 'production'),
    'The staging reconciler must not contain a production route or production execution branch.');

$assert(str_contains($webhook, "require_once __DIR__ . '/helpers/StagingMenuButtonReconciler.php'")
    && str_contains($webhook, 'new StagingMenuButtonReconciler($telegram, $config)')
    && str_contains($webhook, '->reconcile()'),
    'The staging menu button reconciler must run from a real Telegram webhook update.');

$assert(str_contains($webhook, 'catch (Throwable $menuButtonError)')
    && str_contains($webhook, '[MiniGamesWorld staging menu button]'),
    'A Telegram menu button failure must be logged without breaking normal webhook handling.');

$assert(strpos($webhook, 'new StagingMenuButtonReconciler') < strpos($webhook, 'new MaintenanceWebhookGuard'),
    'Menu reconciliation must happen before normal update guards process the incoming update.');

fwrite(STDOUT, "ProductionMvp14R13StagingMenuButtonSelfHealTest: {$assertions} assertions passed\n");
