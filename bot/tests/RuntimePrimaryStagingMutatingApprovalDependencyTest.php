<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$bootstrapPath = $projectRoot . '/bot/runtime/RuntimePrimaryStagingEntrypointBootstrap.php';
$verifierPath = $projectRoot . '/bot/runtime/RuntimePrimaryStagingBoundedMutatingSmokeEvidenceVerifier.php';

$bootstrapSource = file_get_contents($bootstrapPath);
$verifierSource = file_get_contents($verifierPath);
if (!is_string($bootstrapSource) || !is_string($verifierSource)) {
    throw new RuntimeException('Staging mutating approval dependency sources are unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assertTrue(
    str_contains(
        $bootstrapSource,
        "require_once __DIR__ . '/RuntimePrimaryStagingMutatingSmokeApproval.php';"
    ),
    'Staging entrypoint bootstrap must load the mutating smoke approval class.'
);
$assertTrue(
    str_contains(
        $verifierSource,
        'RuntimePrimaryStagingMutatingSmokeApproval::CONTRACT_VERSION'
    ),
    'Bounded mutating evidence verifier must retain its approval contract binding.'
);

require_once $bootstrapPath;
$assertTrue(
    class_exists('RuntimePrimaryStagingMutatingSmokeApproval', false),
    'Staging bootstrap did not load RuntimePrimaryStagingMutatingSmokeApproval.'
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
