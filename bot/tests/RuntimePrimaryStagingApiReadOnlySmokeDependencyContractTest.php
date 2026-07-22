<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$bootstrap = file_get_contents(
    $projectRoot . '/bot/runtime/RuntimePrimaryStagingEntrypointBootstrap.php'
);
$runner = file_get_contents(
    $projectRoot . '/ops/runtime/run-staging-db-primary-api-read-only-smoke.php'
);
if (!is_string($bootstrap) || !is_string($runner)) {
    throw new RuntimeException('Read-only smoke dependency sources are unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$entrypointEvidence = strpos(
    $bootstrap,
    "require_once __DIR__ . '/RuntimePrimaryEntrypointEvidence.php';"
);
$evidenceVerifier = strpos(
    $bootstrap,
    "require_once __DIR__ . '/RuntimePrimaryStagingEvidenceVerifier.php';"
);
$assertTrue(
    $entrypointEvidence !== false
        && $evidenceVerifier !== false
        && $entrypointEvidence < $evidenceVerifier,
    'API smoke bootstrap must load entrypoint evidence before the staging evidence verifier'
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

$assertTrue(
    substr_count($bootstrap, 'RuntimePrimaryEntrypointEvidence.php') === 1,
    'API smoke bootstrap must load the entrypoint evidence dependency exactly once'
);

fwrite(
    STDOUT,
    "RuntimePrimaryStagingApiReadOnlySmokeDependencyContractTest passed: {$assertions} assertions.\n"
);
