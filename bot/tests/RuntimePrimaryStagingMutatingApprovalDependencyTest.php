<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$bootstrapPath = $projectRoot . '/bot/runtime/RuntimePrimaryStagingEntrypointBootstrap.php';
$verifierPath = $projectRoot . '/bot/runtime/RuntimePrimaryStagingBoundedMutatingSmokeEvidenceVerifier.php';
$runnerPath = $projectRoot . '/ops/runtime/run-staging-api-mutating-smoke.php';

$bootstrapSource = file_get_contents($bootstrapPath);
$verifierSource = file_get_contents($verifierPath);
$runnerSource = file_get_contents($runnerPath);
if (!is_string($bootstrapSource) || !is_string($verifierSource) || !is_string($runnerSource)) {
    throw new RuntimeException('Staging mutating runtime dependency sources are unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$approvalRequire = "require_once __DIR__ . '/RuntimePrimaryStagingMutatingSmokeApproval.php';";
$rollbackGuardRequire = "require_once __DIR__ . '/RuntimePrimaryStagingMutatingSmokeRollbackGuard.php';";
$assertTrue(
    str_contains($bootstrapSource, $approvalRequire),
    'Staging entrypoint bootstrap must load the mutating smoke approval class.'
);
$assertTrue(
    str_contains($bootstrapSource, $rollbackGuardRequire),
    'Staging entrypoint bootstrap must load the mutating smoke rollback guard class.'
);
$assertTrue(
    str_contains(
        $verifierSource,
        'RuntimePrimaryStagingMutatingSmokeApproval::CONTRACT_VERSION'
    ),
    'Bounded mutating evidence verifier must retain its approval contract binding.'
);

$bootstrapUse = strpos(
    $runnerSource,
    "RuntimePrimaryStagingEntrypointBootstrap.php"
);
$rollbackGuardUse = strpos(
    $runnerSource,
    'new RuntimePrimaryStagingMutatingSmokeRollbackGuard('
);
$databaseConnectionUse = strpos(
    $runnerSource,
    '$database = PdoConnectionFactory::create($databaseConfig);'
);
$assertTrue(
    $bootstrapUse !== false
        && $rollbackGuardUse !== false
        && $databaseConnectionUse !== false
        && $bootstrapUse < $databaseConnectionUse
        && $databaseConnectionUse < $rollbackGuardUse,
    'Real API mutating smoke must load the shared staging bootstrap before DB setup and rollback guard use.'
);

require_once $bootstrapPath;
$assertTrue(
    class_exists('RuntimePrimaryStagingMutatingSmokeApproval', false),
    'Staging bootstrap did not load RuntimePrimaryStagingMutatingSmokeApproval.'
);
$assertTrue(
    class_exists('RuntimePrimaryStagingMutatingSmokeRollbackGuard', false),
    'Staging bootstrap did not load RuntimePrimaryStagingMutatingSmokeRollbackGuard.'
);

require_once $verifierPath;
$assertTrue(
    class_exists('RuntimePrimaryStagingBoundedMutatingSmokeEvidenceVerifier', false),
    'Bounded mutating evidence verifier did not load after the shared staging bootstrap.'
);

fwrite(
    STDOUT,
    "RuntimePrimaryStagingMutatingApprovalDependencyTest passed: {$assertions} assertions.\n"
);
