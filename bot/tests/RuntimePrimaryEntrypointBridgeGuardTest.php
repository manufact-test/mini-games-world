<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryEntrypointBridgeGuard.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assertTrue(
    RuntimePrimaryEntrypointBridgeGuard::legacyJsonBridgeAllowed() === true,
    'Legacy JSON bridges must remain enabled when no DB-primary context class is loaded'
);

eval(<<<'PHP'
final class RuntimePrimaryEntrypointStorageContext
{
    public static bool $installed = false;
    public static function installed(): bool
    {
        return self::$installed;
    }
}
PHP);

$assertTrue(
    RuntimePrimaryEntrypointBridgeGuard::legacyJsonBridgeAllowed() === true,
    'Loaded but empty request context must preserve legacy JSON bridges'
);
RuntimePrimaryEntrypointStorageContext::$installed = true;
$assertTrue(
    RuntimePrimaryEntrypointBridgeGuard::legacyJsonBridgeAllowed() === false,
    'Installed DB-primary request context must suppress legacy JSON bridges'
);
RuntimePrimaryEntrypointStorageContext::$installed = false;
$assertTrue(
    RuntimePrimaryEntrypointBridgeGuard::legacyJsonBridgeAllowed() === true,
    'Removing request routing state must re-enable legacy JSON bridges'
);

fwrite(STDOUT, "RuntimePrimaryEntrypointBridgeGuardTest passed: {$assertions} assertions.\n");
