<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$manifestPath = $root . '/bot/helpers/staging-e2e-runtime-files.txt';
$manifest = file_get_contents($manifestPath);
if (!is_string($manifest)) {
    throw new RuntimeException('Staging runtime fingerprint manifest is unavailable.');
}

$paths = array_values(array_filter(array_map(
    static fn(string $line): string => trim($line),
    preg_split('/\R/', $manifest) ?: []
), static fn(string $line): bool => $line !== '' && !str_starts_with($line, '#')));
$pathSet = array_fill_keys($paths, true);

$required = [
    'bot/staging-e2e-readiness.php',
    'bot/staging-test-auth.php',
    'bot/services/StagingTestAuthService.php',
    'bot/services/StagingTestInviteResidualRecoveryService.php',
    'bot/services/StagingTestPlayerStateResetService.php',
    'bot/staging-invite-mismatch-diagnostic.php',
    'bot/services/StagingInviteMismatchDiagnosticService.php',
    'bot/staging-test-only-invite-recovery.php',
    'bot/services/StagingTestOnlyInviteOrphanRecoveryService.php',
    'bot/staging-fresh-invite-recovery.php',
    'bot/services/StagingFreshInviteReplacementRecoveryService.php',
    'bot/services/GitHubActionsOidcVerifier.php',
    'bot/helpers/RsaJwkPublicKey.php',
    'bot/core/bootstrap.php',
    'bot/invites/RuntimeInviteRepository.php',
    'bot/notifications/RuntimeNotificationRepository.php',
    'bot/ledger/RuntimeEconomyRepository.php',
    'bot/storage/JsonDatabase.php',
    'bot/storage/JsonStorageAdapter.php',
];

$assertions = 0;
foreach ($required as $path) {
    $assertions++;
    if (!isset($pathSet[$path])) {
        throw new RuntimeException('Staging global-setup runtime owner missing from fingerprint: ' . $path);
    }
    if (!is_file($root . '/' . $path)) {
        throw new RuntimeException('Fingerprinted staging global-setup owner does not exist: ' . $path);
    }
}

$assertions++;
if (count($paths) !== count(array_unique($paths))) {
    throw new RuntimeException('Staging runtime fingerprint manifest must not contain duplicate paths.');
}

fwrite(STDOUT, "StagingGlobalSetupRuntimeFingerprintCoverageContractTest: {$assertions} assertions passed\n");
