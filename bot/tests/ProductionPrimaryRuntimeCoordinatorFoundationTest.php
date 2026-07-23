<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/bot/runtime/ProductionPrimaryRuntimeCoordinator.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $messagePart) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($messagePart))) return;
        throw new RuntimeException('Unexpected exception: ' . $error->getMessage());
    }
    throw new RuntimeException('Expected exception was not thrown.');
};

$remove = static function (string $path) use (&$remove): void {
    if (!file_exists($path) && !is_link($path)) return;
    if (is_dir($path) && !is_link($path)) {
        $items = scandir($path);
        if (!is_array($items)) throw new RuntimeException('Fixture directory could not be listed.');
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') continue;
            $remove($path . '/' . $name);
        }
        if (!rmdir($path)) throw new RuntimeException('Fixture directory could not be removed.');
        return;
    }
    if (!unlink($path)) throw new RuntimeException('Fixture file could not be removed.');
};

$writePrivate = static function (string $path, string $content): void {
    $written = file_put_contents($path, $content, LOCK_EX);
    if ($written !== strlen($content) || !chmod($path, 0600)) {
        throw new RuntimeException('Private fixture could not be written safely.');
    }
};

$plan = str_repeat('a', 64);
$source = str_repeat('b', 64);
$modules = array_fill_keys([
    'accounts',
    'realtime',
    'invites',
    'notifications',
    'economy',
    'history',
    'shop',
    'payments',
    'weekly_bonus',
], true);

