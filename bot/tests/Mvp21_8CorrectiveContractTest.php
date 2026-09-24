<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $value = file_get_contents($root . '/' . $path);
    if (!is_string($value)) throw new RuntimeException('Cannot read ' . $path);
    return $value;
};

$api = $read('bot/api.php');
$screen = $read('app/assets/js/screens/tournaments-screen-v1.js');
$main = $read('app/assets/js/main-v110-handoff-shell.js');
$profile = $read('app/assets/js/screens/profile-screen-v110.js');
$manifest = $read('app/runtime/client/version-manifest.php');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($api, 'function mgw_observe_finished_tournament_game('),
    'Terminal tournament games must have a direct durable progression observer.');
$assert(substr_count($api, 'mgw_observe_finished_tournament_game(') >= 5,
    'All normal terminal game response paths must feed the durable tournament progression owner.');
$assert(str_contains($api, 'TournamentRoundProgressionService($database))->observeFinishedGame($game)'),
    'Terminal observer must use the canonical round progression service.');
$assert(str_contains($api, '$readyGameAlreadyAttached')
        && str_contains($api, "TournamentMatchReadinessService::STATE_LAUNCHED"),
    'An already-attached first-round game must not be relaunched after settlement.');

$assert(str_contains($screen, 'let tournamentTerminalSyncPromise = null;')
        && str_contains($screen, 'let tournamentTerminalReturnPending = false;'),
    'Tournament return must own an explicit terminal synchronization state.');
$assert(!str_contains($screen, "tournamentProgressionSnapshot = null;\n    tournamentMatchError = '';\n    tournamentTerminalReturnPending = true;"),
    'Returning from a result must not erase the already-synchronized durable progression snapshot.');
$assert(str_contains($screen, 'Сохраняем результат турнира…'),
    'Terminal return must show result persistence rather than falling back to old readiness copy.');
$assert(str_contains($screen, 'if (tournamentTerminalSyncPromise) return tournamentTerminalSyncPromise;'),
    'Terminal progression synchronization must be single-flight.');
$assert(str_contains($screen, "document.addEventListener('mgw:game-finished'")
        && str_contains($screen, 'tournamentTerminalReturnPending = true;'),
    'Finished tournament games must prime the return path before the result sheet is dismissed.');

$registeredOpen = strpos($screen, 'else if (open && registered && !full)');
$cancel = strpos($screen, 'Отменить регистрацию', $registeredOpen ?: 0);
$consent = strpos($screen, 'Подтвердить правила', $registeredOpen ?: 0);
$assert($registeredOpen !== false && $cancel !== false,
    'Open registered participants must retain a cancellation CTA.');
$assert($consent !== false && $cancel !== false,
    'Stale consent handling must coexist with cancellation instead of replacing it.');

$profilePromise = strpos($main, 'const profilePromise = api.mgwProfile();');
$prestigePromise = strpos($main, 'const prestigePromise = api.tournamentPrestige().catch(() => null);');
$bootstrap = strpos($main, 'const result = await api.bootstrap();');
$profileAwait = strpos($main, 'const [mgwProfileResult, prestigeResult] = await Promise.all([profilePromise, prestigePromise]);');
$ready = strpos($main, 'dispatchAppReady();');
$assert($profilePromise !== false && $prestigePromise !== false && $bootstrap !== false && $profileAwait !== false
        && $profilePromise < $bootstrap && $prestigePromise < $bootstrap && $bootstrap < $profileAwait,
    'Bootstrap, canonical profile and lightweight prestige reads must overlap instead of paying sequential round trips.');
$assert($ready !== false && $profileAwait < $ready,
    'Canonical identity and lightweight prestige must still be ready before app-ready is published.');

$assert(str_contains($profile, 'deferWhileHidden:true')
        && str_contains($profile, 'scheduleProfileRenderIdle();'),
    'Background Profile hydration must converge through the bounded idle render owner.');
$assert(str_contains($profile, 'requestIdleCallback(flushScheduledProfileRender, { timeout:1800 })')
        && str_contains($profile, "document.documentElement.classList.contains('mgw-profile-route-settling')"),
    'Deferred full Profile rendering must run as idle work and yield during the first route settle window.');
$assert(str_contains($profile, 'navigator.scheduling.isInputPending({ includeContinuous:true })')
        && !str_contains($profile, 'flushHiddenProfileRenderOnEntry')
        && !str_contains($profile, 'PROFILE_ROUTE_TRANSITION_MS + 40'),
    'First Profile entry must not own the expensive full-DOM convergence task.');
$assert(str_contains($profile, 'globalThis.setTimeout(warm, 0)')
        && str_contains($profile, 'Date.now() - lastFullProfileSnapshotAt < 5000'),
    'Profile must start its read-only warm promptly and suppress an immediate duplicate read on entry.');
$bootStart = strpos($main, 'async function boot(){');
$bootEnd = strpos($main, 'function shouldPrimeMobileProfile', $bootStart ?: 0);
$bootBody = ($bootStart !== false && $bootEnd !== false)
    ? substr($main, $bootStart, $bootEnd - $bootStart)
    : '';
$assert(str_contains($bootBody, 'await primeMobileProfileFirstPresentation()')
        && str_contains($main, "classList.add('mgw-profile-prewarm-pass')")
        && str_contains($main, 'window.requestAnimationFrame(() => {')
        && str_contains($main, "if (String(result?.active_game?.id || '').trim()) return false;"),
    'Cold mobile Profile may complete one bounded covered raster pass under the preloader, but never on active-game reloads.');
$assert(!str_contains($bootBody, 'await primeTournamentFirstPresentation()'),
    'Hidden Tournament raster warm must not block first usable paint.');
$assert(str_contains($main, "import('./screens/store-screen.js?v=34')")
        && !str_contains($main, "import { initStoreScreen, openStoreTab } from './screens/store-screen.js?v=34';")
        && str_contains($main, 'warmStoreScreenAfterFirstPaint();')
        && str_contains($main, 'window.requestIdleCallback(warm, { timeout:1200 });'),
    'The large Store module graph must be lazy and warmed only after first usable paint.');

$assert(str_contains($manifest, 'main-v110-handoff-shell.js?v=1157')
        && str_contains($manifest, 'startup=parallel-bootstrap-profile-v1')
        && str_contains($manifest, 'startup=nonblocking-hidden-warm-v1')
        && str_contains($manifest, 'store_warm=post-first-paint-v1')
        && str_contains($manifest, 'profile_first=covered-raster-prewarm-v2'),
    'Startup corrective must publish a fresh main client identity.');
$assert(str_contains($manifest, 'tournaments-screen-v1.js?v=31')
        && str_contains($manifest, 'mvp21_8=corrective-v8')
        && str_contains($manifest, 'mvp21_manual=acceptance-corrective-v1')
        && str_contains($manifest, 'registration_cancel=restored-v1')
        && str_contains($manifest, 'mvp21_6=terminal-return-preserve-v5')
        && str_contains($manifest, 'archive=per-round-v1')
        && str_contains($manifest, 'desktop=endurance-v1'),
    'Tournament corrective must publish a fresh v8 client identity.');

if ($assertions < 22) {
    throw new RuntimeException('Corrective v8 contract is too shallow: ' . $assertions);
}
fwrite(STDOUT, "Mvp21_8CorrectiveContractTest: {$assertions} assertions passed\n");
