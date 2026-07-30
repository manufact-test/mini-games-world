<?php
declare(strict_types=1);

use Mgw\CleanRuntime\Server\Auth\RuntimeAuthenticationService;
use Mgw\CleanRuntime\Server\Auth\TelegramInitDataVerifier;
use Mgw\CleanRuntime\Server\Context\RuntimeRequestContextFactory;
use Mgw\CleanRuntime\Server\Match\RuntimeMatchService;
use Mgw\CleanRuntime\Server\Match\TicTacToeRules;
use Mgw\CleanRuntime\Server\RuntimeApplicationService;
use Mgw\CleanRuntime\Server\RuntimeConfig;
use Mgw\CleanRuntime\Server\Session\RuntimeSessionService;
use Mgw\CleanRuntime\Server\Storage\JsonFileRuntimeStore;

$root = dirname(__DIR__, 2);
foreach ([
    '/app/runtime/server/contracts/RuntimeStateStore.php',
    '/app/runtime/server/RuntimeConfig.php',
    '/app/runtime/server/auth/AuthenticationException.php',
    '/app/runtime/server/auth/AuthenticatedIdentity.php',
    '/app/runtime/server/auth/TelegramInitDataVerifier.php',
    '/app/runtime/server/auth/RuntimeAuthenticationService.php',
    '/app/runtime/server/context/RuntimeRequestContext.php',
    '/app/runtime/server/context/RuntimeRequestContextFactory.php',
    '/app/runtime/server/storage/JsonFileRuntimeStore.php',
    '/app/runtime/server/session/RuntimeSessionService.php',
    '/app/runtime/server/match/TicTacToeRules.php',
    '/app/runtime/server/match/RuntimeMatchService.php',
    '/app/runtime/server/RuntimeApplicationService.php',
] as $file) require_once $root . $file;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$cleanup = static function (string $directory) use (&$cleanup): void {
    if (!is_dir($directory)) return;
    foreach (scandir($directory) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $directory . '/' . $item;
        if (is_dir($path)) $cleanup($path); else @unlink($path);
    }
    @rmdir($directory);
};
$command = static function (string $suffix): string {
    return 'cmd_' . str_pad(preg_replace('/[^a-z0-9]/i', '', $suffix), 28, '0');
};
$payload = static function (string $installation, string $session): array {
    return [
        'installation_id' => $installation,
        'session_id' => $session,
        'init_data' => '',
        'launch' => [
            'runtime' => 'mgw-clean-v1',
            'path' => '/app/runtime/index.php',
            'source' => 'standard',
            'invite_present' => false,
            'telegram_available' => false,
        ],
        'presence' => [
            'visibility' => 'visible',
            'platform' => 'ci',
            'timezone_offset' => 0,
        ],
    ];
};

