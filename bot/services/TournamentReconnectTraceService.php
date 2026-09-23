<?php
declare(strict_types=1);

/**
 * Staging-only, privacy-safe trace for the real Telegram tournament reconnect path.
 * It is diagnostic evidence only and never owns gameplay/reconnect/settlement state.
 */
final class TournamentReconnectTraceService
{
    private const MAX_FILE_BYTES = 2097152;
    private const MAX_TAIL = 240;

    private bool $enabled;
    private string $path;
    private string $requestRef;

    public function __construct(private array $config)
    {
        $this->enabled = strtolower(trim((string)($config['environment'] ?? ''))) === 'staging';
        $dataDir = trim((string)($config['data_dir'] ?? ''));
        if ($dataDir === '') $dataDir = dirname(__DIR__) . '/data';
        $this->path = rtrim($dataDir, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . '.runtime'
            . DIRECTORY_SEPARATOR . 'tournament-reconnect-trace.jsonl';

        $globalKey = 'mgw_tournament_reconnect_trace_request_ref';
        $existing = trim((string)($GLOBALS[$globalKey] ?? ''));
        if ($existing === '') {
            try {
                $existing = substr(hash('sha256', microtime(true) . '|' . bin2hex(random_bytes(12))), 0, 16);
            } catch (Throwable) {
                $existing = substr(hash('sha256', microtime(true) . '|' . getmypid()), 0, 16);
            }
            $GLOBALS[$globalKey] = $existing;
        }
        $this->requestRef = $existing;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function ref(string $value): string
    {
        $value = trim($value);
        return $value === ''
            ? ''
            : substr(hash('sha256', 'mgw-tournament-reconnect-trace-v1|' . $value), 0, 16);
    }

    public function record(string $event, array $context = []): void
    {
        if (!$this->enabled) return;

        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) return;

        $event = preg_replace('/[^a-zA-Z0-9_.:-]/', '_', trim($event)) ?: 'unknown';
        $entry = [
            'ts'=>sprintf('%.6f', microtime(true)),
            'utc'=>gmdate(DATE_ATOM),
            'request_ref'=>$this->requestRef,
            'endpoint'=>basename((string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['SCRIPT_FILENAME'] ?? 'cli')),
            'event'=>$event,
            'context'=>$this->sanitize($context),
        ];
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($line)) return;

        $handle = @fopen($this->path, 'c+');
        if (!is_resource($handle)) return;
        try {
            if (!@flock($handle, LOCK_EX)) return;
            $stat = @fstat($handle);
            if (is_array($stat) && (int)($stat['size'] ?? 0) > self::MAX_FILE_BYTES) {
                @ftruncate($handle, 0);
                @rewind($handle);
            } else {
                @fseek($handle, 0, SEEK_END);
            }
            @fwrite($handle, $line . PHP_EOL);
            @fflush($handle);
            @flock($handle, LOCK_UN);
        } finally {
            @fclose($handle);
        }
    }

    public function tournamentContextForAccount(array $db, string $accountId): ?array
    {
        $accountId = trim($accountId);
        if ($accountId === '' || !isset($db['users'][$accountId]) || !is_array($db['users'][$accountId])) {
            return null;
        }

        $user = $db['users'][$accountId];
        $gameId = trim((string)($user['current_game_id'] ?? ''));
        if ($gameId === '') $gameId = trim((string)($user['reconnect_game_id'] ?? ''));
        if ($gameId === '' || !isset($db['games'][$gameId]) || !is_array($db['games'][$gameId])) {
            return null;
        }

        $game = $db['games'][$gameId];
        if ((string)($game['match_source'] ?? '') !== 'tournament') return null;

        return [
            'account_ref'=>$this->ref($accountId),
            'user'=>$this->userContext($user),
            'game'=>$this->gameContext($game),
        ];
    }

    public function tournamentGames(array $db): array
    {
        $result = [];
        foreach (is_array($db['games'] ?? null) ? $db['games'] : [] as $game) {
            if (!is_array($game) || (string)($game['match_source'] ?? '') !== 'tournament') continue;
            if (!in_array((string)($game['status'] ?? ''), ['active','finished'], true)) continue;
            $result[] = $this->gameContext($game);
        }
        return $result;
    }

