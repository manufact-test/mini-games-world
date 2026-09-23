<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};

$registration = $read('bot/tournaments/TournamentRegistrationService.php');
$api = $read('bot/api.php');
$client = $read('app/assets/js/api/client.js');
$screen = $read('app/assets/js/screens/tournaments-screen-v1.js');
$realtime = $read('bot/realtime/RealtimeRuntimeBridge.php');
$economy = $read('bot/ledger/EconomyRuntimeBridge.php');
$weekly = $read('bot/weekly/WeeklyBonusRuntimeBridge.php');
$rating = $read('bot/ratings/PerGameRatingRuntimeBridge.php');
$manifest = $read('app/runtime/client/version-manifest.php');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($registration, 'public function publishRegistration(')
    && str_contains($registration, 'publishedRegisteredCount(')
    && str_contains($registration, 'published_at_utc IS NOT NULL'),
    'Tournament registration must have a server-owned publication boundary.');
$assert(str_contains($registration, "'published'=>trim((string)(\$row['published_at_utc'] ?? '')) !== ''"),
    'Viewer snapshot must expose whether its own durable registration is published.');
$assert(str_contains($api, "case 'tournament_registration_publish':")
    && str_contains($api, "tournament_registration_publish' => \$tournaments->publishRegistration("),
    'API must expose the explicit publication acknowledgement action.');
$assert(str_contains($client, 'tournamentRegistrationPublish:')
    && str_contains($screen, 'await api.tournamentRegistrationPublish()'),
    'Client must verify durable registration before explicitly publishing it.');
$assert(str_contains($client, 'async function requestTournamentStatus()')
    && str_contains($client, 'status < 500 || status > 599')
    && str_contains($client, 'window.setTimeout(resolve, 180)')
    && str_contains($client, 'tournamentStatus: () => requestTournamentStatus()'),
    'Read-only tournament status must retry exactly one transient 5xx without retrying mutations.');
$assert(str_contains($manifest, 'client.js?v=1145')
    && str_contains($manifest, 'mvp21_7_1=status-read-retry-v1'),
    'Transient tournament status recovery must publish a fresh client cache identity.');
$assert(!str_contains($screen, 'EXTERNAL_TOURNAMENT_COMMIT_CONFIRM_MS')
    && !str_contains($screen, 'stageExternalTournamentCommit'),
    'Cross-client visibility must not rely on another arbitrary client timer.');

foreach ([
    'tournament_status',
    'tournament_register',
    'tournament_registration_publish',
    'tournament_leave',
    'tournament_match_state',
    'tournament_match_ready',
] as $action) {
    foreach ([
        'RealtimeRuntimeBridge'=>$realtime,
        'EconomyRuntimeBridge'=>$economy,
        'WeeklyBonusRuntimeBridge'=>$weekly,
        'PerGameRatingRuntimeBridge'=>$rating,
    ] as $owner=>$source) {
        $assert(str_contains($source, "'{$action}'"),
            "{$owner} must defer unrelated projection work at {$action} tournament boundary.");
    }
}

$assert(str_contains($screen, 'tournamentRoundSectionsMarkup(')
    && str_contains($screen, 'data-tournament-round-archive=')
    && str_contains($screen, 'tournamentProgressionSnapshot'),
    'Live progression must retain every materialized tournament round as an independent archive section.');

if ($assertions < 32) {
    throw new RuntimeException('MVP-21 corrective v7 contract is too shallow: ' . $assertions);
}
fwrite(STDOUT, "Mvp21_5_6CorrectiveV7ContractTest: {$assertions} assertions passed\n");
