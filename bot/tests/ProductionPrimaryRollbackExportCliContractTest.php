<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$cliPath = $projectRoot . '/ops/runtime/run-production-primary-rollback-export.php';
$bootstrapPath = $projectRoot . '/bot/runtime/ProductionPrimaryRollbackExportBootstrap.php';
$cli = file_get_contents($cliPath);
$bootstrap = file_get_contents($bootstrapPath);
if (!is_string($cli) || !is_string($bootstrap)) {
    throw new RuntimeException('Rollback export CLI sources are unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$run = static function (array $arguments) use ($cliPath): array {
    $command = array_merge([PHP_BINARY, $cliPath], $arguments);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Rollback CLI subprocess could not start.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    return [
        'exit' => $exit,
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
};

foreach ([
    "if (PHP_SAPI !== 'cli')",
    "--config=",
    "--cutover-state=",
    "--authorization=",
    "--output-root=",
    "--request-id=",
    "--confirm=",
    "EXPORT_DB_PRIMARY_TO_JSON_ROLLBACK",
    "ProductionPrimaryRollbackExportInputLoader",
    "ProductionPrimaryRollbackExportGate",
    "PdoConnectionFactory::create",
    "ProductionPrimaryRollbackAuditorFactory",
    "ProductionPrimaryRollbackExportService",
    "DATABASE_WRITE_EXECUTED=false",
    "LIVE_JSON_CHANGED=false",
    "PERSISTENT_CONFIG_CHANGED=false",
    "WEBHOOK_CHANGED=false",
    "CRON_CHANGED=false",
    "PRODUCTION_CHANGED=false",
] as $required) {
    $assertTrue(
        str_contains($cli, $required),
        'Rollback export CLI is missing required contract text: ' . $required
    );
}

foreach ([
    'BackupManager::restore(',
    'ProductionPrimaryRollbackRestoreService',
    'JsonDatabase',
    'JsonStorageAdapter',
    'file_put_contents($config',
    'runtime.php',
    'production-cutover.php',
    'setWebhook',
    'deleteWebhook',
    'crontab',
    'curl_',
] as $forbidden) {
    $assertTrue(
        !str_contains($cli, $forbidden),
        'Rollback export CLI must not contain live restore or control-plane mutation: '
            . $forbidden
    );
}

$requiredBootstrapFiles = [
    'DatabaseConnectionInterface.php',
    'DatabaseConfig.php',
    'PdoDatabaseConnection.php',
    'PdoConnectionFactory.php',
    'RuntimeStorageRouter.php',
    'RuntimePrimaryProjectionAuditorInterface.php',
    'RuntimePrimaryRepositoryProjectorFactory.php',
    'ProductionPrimaryRollbackExportGate.php',
    'ProductionPrimaryRollbackExportVerifier.php',
    'ProductionPrimaryRollbackExportService.php',
    'ProductionPrimaryRollbackAuditorFactory.php',
    'ProductionPrimaryRollbackExportInputLoader.php',
];
foreach ($requiredBootstrapFiles as $file) {
    $assertTrue(
        substr_count($bootstrap, $file) === 1,
        'Rollback bootstrap must load dependency exactly once: ' . $file
    );
}
$assertTrue(
    !str_contains($bootstrap, 'core/bootstrap.php')
        && !str_contains($bootstrap, 'WebhookHandler.php')
        && !str_contains($bootstrap, 'TelegramService.php')
        && !str_contains($bootstrap, 'JsonDatabase.php'),
    'Rollback bootstrap must not load application, Telegram or JSON runtime side effects'
);

$missing = $run([]);
$assertTrue($missing['exit'] === 2, 'CLI without explicit options must exit with usage error');
$assertTrue(
    str_contains($missing['stderr'], 'requires every explicit option'),
    'CLI without options must explain the explicit-option requirement'
);
$assertTrue($missing['stdout'] === '', 'Usage failure must not write success output');

$unknown = $run(['--unknown=value']);
$assertTrue($unknown['exit'] === 2, 'Unknown option must exit with usage error');
$assertTrue(
    str_contains($unknown['stderr'], 'Unknown rollback export argument'),
    'Unknown option must be rejected explicitly'
);

$duplicate = $run([
    '--config=/tmp/config.php',
    '--config=/tmp/config.php',
]);
$assertTrue($duplicate['exit'] === 2, 'Duplicate option must exit with usage error');
$assertTrue(
    str_contains($duplicate['stderr'], 'may be specified only once'),
    'Duplicate option must be rejected explicitly'
);

$badPath = $run([
    '--config=C:\\private\\config.php',
    '--cutover-state=/tmp/production-cutover.json',
    '--authorization=/tmp/production-rollback-export-authorization.json',
    '--output-root=/tmp/rollback',
    '--request-id=' . str_repeat('a', 32),
    '--confirm=EXPORT_DB_PRIMARY_TO_JSON_ROLLBACK',
]);
$assertTrue($badPath['exit'] === 2, 'Backslash path must exit with usage error');
$assertTrue(
    str_contains($badPath['stderr'], 'exact absolute Linux paths'),
    'Backslash path must be rejected before any filesystem or DB contact'
);

$badConfirm = $run([
    '--config=/tmp/config.php',
    '--cutover-state=/tmp/production-cutover.json',
    '--authorization=/tmp/production-rollback-export-authorization.json',
    '--output-root=/tmp/rollback',
    '--request-id=' . str_repeat('a', 32),
    '--confirm=WRONG',
]);
$assertTrue($badConfirm['exit'] === 2, 'Wrong confirmation must exit with usage error');
$assertTrue(
    str_contains($badConfirm['stderr'], 'confirmation phrase is invalid'),
    'Wrong confirmation must be rejected before input loading'
);

require_once $bootstrapPath;
foreach ([
    'ProductionPrimaryRollbackExportGate',
    'ProductionPrimaryRollbackExportVerifier',
    'ProductionPrimaryRollbackExportService',
    'ProductionPrimaryRollbackAuditorFactory',
    'ProductionPrimaryRollbackExportInputLoader',
    'RuntimePrimaryRepositoryProjectorFactory',
    'RuntimePrimaryProjectionAuditorAdapter',
] as $class) {
    $assertTrue(
        class_exists($class, false),
        'Rollback bootstrap must make class available: ' . $class
    );
}

fwrite(
    STDOUT,
    "ProductionPrimaryRollbackExportCliContractTest passed: {$assertions} assertions.\n"
);