$fixture = static function (
    string $state,
    array $runtimeOverrides = [],
    array $stateOverrides = [],
    bool $writeBackup = true
) use ($plan, $source, $modules, $writePrivate): array {
    $root = sys_get_temp_dir() . '/mgw-production-coordinator-' . bin2hex(random_bytes(6));
    $project = $root . '/project';
    $private = $root . '/private';
    $data = $root . '/data';
    foreach ([$root, $project, $private, $data] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new RuntimeException('Fixture directory could not be created.');
        }
        if (!chmod($directory, 0700)) {
            throw new RuntimeException('Fixture directory permissions could not be secured.');
        }
    }

    $configFile = $private . '/config.php';
    $writePrivate($configFile, "<?php\ndeclare(strict_types=1);\nreturn [];\n");

    $runtime = [
        'enabled' => true,
        'production_activated' => true,
        'activation_build' => ProductionPrimaryRuntimeActivationContract::ACTIVATION_BUILD,
        'activation_plan_fingerprint' => $plan,
        'activation_source_fingerprint' => $source,
        'activated_at_utc' => '2026-07-23T13:00:00+00:00',
        'rollback_driver' => 'json',
        'modules' => $modules,
    ];
    $runtime = array_replace_recursive($runtime, $runtimeOverrides);

    $awaiting = $state === 'awaiting_release';
    $config = [
        'environment' => 'production',
        'storage_driver' => 'json',
        'data_dir' => $data,
        'database' => [
            'enabled' => true,
            'driver' => 'mysql',
            'host' => 'db.internal',
            'port' => 3306,
            'name' => 'mgw_runtime',
            'user' => 'mgw_runtime',
            'password' => 'fixture-password',
            'charset' => 'utf8mb4',
        ],
        'feature_flags' => [
            'maintenance_mode' => $awaiting,
            'financial_read_only' => $awaiting,
            'database_runtime' => $runtime,
        ],
    ];

    $statePayload = [
        'state' => $state,
        'build' => ProductionPrimaryRuntimeActivationContract::ACTIVATION_BUILD,
        'started_at_utc' => '2026-07-23T12:50:00+00:00',
        'plan_fingerprint' => $plan,
        'source_fingerprint' => $source,
        'runtime_backup_present' => true,
        'database_runtime_published' => true,
        'json_write_block_active' => $awaiting,
        'rollback_driver' => 'json',
    ];
    if ($awaiting) {
        $statePayload['release_ready_at_utc'] = '2026-07-23T13:00:00+00:00';
        $statePayload['maintenance_active'] = true;
        $statePayload['financial_read_only_active'] = true;
    } else {
        $statePayload['completed_at_utc'] = '2026-07-23T13:05:00+00:00';
    }
    $statePayload = array_replace_recursive($statePayload, $stateOverrides);
    $writePrivate(
        $private . '/production-cutover.json',
        json_encode($statePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
    );

    if ($writeBackup) {
        $writePrivate($private . '/production-cutover.runtime.backup', '__MGW_RUNTIME_ABSENT__');
    }

    if ($awaiting) {
        $writePrivate(
            $data . '/.cutover-write-block',
            json_encode([
                'state' => 'sealed',
                'environment' => 'production',
                'build' => ProductionPrimaryRuntimeActivationContract::ACTIVATION_BUILD,
                'plan_fingerprint' => $plan,
                'activated_at_utc' => '2026-07-23T13:00:00+00:00',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );
    }

    return [$root, $project, $private, $data, $configFile, $config];
};

[$root, $project, $private, $data, $configFile, $config] = $fixture('awaiting_release');
try {
    $activation = new ProductionPrimaryRuntimeActivationContract($project, $config, $configFile);
    $report = $activation->inspect();
    $assertTrue(($report['ready'] ?? false) === true, 'Protected awaiting-release activation must pass');
    $assertTrue(($report['state'] ?? '') === 'awaiting_release', 'Awaiting-release state must be preserved');
    $assertTrue(($report['json_write_block_active'] ?? false) === true, 'Awaiting-release must prove the JSON write block');
    $assertTrue(count((array)($report['enabled_modules'] ?? [])) === 9, 'All nine runtime modules must be enabled');
    $assertTrue(($report['database_contacted'] ?? true) === false, 'Activation inspection must not contact the database');
    $assertTrue(($report['production_changed'] ?? true) === false, 'Activation inspection must not change production');
    $assertTrue(
        preg_match('/\A[a-f0-9]{64}\z/', (string)($report['contract_fingerprint'] ?? '')) === 1,
        'Activation report must expose a deterministic fingerprint'
    );

    $coordinator = new ProductionPrimaryRuntimeCoordinator($project, $config, $configFile);
    $coordinatorReport = $coordinator->inspect();
    $assertTrue(($coordinatorReport['ready'] ?? false) === true, 'Coordinator must inherit the valid activation contract');
    $assertTrue(($coordinatorReport['execution_enabled'] ?? true) === false, 'Foundation execution must remain disabled');
    $assertTrue(($coordinatorReport['entrypoint_wiring_required'] ?? false) === true, 'Foundation must require a later wiring stage');

    foreach (['api', 'webhook'] as $entrypoint) {
        $planReport = $coordinator->prepareEntrypointPlan($entrypoint);
        $assertTrue(($planReport['ok'] ?? false) === true, 'Valid entrypoint plan must pass: ' . $entrypoint);
        $assertTrue(($planReport['entrypoint'] ?? '') === $entrypoint, 'Entrypoint plan identity must match');
        $assertTrue(($planReport['storage_driver'] ?? '') === 'database', 'Entrypoint plan must target DB-primary storage');
        $assertTrue(($planReport['execution_enabled'] ?? true) === false, 'Entrypoint plan must remain non-executable');
    }

    $assertThrows(
        static fn() => $coordinator->prepareEntrypointPlan('cron'),
        'only API and webhook'
    );
    $assertThrows(
        static fn() => $coordinator->executeApiRequest(['action' => 'bootstrap']),
        'intentionally disabled'
    );
    $assertThrows(
        static fn() => $coordinator->executeWebhookMutation(['update_id' => 1]),
        'intentionally disabled'
    );
} finally {
    $remove($root);
}

[$root, $project, $private, $data, $configFile, $config] = $fixture('completed');
try {
    $report = (new ProductionPrimaryRuntimeActivationContract(
        $project,
        $config,
        $configFile
    ))->inspect();
    $assertTrue(($report['ready'] ?? false) === true, 'Completed activation without write block must pass');
    $assertTrue(($report['state'] ?? '') === 'completed', 'Completed state must be preserved');
    $assertTrue(($report['json_write_block_active'] ?? true) === false, 'Completed activation must prove the write block is absent');
} finally {
    $remove($root);
}

[$root, $project, $private, $data, $configFile, $config] = $fixture(
    'awaiting_release',
    [],
    ['plan_fingerprint' => str_repeat('c', 64)]
);
try {
    $report = (new ProductionPrimaryRuntimeActivationContract($project, $config, $configFile))->inspect();
    $assertTrue(($report['ready'] ?? true) === false, 'Mismatched cutover plan must block activation');
    $assertTrue(
        in_array('Production cutover plan fingerprint does not match runtime.', (array)($report['blockers'] ?? []), true),
        'Plan mismatch blocker must be explicit'
    );
} finally {
    $remove($root);
}

[$root, $project, $private, $data, $configFile, $config] = $fixture(
    'completed',
    ['modules' => ['unexpected_module' => true]]
);
try {
    $report = (new ProductionPrimaryRuntimeActivationContract($project, $config, $configFile))->inspect();
    $assertTrue(($report['ready'] ?? true) === false, 'Unexpected production module must block activation');
    $assertTrue(($report['checks']['all_modules_enabled_exact'] ?? true) === false, 'Module set must be exact');
} finally {
    $remove($root);
}

[$root, $project, $private, $data, $configFile, $config] = $fixture(
    'completed',
    [],
    [],
    false
);
try {
    $report = (new ProductionPrimaryRuntimeActivationContract($project, $config, $configFile))->inspect();
    $assertTrue(($report['ready'] ?? true) === false, 'Missing exact runtime backup must block activation');
    $assertTrue(($report['checks']['runtime_backup_private'] ?? true) === false, 'Missing runtime backup must remain explicit');
} finally {
    $remove($root);
}

[$root, $project, $private, $data, $configFile, $config] = $fixture('completed');
try {
    $inside = $project . '/private';
    if (!mkdir($inside, 0700, true) || !chmod($inside, 0700)) {
        throw new RuntimeException('Inside-project fixture could not be created.');
    }
    $insideConfig = $inside . '/config.php';
    $writePrivate($insideConfig, "<?php\nreturn [];\n");
    $assertThrows(
        static fn() => new ProductionPrimaryRuntimeActivationContract(
            $project,
            $config,
            $insideConfig
        ),
        'outside the deployed project'
    );
} finally {
    $remove($root);
}

$activationSource = file_get_contents(
    $projectRoot . '/bot/runtime/ProductionPrimaryRuntimeActivationContract.php'
);
$coordinatorSource = file_get_contents(
    $projectRoot . '/bot/runtime/ProductionPrimaryRuntimeCoordinator.php'
);
$apiSource = file_get_contents($projectRoot . '/bot/api.php');
$webhookSource = file_get_contents($projectRoot . '/bot/handlers/WebhookHandler.php');
$assertTrue(
    is_string($activationSource)
        && is_string($coordinatorSource)
        && is_string($apiSource)
        && is_string($webhookSource),
    'Coordinator foundation source files must be readable'
);
foreach (['file_put_contents(', 'rename(', 'unlink(', 'StorageFactory', 'PdoConnectionFactory', 'transaction(', 'curl'] as $forbidden) {
    $assertTrue(
        !str_contains((string)$activationSource, $forbidden)
            && !str_contains((string)$coordinatorSource, $forbidden),
        'Coordinator foundation must remain read-only and offline: ' . $forbidden
    );
}
$assertTrue(
    !str_contains((string)$apiSource, 'ProductionPrimaryRuntimeCoordinator')
        && !str_contains((string)$webhookSource, 'ProductionPrimaryRuntimeCoordinator'),
    'Foundation must not wire live API or webhook entrypoints'
);
$assertTrue(
    str_contains((string)$coordinatorSource, 'public const EXECUTION_ENABLED = false')
        && str_contains((string)$coordinatorSource, 'executeApiRequest(')
        && str_contains((string)$coordinatorSource, 'executeWebhookMutation('),
    'Coordinator foundation must expose the versioned fail-closed execution contract'
);

fwrite(
    STDOUT,
    "ProductionPrimaryRuntimeCoordinatorFoundationTest passed: {$assertions} assertions.\n"
);
