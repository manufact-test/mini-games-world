<?php
declare(strict_types=1);

require_once __DIR__ . '/BackupCli.php';

$options = getopt('', [
    'backup-root::',
    'external-dir::',
    'retention-days::',
    'retention-count::',
    'data-dir::',
    'no-release',
]);

foreach ([
    'backup-root' => 'MGW_BACKUP_ROOT',
    'external-dir' => 'MGW_BACKUP_EXTERNAL_DIR',
    'retention-days' => 'MGW_BACKUP_RETENTION_DAYS',
    'retention-count' => 'MGW_BACKUP_RETENTION_COUNT',
] as $option => $environmentKey) {
    if (isset($options[$option]) && $options[$option] !== false && trim((string)$options[$option]) !== '') {
        putenv($environmentKey . '=' . trim((string)$options[$option]));
    }
}
if (array_key_exists('no-release', $options)) {
    putenv('MGW_BACKUP_INCLUDE_RELEASE=false');
}

try {
    $context = BackupCli::boot();
    $manager = BackupCli::manager($context, [
        'data_dir' => isset($options['data-dir']) ? (string)$options['data-dir'] : null,
    ]);
    $result = $manager->create(
        (string)$context['environment'],
        (string)$context['build']
    );

    $environment = strtolower(trim((string)$context['environment']));
    if ($environment === 'production'
        && class_exists('FeatureFlagService')
        && FeatureFlagService::BUILD === 'v102-mvp14-production-preflight') {
        try {
            $projectRoot = (string)$context['project_root'];
            require_once $projectRoot . '/bot/cutover/ProductionPreflightService.php';
            require_once $projectRoot . '/bot/cutover/ProductionPreflightRunner.php';
            require_once $projectRoot . '/bot/database/ManagedMigrationController.php';

            $result['production_preflight'] = (new ProductionPreflightRunner(
                $projectRoot,
                is_array($context['config'] ?? null) ? $context['config'] : [],
                is_string($context['config_file'] ?? null) ? $context['config_file'] : null
            ))->run();
        } catch (Throwable $preflightError) {
            $message = preg_replace(
                '~/(?:home|var|tmp|srv)/[^\s\'\"]+~',
                '[private-path]',
                $preflightError->getMessage()
            ) ?? $preflightError->getMessage();
            $result['production_preflight'] = [
                'ok' => false,
                'report_type' => 'mvp-14.8.5-production-preflight',
                'execution_mode' => 'read-only',
                'production_switch_allowed' => false,
                'production_switch_performed' => false,
                'production_changed' => false,
                'sensitive_identifiers_exposed' => false,
                'error_class' => get_class($preflightError),
                'error_message' => mb_substr(trim($message), 0, 500),
            ];
        }
    }

    BackupCli::printJson($result);
} catch (Throwable $e) {
    BackupCli::fail($e);
}
