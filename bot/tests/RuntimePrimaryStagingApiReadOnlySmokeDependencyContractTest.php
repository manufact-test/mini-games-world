<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$bootstrapPath = $projectRoot . '/bot/runtime/RuntimePrimaryStagingEntrypointBootstrap.php';
$runnerPath = $projectRoot . '/ops/runtime/run-staging-db-primary-api-read-only-smoke.php';
$bootstrap = file_get_contents($bootstrapPath);
$runner = file_get_contents($runnerPath);
if (!is_string($bootstrap) || !is_string($runner)) {
    throw new RuntimeException('Read-only smoke dependency sources are unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$requiredBootstrapFiles = [
    'RuntimePrimaryEntrypointEvidence.php',
    'RuntimePrimaryProjectionBootstrap.php',
    'RuntimePrimaryProjectionOutboxSchemaInstaller.php',
    'RuntimePrimaryProjectionOutboxWriter.php',
    'RuntimePrimaryProjectionWorker.php',
    'RuntimePrimaryProjectionWorkerInterface.php',
    'RuntimePrimaryProjectionAuditorInterface.php',
    'RuntimePrimaryProjectionWorkerAdapter.php',
    'RuntimePrimaryProjectionAuditorAdapter.php',
    'RuntimePrimaryStagingRequestFinalizer.php',
    'RuntimePrimaryStagingApiSessionCoordinator.php',
    'RuntimePrimaryStagingEntrypointStorageSelector.php',
];
foreach ($requiredBootstrapFiles as $file) {
    $assertTrue(
        substr_count($bootstrap, $file) === 1,
        'API smoke bootstrap must load the critical dependency exactly once: ' . $file
    );
}

$entrypointEvidence = strpos(
    $bootstrap,
    "require_once __DIR__ . '/RuntimePrimaryEntrypointEvidence.php';"
);
$evidenceVerifier = strpos(
    $bootstrap,
    "require_once __DIR__ . '/RuntimePrimaryStagingEvidenceVerifier.php';"
);
$projectionBootstrap = strpos(
    $bootstrap,
    "require_once __DIR__ . '/RuntimePrimaryProjectionBootstrap.php';"
);
$outboxSchema = strpos(
    $bootstrap,
    "require_once __DIR__ . '/RuntimePrimaryProjectionOutboxSchemaInstaller.php';"
);
$outboxWriter = strpos(
    $bootstrap,
    "require_once __DIR__ . '/RuntimePrimaryProjectionOutboxWriter.php';"
);
$projectionWorker = strpos(
    $bootstrap,
    "require_once __DIR__ . '/RuntimePrimaryProjectionWorker.php';"
);
$workerAdapter = strpos(
    $bootstrap,
    "require_once __DIR__ . '/RuntimePrimaryProjectionWorkerAdapter.php';"
);
$coordinator = strpos(
    $bootstrap,
    "require_once __DIR__ . '/RuntimePrimaryStagingApiSessionCoordinator.php';"
);
$assertTrue(
    $entrypointEvidence !== false
        && $evidenceVerifier !== false
        && $entrypointEvidence < $evidenceVerifier,
    'API smoke bootstrap must load entrypoint evidence before the staging evidence verifier'
);
$assertTrue(
    $projectionBootstrap !== false
        && $outboxSchema !== false
        && $outboxWriter !== false
        && $projectionWorker !== false
        && $workerAdapter !== false
        && $coordinator !== false
        && $projectionBootstrap < $outboxSchema
        && $outboxSchema < $outboxWriter
        && $outboxWriter < $projectionWorker
        && $projectionWorker < $workerAdapter
        && $workerAdapter < $coordinator,
    'API smoke bootstrap must load the complete projection execution graph before the coordinator'
);

$bootstrapRequire = strpos(
    $runner,
    'require_once $projectRoot . \'/bot/runtime/RuntimePrimaryStagingEntrypointBootstrap.php\';'
);
$overlayRequire = strpos(
    $runner,
    'require_once $projectRoot . \'/bot/runtime/RuntimePrimaryStagingApiReadOnlySmokeConfigOverlay.php\';'
);
$smokeRequire = strpos(
    $runner,
    'require_once $projectRoot . \'/bot/runtime/RuntimePrimaryStagingApiReadOnlySmoke.php\';'
);
$assertTrue(
    $bootstrapRequire !== false
        && $overlayRequire !== false
        && $smokeRequire !== false
        && $bootstrapRequire < $overlayRequire
        && $overlayRequire < $smokeRequire,
    'CLI smoke must load the complete staging bootstrap before overlay and smoke execution'
);

require_once $bootstrapPath;
$requiredClasses = [
    'RuntimePrimaryEntrypointEvidence',
    'RuntimePrimaryStagingEvidenceVerifier',
    'RuntimePrimaryProjectionOutboxSchemaInstaller',
    'RuntimePrimaryProjectionOutboxWriter',
    'RuntimePrimaryProjectionWorker',
    'RuntimePrimaryProjectionWorkerAdapter',
    'RuntimePrimaryProjectionAuditorAdapter',
    'RuntimePrimaryRepositoryProjectorFactory',
    'RuntimePrimaryAllModuleProjector',
    'RuntimePrimaryStagingRequestFinalizer',
    'RuntimePrimaryStagingApiSessionCoordinator',
    'RuntimePrimaryStagingEntrypointStorageSelector',
];
foreach ($requiredClasses as $class) {
    $assertTrue(
        class_exists($class, false),
        'Executing the API smoke bootstrap must make the critical class available: ' . $class
    );
}

fwrite(
    STDOUT,
    "RuntimePrimaryStagingApiReadOnlySmokeDependencyContractTest passed: {$assertions} assertions.\n"
);
