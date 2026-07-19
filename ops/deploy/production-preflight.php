<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

@set_time_limit(240);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/core/bootstrap.php';
require_once $projectRoot . '/bot/cutover/ProductionPreflightService.php';
require_once $projectRoot . '/bot/cutover/ProductionPreflightRunner.php';
require_once $projectRoot . '/bot/database/ManagedMigrationController.php';
require_once $projectRoot . '/ops/backup/BackupConfigLoader.php';
require_once $projectRoot . '/ops/backup/BackupManager.php';

$options = getopt('', ['run']);
$exitCode = 0;

$safeMessage = static function (string $message): string {
    $message = preg_replace('~/(?:home|var|tmp|srv)/[^\s\'\"]+~', '[private-path]', $message) ?? $message;
    $message = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted-email]', $message) ?? $message;
    return mb_substr(trim($message), 0, 500);
};
$print = static function (array $result): void {
    fwrite(STDOUT, json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL);
};

try {
    if (!isset($options['run'])) {
        throw new InvalidArgumentException('Production preflight requires --run.');
    }

    $runner = new ProductionPreflightRunner(
        $projectRoot,
        $config,
        is_string($configFile ?? null) ? $configFile : null
    );
    $result = $runner->run();
    $print($result);
    $exitCode = ($result['ok'] ?? false) === true ? 0 : 2;
} catch (Throwable $error) {
    $print([
        'ok' => false,
        'report_type' => 'mvp-14.8.5-production-preflight',
        'execution_mode' => 'read-only',
        'production_switch_allowed' => false,
        'production_switch_performed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
        'error_class' => get_class($error),
        'error_message' => $safeMessage($error->getMessage()),
    ]);
    $exitCode = 1;
}

exit($exitCode);
