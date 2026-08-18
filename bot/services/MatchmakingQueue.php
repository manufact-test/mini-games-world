<?php
declare(strict_types=1);

/**
 * Platform-neutral matchmaking queue policy.
 *
 * The queue identity is intentionally independent from any transport:
 * internal user id + game type + requested board size + skill band.
 * Progressive skill widening is deliberately out of scope for MVP-17.1.
 */
final class MatchmakingQueue
{
    public const DEFAULT_SKILL_BAND = 'unrated';

    public function normalizeSkillBand(mixed $value): string
    {
        $band = strtolower(trim((string)$value));
        if ($band === '' || strlen($band) > 64) {
            return self::DEFAULT_SKILL_BAND;
        }

        if (!preg_match('/^[a-z0-9._:-]+$/', $band)) {
            return self::DEFAULT_SKILL_BAND;
        }

        return $band;
    }

    public function matchesKey(array $item, string $gameType, int $boardSize, string $skillBand): bool
    {
        return (string)($item['game_type'] ?? 'tictactoe') === $gameType
            && $this->requestedBoardSize($item) === $boardSize
            && $this->normalizeSkillBand($item['skill_band'] ?? null) === $this->normalizeSkillBand($skillBand);
    }

    public function firstCandidate(
        array $db,
        string $userId,
        string $gameType,
        int $boardSize,
        string $skillBand
    ): ?array {
        foreach ($db['queue'] ?? [] as $item) {
            if (!is_array($item) || !$this->matchesKey($item, $gameType, $boardSize, $skillBand)) {
                continue;
            }

            $candidateId = (string)($item['user_id'] ?? '');
            if ($candidateId === '' || $candidateId === $userId || !isset($db['users'][$candidateId])) {
                continue;
            }
            if (($db['users'][$candidateId]['status'] ?? '') !== 'searching') {
                continue;
            }
            if ($this->activeGameForUser($db, $candidateId) !== null) {
                continue;
            }

            return $item;
        }

        return null;
    }

    public function activeGameForUser(array $db, string $userId): ?array
    {
        if ($userId === '') return null;

        foreach ($db['games'] ?? [] as $game) {
            if (!is_array($game) || ($game['status'] ?? '') !== 'active') {
                continue;
            }
            if (in_array($userId, array_map('strval', $game['player_ids'] ?? []), true)) {
                return $game;
            }
        }

        return null;
    }

    /**
     * Removes impossible queue records whose users already own an active game.
     * This is the matchmaking-level one-active-game guard.
     */
    public function purgeActiveGameQueueEntries(array &$db): int
    {
        $removed = 0;
        $queue = [];

        foreach ($db['queue'] ?? [] as $item) {
            if (!is_array($item)) continue;
            $userId = (string)($item['user_id'] ?? '');
            if ($userId !== '' && $this->activeGameForUser($db, $userId) !== null) {
                $removed++;
                continue;
            }
            $queue[] = $item;
        }

        $db['queue'] = array_values($queue);
        if ($removed > 0) {
            $this->incrementDuplicatePrevented($db, $removed);
        }
        $this->observeQueueDepth($db);

        return $removed;
    }

    public function preventDuplicateMatch(array &$db, string $userId): void
    {
        if ($userId !== '') {
            $db['queue'] = array_values(array_filter(
                $db['queue'] ?? [],
                static fn($item): bool => !is_array($item) || (string)($item['user_id'] ?? '') !== $userId
            ));
        }

        $this->incrementDuplicatePrevented($db, 1);
        $this->observeQueueDepth($db);
    }

    public function observeQueueDepth(array &$db): void
    {
        $this->ensureTelemetry($db);
        $db['system']['telemetry']['matchmaking_queue_depth'] = count($db['queue'] ?? []);
    }

    public function observeWaitFromQueueItem(array &$db, ?array $item): void
    {
        if (!$item) return;

        $created = strtotime((string)($item['created_at'] ?? '')) ?: 0;
        if ($created <= 0) return;

        $this->ensureTelemetry($db);
        $db['system']['telemetry']['matchmaking_wait_ms'] = max(0, (time() - $created) * 1000);
    }

    public function telemetry(array $db): array
    {
        $telemetry = is_array($db['system']['telemetry'] ?? null) ? $db['system']['telemetry'] : [];
        return [
            'matchmaking_queue_depth' => (int)($telemetry['matchmaking_queue_depth'] ?? count($db['queue'] ?? [])),
            'matchmaking_wait_ms' => (int)($telemetry['matchmaking_wait_ms'] ?? 0),
            'matchmaking_duplicate_match_prevented_total' => (int)($telemetry['matchmaking_duplicate_match_prevented_total'] ?? 0),
        ];
    }

    private function requestedBoardSize(array $item): int
    {
        $requested = (int)($item['requested_board_size'] ?? 0);
        if ($requested > 0) return $requested;

        $variant = (int)($item['game_variant_size'] ?? 0);
        if ($variant > 0) return $variant;

        return (int)($item['board_size'] ?? 0);
    }

    private function incrementDuplicatePrevented(array &$db, int $amount): void
    {
        $this->ensureTelemetry($db);
        $current = (int)($db['system']['telemetry']['matchmaking_duplicate_match_prevented_total'] ?? 0);
        $db['system']['telemetry']['matchmaking_duplicate_match_prevented_total'] = $current + max(0, $amount);
    }

    private function ensureTelemetry(array &$db): void
    {
        if (!isset($db['system']) || !is_array($db['system'])) {
            $db['system'] = [];
        }
        if (!isset($db['system']['telemetry']) || !is_array($db['system']['telemetry'])) {
            $db['system']['telemetry'] = [];
        }
    }
}
