<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = file_get_contents($root . '/bot/services/StagingTestPlayerStateResetService.php');
if (!is_string($service)) {
    throw new RuntimeException('Cannot read staging test reset service.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

foreach ([
    'invite_cleanup_parity_db_missing',
    'invite_cleanup_parity_db_extra',
    'invite_cleanup_parity_fingerprint',
    'invite_cleanup_parity_unknown',
] as $stage) {
    $assert(str_contains($service, "'{$stage}'"), "Missing safe invite parity stage {$stage}.");
}
$assert(str_contains($service, 'catch (StagingTestPlayerResetStageException $error)'),
    'Invite cleanup must preserve the classified safe stage.');
$assert(str_contains($service, '$sourceCount > $databaseCount')
    && str_contains($service, '$databaseCount > $sourceCount'),
    'Invite parity classifier must distinguish missing versus extra DB rows.');
$assert(str_contains($service, '!hash_equals($sourceFingerprint, $databaseFingerprint)'),
    'Equal-count fingerprint mismatch must have its own safe classification.');
$assert(!str_contains($service, "throw new StagingTestPlayerResetStageException(\n                $stage,\n                $error"),
    'Classifier must not expose or reuse a private exception message.');

fwrite(STDOUT, "StagingTestInviteCleanupParityClassifierContractTest: {$assertions} assertions passed\n");
