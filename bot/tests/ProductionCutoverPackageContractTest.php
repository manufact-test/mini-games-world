<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/bot/cutover/ProductionCutoverPackageManifest.php';

$paths = [
    'manifest' => 'bot/cutover/ProductionCutoverPackageManifest.php',
    'preflight' => 'bot/cutover/ProductionCutoverExactPreflight.php',
    'run' => 'bot/cutover/ProductionCutoverRunTrait.php',
    'release' => 'bot/cutover/ProductionCutoverReleaseTrait.php',
    'recovery' => 'bot/cutover/ProductionCutoverRecoveryPolicyTrait.php',
    'receipt' => 'bot/cutover/ProductionCutoverReleaseReceiptVerifier.php',
    'guard' => 'bot/cutover/ProductionCutoverPackageGuardTrait.php',
    'loader' => 'bot/core/RuntimeConfigLoader.php',
    'cli' => 'ops/deploy/production-cutover.php',
];
$sources = [];
foreach ($paths as $name => $relative) {
    $source = file_get_contents($projectRoot . '/' . $relative);
    if (!is_string($source)) {
        throw new RuntimeException('Cutover package source is unavailable: ' . $name . '.');
    }
    $sources[$name] = $source;
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$manifest = (new ProductionCutoverPackageManifest($projectRoot))->inspect();
$assertTrue(($manifest['ready'] ?? false) === true, 'Exact repository package manifest must pass');
$assertTrue(
    preg_match('/\A[a-f0-9]{40}\z/', (string)($manifest['release_commit'] ?? '')) === 1,
    'Package manifest must bind exact Git commit'
);
$assertTrue(
    preg_match('/\A[a-f0-9]{64}\z/', (string)($manifest['package_fingerprint'] ?? '')) === 1,
    'Package manifest must expose exact SHA-256 fingerprint'
);
$assertTrue(
    (int)($manifest['critical_file_count'] ?? 0) >= 40,
    'Package manifest must cover the full cutover and rollback surface'
);
foreach ([
    'bot/core/RuntimeConfigLoader.php',
    'bot/runtime/ProductionPrimaryAtomicStorageAdapter.php',
    'bot/runtime/ProductionPrimaryRollbackExportService.php',
    'bot/runtime/ProductionPrimaryLiveRollbackService.php',
    'bot/cutover/ProductionCutoverReleaseReceiptVerifier.php',
    'ops/deploy/production-cutover.php',
] as $critical) {
    $assertTrue(
        preg_match(
            "/'" . preg_quote($critical, '/') . "'/",
            $sources['manifest']
        ) === 1,
        'Package manifest must bind critical file: ' . $critical
    );
}

$assertTrue(
    str_contains($sources['preflight'], 'ProductionCutoverPackageManifest')
        && str_contains($sources['preflight'], 'ProductionRuntimePrimaryContract::inspect')
        && str_contains($sources['preflight'], "'production_switch_allowed'] = false")
        && str_contains($sources['preflight'], "'execution_mode'] = 'read-only-exact-package-preflight'"),
    'Exact preflight must bind package/runtime identity and remain read-only'
);
$assertTrue(
    str_contains($sources['run'], 'ProductionCutoverExactPreflight(')
        && !str_contains($sources['run'], 'new ProductionPreflightRunner(')
        && str_contains($sources['run'], '$this->policy->assertApproved('),
    'Cutover run must use exact preflight and separate run approval'
);

$release = $sources['release'];
$receiptPosition = strpos($release, 'ProductionCutoverReleaseReceiptVerifier())->verify(');
$approvalPosition = strpos($release, '$this->policy->assertReleaseApproved(');
$runtimePosition = strpos($release, '$this->writeRuntime($finalRuntime);');
$unsealPosition = strpos($release, '$this->removeWriteBlock();');
$completedPosition = strpos($release, "'state' => 'completed'");
$assertTrue(
    $receiptPosition !== false
        && $approvalPosition !== false
        && $runtimePosition !== false
        && $unsealPosition !== false
        && $completedPosition !== false
        && $receiptPosition < $approvalPosition
        && $approvalPosition < $runtimePosition
        && $runtimePosition < $unsealPosition
        && $unsealPosition < $completedPosition,
    'Release must verify receipt and approval before runtime, unseal and completed publication'
);
$assertTrue(
    str_contains($release, "if (\$mutationStage === 'none')")
        && str_contains($release, 'release_pre_mutation_gate_failed')
        && str_contains($release, "'json_write_block_must_remain_active' => true"),
    'Pre-mutation release failure must remain sealed and require review'
);
$assertTrue(
    str_contains($release, "'verified_live_rollback_required' => true")
        && str_contains($release, 'run-production-primary-rollback-export.php')
        && str_contains($release, 'run-production-primary-live-rollback.php')
        && !str_contains($release, "'automatic_rollback_available'"),
    'Completed release must advertise only verified fresh rollback'
);

$assertTrue(
    str_contains($sources['recovery'], 'PRE_ROUTE_ABORT_STAGES')
        && str_contains($sources['recovery'], 'DB-primary may have accepted writes; stale JSON rollback is forbidden.')
        && str_contains($sources['recovery'], "'legacy_json_restore_allowed' => false")
        && str_contains($sources['recovery'], "'rollback_export_required' => true"),
    'Recovery policy must allow legacy abort only before DB route publication'
);
$assertTrue(
    str_contains($sources['receipt'], "'cutover_state_exact'")
        && str_contains($sources['receipt'], "'all_nine_modules_exact'")
        && str_contains($sources['receipt'], "'read_only_api_smoke_passed'")
        && str_contains($sources['receipt'], 'MAX_AGE_SECONDS = 900'),
    'Release receipt must prove protected exact smoke within 15 minutes'
);
$assertTrue(
    str_contains($sources['guard'], 'assertPackageIntegrity()')
        && str_contains($sources['guard'], '$this->policy->assertPackage($manifest);')
        && str_contains($sources['guard'], 'assertControlEnvironmentAndBuild()'),
    'Execution must require approval binding while controls remain package-integrity gated'
);
$assertTrue(
    str_contains($sources['loader'], "defined('MINIGAMES_CUTOVER_CONTROL_BOOTSTRAP')")
        && str_contains($sources['loader'], 'MINIGAMES_CUTOVER_CONTROL_BOOTSTRAP === true')
        && str_contains($sources['loader'], 'return $config;'),
    'Only the dedicated control bootstrap may bypass runtime overlay loading'
);

$cli = $sources['cli'];
$constantPosition = strpos($cli, "define('MINIGAMES_CUTOVER_CONTROL_BOOTSTRAP', true)");
$bootstrapPosition = strpos($cli, "require \$projectRoot . '/bot/core/bootstrap.php';");
$databasePosition = strpos($cli, "in_array(\$requestedMode, ['run', 'release'], true)");
$assertTrue(
    $constantPosition !== false
        && $bootstrapPosition !== false
        && $constantPosition < $bootstrapPosition,
    'Control CLI must define isolated bootstrap mode before core bootstrap'
);
$assertTrue(
    $databasePosition !== false
        && str_contains($cli, "'package',")
        && str_contains($cli, "'preflight',")
        && str_contains($cli, "'run',")
        && str_contains($cli, "'status',")
        && str_contains($cli, "'release',")
        && str_contains($cli, "'rollback',")
        && str_contains($cli, "'rearm',"),
    'Control CLI must expose the complete versioned command package'
);
$assertTrue(
    !str_contains($cli, 'shell_exec(')
        && !str_contains($cli, 'exec(')
        && !str_contains($cli, 'system(')
        && !str_contains($cli, 'passthru(')
        && !str_contains($cli, 'crontab')
        && !str_contains($cli, 'setWebhook'),
    'Control CLI must not execute shell, Cron or webhook changes'
);

foreach (['preflight', 'run', 'release', 'recovery', 'receipt', 'guard'] as $name) {
    foreach (['curl', 'setWebhook', 'crontab'] as $forbidden) {
        $assertTrue(
            !str_contains($sources[$name], $forbidden),
            'Cutover package runtime must not change network registration or Cron: '
                . $name . ': ' . $forbidden
        );
    }
}

fwrite(STDOUT, "ProductionCutoverPackageContractTest passed: {$assertions} assertions.\n");
