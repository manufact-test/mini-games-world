<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = file_get_contents($root . '/bot/services/StagingTestPlayerStateResetService.php');
$endpoint = file_get_contents($root . '/bot/staging-test-auth.php');
if (!is_string($service) || !is_string($endpoint)) {
    throw new RuntimeException('Cannot read reset stage diagnostic sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$stages = ['availability','json_state','notification_cleanup','invite_cleanup','economy'];
foreach ($stages as $stage) {
    $assert(str_contains($service, "StagingTestPlayerResetStageException('{$stage}'"), "Missing safe reset stage {$stage}.");
}
$assert(str_contains($service, "parent::__construct('Staging test-player reset stage failed.', 0, \$previous);"),
    'Public reset-stage exception must not copy the private previous message.');
$assert(str_contains($endpoint, "'error' => 'test_player_reset_unavailable'")
    && str_contains($endpoint, "'stage' => \$error->stage()"),
    'OIDC reset endpoint must return only the fixed safe error and stage.');
$assert(!str_contains($endpoint, '$error->getMessage()'),
    'Staging auth endpoint must never return an exception message.');
$assert(str_contains($endpoint, "error_log('[MiniGamesWorld staging test reset] failed stage=' . \$error->stage());"),
    'Server logging must contain only the safe reset stage.');

fwrite(STDOUT, "StagingTestPlayerResetStageDiagnosticContractTest: {$assertions} assertions passed\n");
