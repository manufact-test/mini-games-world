<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Missing source: ' . $path);
    return $content;
};
$blobPrefix = static function (string $content): string {
    return substr(sha1('blob ' . strlen($content) . "\0" . $content), 0, 12);
};

$safePath = 'app/assets/js/screens/game-screen-v102-safe.js';
$acceptancePath = 'app/assets/js/production-v110-acceptance-runtime.js';
$safe = $read($safePath);
$acceptance = $read($acceptancePath);
$v110 = $read('app/v110.php');
$manifest = $read('bot/helpers/staging-e2e-runtime-files.txt');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$safePrefix = $blobPrefix($safe);
$acceptancePrefix = $blobPrefix($acceptance);
$assert($safePrefix === '76d5b9d8d659', 'Safe game-screen blob prefix must match the reviewed content-address value.');
$assert($acceptancePrefix === 'afd6d9a46d1a', 'Acceptance runtime blob prefix must match the reviewed content-address value.');
$assert(
    str_contains($v110, 'game-screen-v102-safe.js?v=102&b=' . $safePrefix),
    'v110 import map must content-address the active safe game-screen wrapper.'
);
$assert(
    str_contains($v110, 'production-v110-acceptance-runtime.js?v=110&b=' . $acceptancePrefix),
    'v110 import map must content-address the active acceptance runtime.'
);
$assert(str_contains($manifest, $safePath), 'Safe game-screen wrapper must be included in exact staging fingerprint coverage.');
$assert(str_contains($manifest, $acceptancePath), 'Acceptance runtime must be included in exact staging fingerprint coverage.');

$assert(str_contains($safe, 'const PREACTIVE_POLL_MS = 400;'), 'Pre-active primary polling cadence must stay explicitly bounded at 400ms.');
$assert(
    str_contains($safe, "phase === 'preparing' || phase === 'countdown'")
        && str_contains($safe, 'APP_CONFIG.gameIntervalMs = Math.min(acceptedInterval, PREACTIVE_POLL_MS);'),
    'Only preparing/countdown entry may temporarily tighten primary game polling.'
);
$assert(
    str_contains($safe, 'export function restoreAcceptedGamePolling(game = null)')
        && str_contains($safe, 'if (status === \'active\') startGamePolling(id);'),
    'Safe wrapper must restore the accepted game polling cadence after launch.'
);

$assert(str_contains($acceptance, 'restoreAcceptedGamePolling(state.activeGame);'), 'Acceptance runtime must drive poll-cadence restoration from authoritative state.');
$assert(str_contains($acceptance, 'candidateDeadline + 700 < runtime.clock.deadline'), 'Same-turn clock snapshots must never extend the local deadline.');
$assert(str_contains($acceptance, 'candidateStart + 250 < runtime.clock.start'), 'Same launch/turn snapshots must never extend the local start anchor.');
$assert(str_contains($acceptance, "phase === 'preparing' || phase === 'preparation_timeout'"), 'Clock UI must preserve the full timeout before a turn starts.');
$assert(str_contains($acceptance, "phase === 'countdown' && !launchStartReached(game)"), 'Countdown actions must remain blocked until the shared start anchor is reached.');
$assert(str_contains($acceptance, "phase === 'preparing' || phase === 'preparation_timeout' || phase === 'cancelled'"), 'Pre-start/cancelled Tic-Tac-Toe actions must be blocked before optimistic pending state is created.');
$assert(str_contains($acceptance, "button.dataset.mgwPhaseBDisabled = '1'"), 'Leave control must be disabled only through an owned Phase B marker.');
$assert(str_contains($acceptance, 'id="mgwPhaseBLaunchOverlay"'), 'Launch overlay must have one deterministic DOM owner.');
$assert(str_contains($acceptance, "title.textContent = 'Синхронизируем игроков'"), 'Preparing overlay copy must be state-driven.');
$assert(str_contains($acceptance, "title.textContent = 'Матч начинается'"), 'Countdown overlay copy must be state-driven.');

fwrite(STDOUT, "PhaseBClientLaunchUxContractTest: {$assertions} assertions passed\n");
