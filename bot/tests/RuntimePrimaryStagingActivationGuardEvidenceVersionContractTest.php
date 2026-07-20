<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$source = file_get_contents($projectRoot . '/bot/runtime/RuntimePrimaryStagingActivationGuard.php');
if (!is_string($source)) {
    throw new RuntimeException('Activation guard source is unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assertTrue(
    str_contains($source, "'evidence_manifest_version' => (string)(\$manifest['manifest_version'] ?? '')"),
    'Readiness must report the loaded manifest version'
);
$assertTrue(
    str_contains($source, 'Staging activation evidence is not valid: '),
    'Failure wording must be version-neutral'
);
$assertTrue(
    !str_contains($source, 'Staging activation evidence v2 is not valid: '),
    'Evidence v3 must not be labelled as v2'
);
$assertTrue(
    str_contains($source, 'RuntimePrimaryStagingEvidenceV2Gate('),
    'Activation must retain the v2/v3 compatibility gate'
);

fwrite(STDOUT, "RuntimePrimaryStagingActivationGuardEvidenceVersionContractTest passed: {$assertions} assertions.\n");
