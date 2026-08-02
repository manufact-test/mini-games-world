<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$paths = [
    'seeder' => 'bot/cutover/ProductionCutoverPrimaryStateSeeder.php',
    'perform' => 'bot/cutover/ProductionCutoverPerformTrait.php',
    'smoke' => 'bot/cutover/ProductionCutoverReleaseSmokeService.php',
];
$sources = [];
foreach ($paths as $name => $relative) {
    $source = file_get_contents($projectRoot . '/' . $relative);
    if (!is_string($source)) {
        throw new RuntimeException('Primary seed source unavailable: ' . $name . '.');
    }
    $sources[$name] = $source;
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$seeder = $sources['seeder'];
$initialize = strpos($seeder, '$adapter->initializeFromSnapshot($snapshot)');
$worker = strpos($seeder, '$worker->runOnce();');
$audit = strpos($seeder, '))->auditOnly(');
$return = strpos($seeder, "'state_revision' => 1");
$assertTrue(
    $initialize !== false && $worker !== false && $audit !== false && $return !== false
        && $initialize < $worker && $worker < $audit && $audit < $return,
    'Seeder must initialize revision 1, complete projection and audit before success'
);
$assertTrue(
    str_contains($seeder, "\$revision !== 1")
        && str_contains($seeder, "'projection_event_status' => 'completed'")
        && str_contains($seeder, "'worker_tick_count' => \$workerTicks")
        && str_contains($seeder, "'outbox_fingerprint' => \$outboxFingerprint"),
    'Seeder must expose exact revision, completed event, worker count and outbox fingerprint'
);
$assertTrue(
    str_contains($seeder, "RuntimePrimaryProjectionOutboxWriter::PROJECTION_VERSION")
        && str_contains($seeder, "'completed_event_count' => 1")
        && str_contains($seeder, "'pending_event_count' => 0")
        && str_contains($seeder, "'failed_event_count' => 0"),
    'Seeder must require exact projection version and one completed-only outbox chain'
);
$assertTrue(
    str_contains($seeder, "sort(\$modules, SORT_STRING)")
        && str_contains($seeder, "sort(\$expected, SORT_STRING)")
        && str_contains($seeder, "\$modules !== \$expected"),
    'Seeder must compare all nine modules independent of incidental array order'
);

$perform = $sources['perform'];
$seedPosition = strpos($perform, 'ProductionCutoverPrimaryStateSeeder(');
$routePosition = strpos($perform, '$this->writeRuntime($activatedRuntime);');
$statePosition = strpos($perform, "\$context['mutation_stage'] = 'primary_state_seeded';");
$assertTrue(
    $seedPosition !== false && $statePosition !== false && $routePosition !== false
        && $seedPosition < $statePosition && $statePosition < $routePosition,
    'Cutover must seed exact DB-primary state before publishing production route'
);
$assertTrue(
    str_contains($perform, "(int)(\$primaryState['state_revision'] ?? 0) !== 1")
        && str_contains($perform, "'outbox_fingerprint' => \$outboxFingerprint")
        && str_contains($perform, "'all_module_fingerprint' => \$allModuleFingerprint")
        && !str_contains($perform, '$this->synchronizeRuntime($activatedConfig, $snapshot)'),
    'Route publication must use new state/outbox evidence, not the legacy synchronization path'
);

$smoke = $sources['smoke'];
$assertTrue(
    str_contains($smoke, 'RuntimePrimaryProjectionOutboxWriter::PROJECTION_VERSION')
        && str_contains($smoke, 'Release smoke changed DB-primary state or projection outbox.')
        && str_contains($smoke, "'database_write_executed' => false")
        && str_contains($smoke, "'health_probe' => 'internal_cli_equivalent'"),
    'Release smoke must verify exact outbox version and remain read-only'
);
$assertTrue(
    str_contains($smoke, 'is_link($this->receiptFile)')
        && str_contains($smoke, 'rename($temporary, $this->receiptFile)')
        && str_contains($smoke, 'chmod($this->receiptFile, 0600)'),
    'Release smoke receipt must reject symlink target and publish atomically with mode 0600'
);

foreach (['seeder', 'smoke'] as $name) {
    foreach (['curl', 'setWebhook', 'crontab', 'shell_exec(', 'exec(', 'system('] as $forbidden) {
        $assertTrue(
            !str_contains($sources[$name], $forbidden),
            'Primary seed/smoke must not execute external controls: ' . $name . ': ' . $forbidden
        );
    }
}

fwrite(
    STDOUT,
    "ProductionCutoverPrimaryStateSeederContractTest passed: {$assertions} assertions.\n"
);
