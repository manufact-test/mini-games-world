<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/database/DatabaseConnectionInterface.php';
require_once $root . '/history/RuntimeHistoryRepository.php';

final class Mvp27_2RecoveryHistoryConnection implements DatabaseConnectionInterface
{
    public array $queries = [];

    public function driver(): string { return 'sqlite'; }
    public function execute(string $sql, array $parameters = []): int
    {
        throw new RuntimeException('Recovery history test must remain read-only.');
    }
    public function fetchValue(string $sql, array $parameters = []): mixed { return null; }
    public function transaction(callable $callback): mixed { return $callback($this); }

    public function fetchAll(string $sql, array $parameters = []): array
    {
        $this->queries[] = [$sql, $parameters];

        if (str_contains($sql, 'FROM mgw_legacy_realtime_shadow')) {
            return [];
        }

        if (str_contains($sql, 'FROM mgw_matches m')) {
            return [[
                'match_id' => 'match_recovery_draw',
                'game_type' => 'four_in_a_row',
                'room' => 'match',
                'status' => 'finished',
                'board_size' => 7,
                'bet' => 100,
                'match_source' => 'runtime',
                'winner_player_ref' => null,
                'finish_reason' => 'draw',
                'server_state_json' => json_encode([
                    'board_columns' => 7,
                    'board_rows' => 6,
                    'payout' => 100,
                    'commission' => 0,
                ], JSON_THROW_ON_ERROR),
                'created_at_utc' => '2026-10-07 16:00:00.000000',
                'started_at_utc' => '2026-10-07 16:00:02.000000',
                'updated_at_utc' => '2026-10-07 16:05:00.000000',
                'finished_at_utc' => '2026-10-07 16:05:00.000000',
            ]];
        }

        if (str_contains($sql, 'FROM mgw_match_players')) {
            return [
                [
                    'seat' => 0,
                    'player_ref' => 'legacy:player_a',
                    'legacy_user_id' => 'player_a',
                    'display_name' => 'Player A',
                    'result' => 'draw',
                ],
                [
                    'seat' => 1,
                    'player_ref' => 'legacy:player_b',
                    'legacy_user_id' => 'player_b',
                    'display_name' => 'Player B',
                    'result' => 'draw',
                ],
            ];
        }

        throw new RuntimeException('Unexpected query in recovery history test: ' . $sql);
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$database = new Mvp27_2RecoveryHistoryConnection();
$repository = (new ReflectionClass(RuntimeHistoryRepository::class))->newInstanceWithoutConstructor();

$databaseProperty = new ReflectionProperty(RuntimeHistoryRepository::class, 'database');
$databaseProperty->setValue($repository, $database);

$method = new ReflectionMethod(RuntimeHistoryRepository::class, 'runtimeReadSnapshot');
$snapshot = $method->invoke($repository, 'player_a', 24);

$game = $snapshot['games']['match_recovery_draw'] ?? null;
$assert(is_array($game), 'Canonical relational match must appear in the live history snapshot.');
$assert(($game['status'] ?? '') === 'finished', 'Finished status must come from mgw_matches.');
$assert(array_key_exists('winner_id', $game) && $game['winner_id'] === null, 'Draw must preserve a null winner.');
$assert(($game['player_ids'] ?? []) === ['player_a', 'player_b'], 'History must project canonical legacy player identities.');
$assert(($game['player_names']['player_b'] ?? '') === 'Player B', 'Opponent display name must come from mgw_match_players.');
$assert((int)($game['board_columns'] ?? 0) === 7 && (int)($game['board_rows'] ?? 0) === 6,
    'Server-state board geometry must survive canonical projection.');
$assert(($game['finished_at'] ?? '') === '2026-10-07T16:05:00+00:00',
    'DB UTC timestamp must be emitted with an explicit UTC offset.');

$queryText = implode("\n", array_map(static fn(array $entry): string => $entry[0], $database->queries));
$assert(str_contains($queryText, 'FROM mgw_matches m'), 'Live history must query mgw_matches.');
$assert(str_contains($queryText, 'me.legacy_user_id = :legacy_user_id'), 'Live history must scope canonical matches to the player.');
$assert(str_contains($queryText, 'FROM mgw_legacy_realtime_shadow'), 'Legacy shadow may remain only as the economy transaction compatibility source.');

$source = file_get_contents($root . '/history/RuntimeHistoryRepository.php');
$assert(is_string($source) && str_contains($source, 'runtimeReadSnapshot($legacyUserId, $limit)'),
    'RuntimeHistoryRepository::read must use the canonical live-history projection.');

fwrite(STDOUT, "Mvp27_2ManualRecoveryHistoryTest: {$assertions} assertions passed\n");