    public function gameContext(array $game): array
    {
        $players = [];
        foreach (array_map('strval', $game['player_ids'] ?? []) as $playerId) {
            if ($playerId === '') continue;
            $players[] = $this->ref($playerId);
        }

        $reconnect = is_array($game['reconnect_v2'] ?? null) ? $game['reconnect_v2'] : [];
        $reconnectPlayers = [];
        foreach (is_array($reconnect['players'] ?? null) ? $reconnect['players'] : [] as $playerId=>$state) {
            if (!is_array($state)) continue;
            $reconnectPlayers[$this->ref((string)$playerId)] = [
                'disconnected_at_ms'=>(int)($state['disconnected_at_ms'] ?? 0),
                'deadline_ms'=>(int)($state['deadline_ms'] ?? 0),
                'individual_deadline_ms'=>(int)($state['individual_deadline_ms'] ?? 0),
            ];
        }

        return [
            'game_ref'=>$this->ref((string)($game['id'] ?? '')),
            'tournament_ref'=>$this->ref((string)($game['tournament_id'] ?? '')),
            'round_no'=>(int)($game['tournament_round_no'] ?? 0),
            'pair_no'=>(int)($game['tournament_pair_no'] ?? 0),
            'attempt_no'=>(int)($game['tournament_attempt_no'] ?? 0),
            'game_type'=>(string)($game['game_type'] ?? ''),
            'match_source'=>(string)($game['match_source'] ?? ''),
            'status'=>(string)($game['status'] ?? ''),
            'launch_phase'=>(string)($game['launch_phase'] ?? ''),
            'finish_reason'=>(string)($game['finish_reason'] ?? ''),
            'winner_ref'=>$this->ref((string)($game['winner_id'] ?? '')),
            'loser_ref'=>$this->ref((string)($game['loser_id'] ?? '')),
            'turn_ref'=>$this->ref((string)($game['turn'] ?? '')),
            'players'=>$players,
            'turn_started_at'=>(string)($game['turn_started_at'] ?? ''),
            'turn_deadline_at'=>(string)($game['turn_deadline_at'] ?? ''),
            'turn_deadline_epoch_ms'=>(int)($game['turn_deadline_epoch_ms'] ?? 0),
            'reconnect'=>[
                'paused'=>!empty($reconnect['paused']),
                'tournament_both_disconnect'=>!empty($reconnect['tournament_both_disconnect']),
                'paused_at_ms'=>(int)($reconnect['paused_at_ms'] ?? 0),
                'both_disconnected_at_ms'=>(int)($reconnect['both_disconnected_at_ms'] ?? 0),
                'both_deadline_ms'=>(int)($reconnect['both_deadline_ms'] ?? 0),
                'players'=>$reconnectPlayers,
            ],
        ];
    }

    public function userContext(array $user): array
    {
        return [
            'user_ref'=>$this->ref((string)($user['id'] ?? '')),
            'status'=>(string)($user['status'] ?? ''),
            'current_game_ref'=>$this->ref((string)($user['current_game_id'] ?? '')),
            'reconnect_game_ref'=>$this->ref((string)($user['reconnect_game_id'] ?? '')),
            'active_session_ref'=>$this->ref((string)($user['active_session_id'] ?? '')),
            'active_session_at'=>(string)($user['active_session_at'] ?? ''),
            'reconnect_until'=>(string)($user['reconnect_until'] ?? ''),
        ];
    }

    public function presenceContext(array $snapshot): array
    {
        return [
            'state'=>(string)($snapshot['state'] ?? 'unknown'),
            'last_foreground_at'=>(int)($snapshot['last_foreground_at'] ?? 0),
            'last_background_at'=>(int)($snapshot['last_background_at'] ?? 0),
            'tournament_disconnect_fallback'=>!empty($snapshot['tournament_disconnect_fallback']),
            'disconnected_at_ms'=>(int)($snapshot['disconnected_at_ms'] ?? 0),
        ];
    }

    public function tail(int $limit = self::MAX_TAIL): array
    {
        if (!$this->enabled || !is_file($this->path)) return [];
        $limit = max(1, min(self::MAX_TAIL, $limit));
        $raw = @file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($raw)) return [];
        $rows = array_slice($raw, -$limit);
        $result = [];
        foreach ($rows as $line) {
            $decoded = json_decode((string)$line, true);
            if (is_array($decoded)) $result[] = $decoded;
        }
        return $result;
    }

    private function sanitize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 6) return '[depth]';
        if (is_null($value) || is_bool($value) || is_int($value) || is_float($value)) return $value;
        if (is_string($value)) return substr($value, 0, 320);
        if (!is_array($value)) return get_debug_type($value);

        $result = [];
        $count = 0;
        foreach ($value as $key=>$item) {
            if ($count++ >= 80) break;
            $safeKey = substr((string)$key, 0, 80);
            $result[$safeKey] = $this->sanitize($item, $depth + 1);
        }
        return $result;
    }
}