$temporary = sys_get_temp_dir() . '/mgw-clean-match-' . bin2hex(random_bytes(8));
try {
    $config = new RuntimeConfig(
        environment: 'staging',
        dataDirectory: $temporary,
        build: 'test-clean-match-v3',
        allowBrowserStagingIdentity: true,
        matchBet: 10,
        initialMatchBalance: 100,
        queueTimeoutSec: 120,
        moveTimeoutSec: 60,
        commissionRate: 0.10,
    );
    $authentication = new RuntimeAuthenticationService($config, new TelegramInitDataVerifier('', 86400, 300));
    $application = new RuntimeApplicationService(
        $config,
        new JsonFileRuntimeStore($temporary),
        new RuntimeRequestContextFactory($authentication),
        new RuntimeSessionService($config),
        new RuntimeMatchService($config, new TicTacToeRules()),
    );

    $payloadA = $payload('install_match_a_12345678901234567890', 'session_match_a_12345678901234567890');
    $payloadB = $payload('install_match_b_12345678901234567890', 'session_match_b_12345678901234567890');
    $bootA = $application->bootstrap($payloadA);
    $bootB = $application->bootstrap($payloadB);
    $accountA = (string)$bootA['account']['id'];
    $accountB = (string)$bootB['account']['id'];
    $assert($accountA !== $accountB, 'Two clean installations must create two staging players.');
    $assert($bootA['balances']['match'] === 100 && $bootB['balances']['match'] === 100, 'Both players must start with isolated staging balances.');

    $searchA = $application->startSearch($payloadA + ['command_id' => $command('search_a_one')]);
    $assert($searchA['matchmaking']['status'] === 'searching', 'The first player must enter one authoritative queue.');
    $assert($searchA['account']['status'] === 'searching', 'The account projection must match the queue state.');

    $searchB = $application->startSearch($payloadB + ['command_id' => $command('search_b_one')]);
    $assert($searchB['matchmaking'] === null && is_array($searchB['active_match']), 'The second player must create one match atomically.');
    $gameId = (string)$searchB['active_match']['id'];
    $assert($searchB['balances']['match'] === 90, 'The second player entry must be deducted once.');

    $stateFile = $temporary . '/runtime-state-v3.json';
    $revisionAfterMatch = (int)$searchB['storage']['revision'];
    $stateBeforeSync = file_get_contents($stateFile);
    $syncA = $application->syncMatch($payloadA);
    $syncARepeat = $application->syncMatch($payloadA);
    $stateAfterSync = file_get_contents($stateFile);
    $assert(($syncA['active_match']['id'] ?? null) === $gameId, 'The first player must observe the same game revision.');
    $assert($syncA['balances']['match'] === 90, 'The first player entry must be deducted once.');
    $assert($syncA['session']['locked'] === false && $searchB['session']['locked'] === false, 'Both active match sessions must remain owned and unlocked.');
    $assert($syncA['storage']['revision'] === $revisionAfterMatch && $syncARepeat['storage']['revision'] === $revisionAfterMatch, 'Read-only match polling must not advance storage revision.');
    $assert($stateBeforeSync === $stateAfterSync, 'Read-only match polling must not rewrite the staging state file.');

    $payloadByAccount = [$accountA => $payloadA, $accountB => $payloadB];
    $projection = $syncA;
    foreach ([0, 3, 1, 4, 2] as $index => $cell) {
        $turn = (string)($projection['active_match']['turn'] ?? '');
        $assert(isset($payloadByAccount[$turn]), 'Every move must name one of the two authoritative players.');
        $movePayload = $payloadByAccount[$turn] + [
            'game_id' => $gameId,
            'cell' => $cell,
            'command_id' => $command('move_' . $index . '_' . $turn),
        ];
        $projection = $application->move($movePayload);
        if ($index < 4) {
            $assert(is_array($projection['active_match']) && $projection['match_result'] === null, 'Intermediate moves must keep exactly one active match.');
        }
    }

    $assert($projection['active_match'] === null && is_array($projection['match_result']), 'The winning move must return the finished result directly without waiting for polling.');
    $winnerId = (string)$projection['match_result']['winner_id'];
    $loserId = $winnerId === $accountA ? $accountB : $accountA;
    $assert($projection['match_result']['outcome'] === 'win', 'The moving winner must receive a win result.');
    $winnerPayload = $payloadByAccount[$winnerId];
    $loserPayload = $payloadByAccount[$loserId];
    $finishRevision = (int)$projection['storage']['revision'];
    $winnerSync = $application->syncMatch($winnerPayload);
    $loserSync = $application->syncMatch($loserPayload);
    $assert($winnerSync['storage']['revision'] === $finishRevision && $loserSync['storage']['revision'] === $finishRevision, 'Result observation must be read-only for both players.');
    $assert($winnerSync['balances']['match'] === 108, 'Winner balance must be 100 - 10 + 18.');
    $assert($loserSync['balances']['match'] === 90, 'Loser balance must be 100 - 10.');
    $assert($loserSync['match_result']['outcome'] === 'loss', 'The opponent must receive the corresponding loss result.');
    $assert($winnerSync['account']['status'] === 'idle' && $loserSync['account']['status'] === 'idle', 'Both players must be released to idle in the finish transaction.');
    $assert($winnerSync['session']['locked'] === false && $loserSync['session']['locked'] === false, 'Both sessions must be immediately reusable after finish.');

    $winnerDismiss = $application->dismissResult($winnerPayload + ['command_id' => $command('dismiss_winner')]);
    $assert($winnerDismiss['match_result'] === null, 'A result dismissal must clear only the viewer result.');
    $winnerSearch = $application->startSearch($winnerPayload + ['command_id' => $command('winner_search_again')]);
    $assert($winnerSearch['matchmaking']['status'] === 'searching', 'The winner must start a new search immediately after release.');
    $winnerCancel = $application->cancelSearch($winnerPayload + ['command_id' => $command('winner_cancel_again')]);
    $assert($winnerCancel['matchmaking'] === null && $winnerCancel['account']['status'] === 'idle', 'Search cancellation must release the same account without another owner.');

    $application->dismissResult($loserPayload + ['command_id' => $command('dismiss_loser')]);
    $application->startSearch($payloadA + ['command_id' => $command('search_a_two')]);
    $secondGame = $application->startSearch($payloadB + ['command_id' => $command('search_b_two')]);
    $secondGameId = (string)$secondGame['active_match']['id'];
    $assert($secondGameId !== '' && $secondGameId !== $gameId, 'A second clean match must use a new game id.');

    $surrenderCommand = $command('surrender_once');
    $surrender = $application->surrender($payloadA + [
        'game_id' => $secondGameId,
        'command_id' => $surrenderCommand,
    ]);
    $assert($surrender['active_match'] === null && $surrender['match_result']['finish_reason'] === 'surrender', 'Surrender must return the finished result directly without waiting for polling.');
    $balanceAfterSurrender = (int)$surrender['balances']['match'];
    $duplicateSurrender = $application->surrender($payloadA + [
        'game_id' => $secondGameId,
        'command_id' => $surrenderCommand,
    ]);
    $assert($duplicateSurrender['balances']['match'] === $balanceAfterSurrender, 'A duplicate surrender command must not change balance twice.');

    $stored = file_get_contents($stateFile);
    $decoded = json_decode((string)$stored, true, 512, JSON_THROW_ON_ERROR);
    $finishRows = array_values(array_filter(
        $decoded['ledger'] ?? [],
        fn(array $row): bool => ($row['type'] ?? '') === 'match_finish' && ($row['game_id'] ?? '') === $secondGameId
    ));
    $assert(count($finishRows) === 1, 'One surrendered game must create exactly one finish ledger row.');
    $assert(($decoded['games'][$secondGameId]['payout_done'] ?? false) === true, 'The surrendered game must persist an atomic settlement guard.');
    $assert(($decoded['system']['fees_match'] ?? null) === 4, 'Two completed winner games must collect exactly two commissions of two.');
    $assert(count($decoded['queue'] ?? []) === 0, 'No queue record may remain after a completed match.');
} finally {
    $cleanup($temporary);
}

fwrite(STDOUT, "Mvp14R3CleanTicTacToeMatchTest: {$assertions} assertions passed\n");
