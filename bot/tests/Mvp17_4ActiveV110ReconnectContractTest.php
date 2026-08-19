<?php
declare(strict_types=1);

$repoRoot = dirname(__DIR__, 2);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$read = static function (string $path): string {
    $content = file_get_contents($path);
    if (!is_string($content)) throw new RuntimeException('Unable to read ' . $path);
    return $content;
};

$launch = $read($repoRoot . '/bot/helpers/WebAppLaunchUrl.php');
$v110 = $read($repoRoot . '/app/v110.php');
$manifestSource = $read($repoRoot . '/app/runtime/client/version-manifest.php');
$manifest = require $repoRoot . '/app/runtime/client/version-manifest.php';
$wrapper = $read($repoRoot . '/app/assets/js/main-v110-reconnect-v174.js');
$reconnect = $read($repoRoot . '/app/assets/js/production-v110-reconnect-v174.js');
$presence = $read($repoRoot . '/app/assets/js/production-v110-presence.js');
$clock = $read($repoRoot . '/bot/services/MatchPreparationClockService.php');

$assert(
    str_contains($launch, "private const ENTRY_PATH = '/app/v110.php?v=1127';"),
    'The Telegram/Test WebApp launch owner must be recognized as v110; reconnect tests must not target an inactive entry graph.'
);
$assert(
    str_contains($v110, "'@mgw/main'") && str_contains($v110, "runtime/client/version-manifest.php"),
    'v110 must continue to render its main graph through the canonical version manifest.'
);
$assert(
    is_array($manifest)
        && str_contains((string)($manifest['imports']['@mgw/main'] ?? ''), 'main-v110-reconnect-v174.js'),
    'The real v110 @mgw/main route must pass through the MVP-17.4 reconnect wrapper.'
);
$assert(
    str_contains((string)($manifest['imports']['./assets/js/production-v110-presence.js?v=1121&b=f5a28b030c69'] ?? ''), 'mvp17=reconnect-v2'),
    'The active v110 presence import must be cache-busted to the reconnect-aware implementation.'
);
$assert(
    str_contains($wrapper, "import './production-v110-reconnect-v174.js?v=1';")
        && str_contains($wrapper, "import './main-v110.js?v=1139"),
    'Reconnect must wrap the accepted v110 shell rather than replace or fork its game/UI implementation.'
);
$assert(
    !str_contains($wrapper, 'reconnect-diagnostic-r5.js'),
    'Temporary reconnect diagnostics must not remain in the accepted user-facing v110 graph.'
);

$bootstrapWrap = strpos($reconnect, 'api.bootstrap = async');
$presenceWait = strpos($reconnect, 'await waitForV110InitialPresence();');
$authoritativeState = strpos($reconnect, 'const activeState = await authoritativeGameState(gameId, true);');
$assert(
    is_int($bootstrapWrap)
        && is_int($presenceWait)
        && is_int($authoritativeState)
        && $bootstrapWrap < $presenceWait
        && $presenceWait < $authoritativeState,
    'A reopened v110 document must await presence and authoritative game_state before returning its bootstrap active_game.'
);
$assert(
    str_contains($reconnect, "document.addEventListener('mgw:v110-presence-ready'")
        && str_contains($reconnect, 'return await api.gameState(gameId);')
        && str_contains($reconnect, 'enterGame(result.game, result.me || null);'),
    'A same-document v110 return must refresh through mutating game_state and the existing game renderer after presence resumes.'
);

$backgroundSignal = strpos($presence, "sendLifecycleBeacon('background');");
$leaveSignal = strpos($presence, 'sendLeaveBeacon();');
$assert(
    is_int($backgroundSignal) && is_int($leaveSignal),
    'The active v110 presence owner must distinguish background from a real page leave.'
);
$assert(
    str_contains($presence, 'export function waitForV110InitialPresence()'),
    'The active v110 boot owner must be able to await its first presence handshake.'
);
$assert(
    str_contains($presence, "document.dispatchEvent(new CustomEvent('mgw:v110-presence-ready'))"),
    'Successful v110 foreground resume must publish one reconnect-resume signal to the authoritative game_state owner.'
);
$assert(
    str_contains($manifestSource, "'@mgw/main' => './assets/js/main-v110-reconnect-v174.js?v=2'"),
    'The accepted active v110 reconnect wrapper must have a post-diagnostic cache identity in the manifest source.'
);
$assert(
    preg_match('/\bMOVE_TIMEOUT_SEC\s*=\s*60\s*;/', $clock) === 1,
    'MVP-17.4 active-route integration must not change the normal 60-second move timer.'
);

fwrite(STDOUT, "Mvp17_4ActiveV110ReconnectContractTest: {$assertions} assertions passed\n");
