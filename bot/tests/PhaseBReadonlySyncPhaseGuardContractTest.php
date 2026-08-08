<?php
declare(strict_types=1);

$repo = dirname(dirname(__DIR__));
$syncPath = $repo . '/app/assets/js/production-v110-readonly-game-sync.js';
$shellPath = $repo . '/app/assets/js/main-v110-handoff-shell.js';
$mainPath = $repo . '/app/assets/js/main-v110.js';
$entryPath = $repo . '/app/v110.php';
$manifestPath = $repo . '/bot/helpers/staging-e2e-runtime-files.txt';

$sync = file_get_contents($syncPath);
$shell = file_get_contents($shellPath);
$main = file_get_contents($mainPath);
$entry = file_get_contents($entryPath);
$manifest = file_get_contents($manifestPath);
foreach ([$sync, $shell, $main, $entry, $manifest] as $source) {
    if (!is_string($source)) throw new RuntimeException('Phase B readonly-sync source is unavailable.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$blobPrefix = static function (string $content): string {
    return substr(sha1('blob ' . strlen($content) . "\0" . $content), 0, 12);
};

$assert(
    str_contains($sync, "const launchPhase = String(game?.launch_phase || '');")
        && str_contains($sync, "if (launchPhase && launchPhase !== 'active') return false;"),
    'Readonly game watch must stop for every explicit non-active Phase B launch phase.'
);
$assert(
    !str_contains($sync, "launchPhase === 'active') return false"),
    'Explicit active Phase B games must remain eligible for readonly PvP watch.'
);
$assert(
    str_contains($sync, "if (!gameId || String(game?.status || '') !== 'active') return false;")
        && str_contains($sync, 'if (game?.is_bot_game) return false;'),
    'Existing active-game and bot exclusions must remain unchanged.'
);

$syncBlob = $blobPrefix($sync);
$shellBlob = $blobPrefix($shell);
$mainBlob = $blobPrefix($main);
$assert(
    str_contains($shell, "./production-v110-readonly-game-sync.js?v=1107&b={$syncBlob}"),
    'Shell must content-address the exact readonly-sync blob.'
);
$assert(
    str_contains($main, "./main-v110-handoff-shell.js?v=1135&pending=6&b={$shellBlob}"),
    'Main entry must content-address the exact changed shell blob.'
);
$assert(
    str_contains($entry, "./assets/js/main-v110.js?v=1135&pending=6&b={$mainBlob}"),
    'v110.php must content-address the exact changed main-v110 blob.'
);

$manifestLines = array_values(array_filter(array_map('trim', preg_split('/\R/', $manifest) ?: [])));
$assert(
    count(array_keys($manifestLines, 'app/assets/js/production-v110-readonly-game-sync.js', true)) === 1,
    'Exact staging runtime fingerprint must include the active readonly-sync asset exactly once.'
);

fwrite(STDOUT, "PhaseBReadonlySyncPhaseGuardContractTest: {$assertions} assertions passed\n");
