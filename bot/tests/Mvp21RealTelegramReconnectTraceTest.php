<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/services/TournamentReconnectTraceService.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected=' . var_export($expected, true)
            . ' actual=' . var_export($actual, true));
    }
};

$temp = sys_get_temp_dir() . '/mgw-reconnect-trace-' . bin2hex(random_bytes(6));
if (!mkdir($temp, 0700, true) && !is_dir($temp)) {
    throw new RuntimeException('Could not create trace test directory.');
}

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $target = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($target)) $removeTree($target);
        else @unlink($target);
    }
    @rmdir($path);
};

try {
    $_SERVER['SCRIPT_NAME'] = '/bot/presence.php';
    $GLOBALS['mgw_tournament_reconnect_trace_request_ref'] = 'request-test-ref';
    unset($GLOBALS['mgw_tournament_reconnect_trace_request_action']);

    $trace = new TournamentReconnectTraceService([
        'environment'=>'staging',
        'data_dir'=>$temp,
    ]);
    $assertTrue($trace->enabled(), 'Trace must be enabled on staging only.');

    $db = [
        'users'=>[
            'player-secret-a'=>[
                'id'=>'player-secret-a',
                'status'=>'playing',
                'current_game_id'=>'game-secret-1',
                'reconnect_game_id'=>'game-secret-1',
                'active_session_id'=>'session-secret-a',
                'active_session_at'=>'2026-09-23T18:00:00+00:00',
                'reconnect_until'=>'2026-09-23T18:03:00+00:00',
            ],
            'player-secret-b'=>[
                'id'=>'player-secret-b',
                'status'=>'playing',
                'current_game_id'=>'game-secret-1',
                'active_session_id'=>'session-secret-b',
                'active_session_at'=>'2026-09-23T18:00:00+00:00',
            ],
        ],
        'games'=>[
            'game-secret-1'=>[
                'id'=>'game-secret-1',
                'game_type'=>'tictactoe',
                'match_source'=>'tournament',
                'tournament_id'=>'tournament-secret-1',
                'tournament_round_no'=>1,
                'tournament_pair_no'=>2,
                'tournament_attempt_no'=>1,
                'player_ids'=>['player-secret-a','player-secret-b'],
                'status'=>'active',
                'launch_phase'=>'active',
                'turn'=>'player-secret-a',
                'turn_started_at'=>'2026-09-23T18:00:00+00:00',
                'turn_deadline_at'=>'2026-09-23T18:01:00+00:00',
                'turn_deadline_epoch_ms'=>1790186460000,
                'reconnect_v2'=>[
                    'paused'=>true,
                    'tournament_both_disconnect'=>true,
                    'paused_at_ms'=>1790186400000,
                    'both_deadline_ms'=>1790186580000,
                    'players'=>[
                        'player-secret-a'=>[
                            'disconnected_at_ms'=>1790186400000,
                            'deadline_ms'=>1790186580000,
                        ],
                        'player-secret-b'=>[
                            'disconnected_at_ms'=>1790186401000,
                            'deadline_ms'=>1790186580000,
                        ],
                    ],
                ],
            ],
        ],
    ];

    $context = $trace->tournamentContextForAccount($db, 'player-secret-a');
    $assertTrue(is_array($context), 'Tournament account context must be captured.');
    $encodedContext = json_encode($context, JSON_UNESCAPED_SLASHES);
    $assertTrue(is_string($encodedContext), 'Trace context must be JSON encodable.');
    foreach ([
        'player-secret-a',
        'player-secret-b',
        'game-secret-1',
        'tournament-secret-1',
        'session-secret-a',
    ] as $secret) {
        $assertTrue(
            !str_contains((string)$encodedContext, $secret),
            'Trace context must never expose raw sensitive refs: ' . $secret
        );
    }
    $assertSame(16, strlen((string)($context['account_ref'] ?? '')), 'Hashed refs use bounded fingerprints.');

    $trace->record('presence.reconnect_mutation', [
        'runtime'=>$context,
        'previous_presence'=>$trace->presenceContext([
            'state'=>'background',
            'last_foreground_at'=>1790186390,
            'last_background_at'=>1790186400,
            'tournament_disconnect_fallback'=>true,
            'disconnected_at_ms'=>1790186415000,
        ]),
    ]);
    $tail = $trace->tail();
    $assertSame(1, count($tail), 'Trace tail must expose the durable staging record.');
    $assertSame('presence.reconnect_mutation', (string)($tail[0]['event'] ?? ''), 'Trace event identity must survive.');
    $assertSame('request-test-ref', (string)($tail[0]['request_ref'] ?? ''), 'One request keeps one correlation ref.');
    $assertSame('presence.php', (string)($tail[0]['endpoint'] ?? ''), 'Endpoint identity must be recorded.');

    $traceFile = $temp . '/.runtime/tournament-reconnect-trace.jsonl';
    $raw = (string)file_get_contents($traceFile);
    foreach ([
        'player-secret-a',
        'player-secret-b',
        'game-secret-1',
        'tournament-secret-1',
        'session-secret-a',
    ] as $secret) {
        $assertTrue(!str_contains($raw, $secret), 'Durable trace file must not contain raw sensitive refs.');
    }

    $prodDir = $temp . '/prod';
    $prod = new TournamentReconnectTraceService([
        'environment'=>'production',
        'data_dir'=>$prodDir,
    ]);
    $assertTrue(!$prod->enabled(), 'Trace must be disabled outside staging.');
    $prod->record('must.not.persist', ['value'=>'secret']);
    $assertTrue(
        !is_file($prodDir . '/.runtime/tournament-reconnect-trace.jsonl'),
        'Production trace must not create a file.'
    );

    $presenceSource = (string)file_get_contents($root . '/presence.php');
    $runtimeSource = (string)file_get_contents($root . '/services/ChessRuntimeService.php');
    $diagnosticSource = (string)file_get_contents($root . '/staging-projection-diagnostic.php');
    $assertTrue(
        str_contains($presenceSource, "presence.reconnect_mutation"),
        'Real presence endpoint must record reconnect mutation evidence.'
    );
    $assertTrue(
        str_contains($runtimeSource, "cleanup.stage_changed")
            && str_contains($runtimeSource, "cleanup.reconnect_preflight_mutated"),
        'Runtime cleanup must record preflight and first changed cleanup stage.'
    );
    $assertTrue(
        str_contains($diagnosticSource, "tournament_reconnect_trace"),
        'OIDC staging diagnostic must expose the bounded trace tail.'
    );
} finally {
    $removeTree($temp);
    unset(
        $GLOBALS['mgw_tournament_reconnect_trace_request_ref'],
        $GLOBALS['mgw_tournament_reconnect_trace_request_action']
    );
}

if ($assertions < 20) {
    throw new RuntimeException('MVP-21 real reconnect trace test is too shallow: ' . $assertions);
}
fwrite(STDOUT, "Mvp21RealTelegramReconnectTraceTest: {$assertions} assertions passed\n");
