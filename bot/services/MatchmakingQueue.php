<?php
declare(strict_types=1);

/**
 * Platform-neutral matchmaking queue policy.
 *
 * The queue identity is intentionally independent from any transport:
 * internal user id + game type + requested board size + skill band.
 * MVP-17.2 keeps the first eight seconds human-only and progressively widens
 * server-assigned ordinal skill bands while preserving hard server limits.
 */
final class MatchmakingQueue
{
    public const DEFAULT_SKILL_BAND = 'unrated';
    public const HUMAN_PRIORITY_SEC = 8;
    public const SKILL_WIDEN_STEP_SEC = 2;
    public const MAX_SKILL_BAND_DISTANCE = 3;

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
        if ((string)($item['game_type'] ?? 'tictactoe') !== $gameType
            || $this->requestedBoardSize($item) !== $boardSize) {
            return false;
        }

        $candidateBand = $this->normalizeSkillBand($item['skill_band'] ?? null);
        $requestedBand = $this->normalizeSkillBand($skillBand);
        if ($candidateBand === $requestedBand) {
            return true;
        }

        return $this->skillBandsCompatible(
            $candidateBand,
            $requestedBand,
            $this->queueWaitSeconds($item)
        );
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

    public function queueWaitSeconds(?array $item, ?int $now = null): int
    {
        if (!$item) return 0;

        $created = strtotime((string)($item['created_at'] ?? '')) ?: 0;
        if ($created <= 0) return 0;

        return max(0, ($now ?? time()) - $created);
    }

    public function botFallbackAllowed(?array $item, ?int $now = null): bool
    {
        return $this->queueWaitSeconds($item, $now) >= self::HUMAN_PRIORITY_SEC;
    }

    public function allowedSkillDistanceForWait(int $waitSeconds): int
    {
        if ($waitSeconds <= 0) return 0;

        return min(
            self::MAX_SKILL_BAND_DISTANCE,
            intdiv(max(0, $waitSeconds), self::SKILL_WIDEN_STEP_SEC)
        );
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
            'matchmaking_human_match_total' => (int)($telemetry['matchmaking_human_match_total'] ?? 0),
            'matchmaking_bot_match_total' => (int)($telemetry['matchmaking_bot_match_total'] ?? 0),
        ];
    }

    private function skillBandsCompatible(string $candidateBand, string $requestedBand, int $waitSeconds): bool
    {
        if ($candidateBand === self::DEFAULT_SKILL_BAND || $requestedBand === self::DEFAULT_SKILL_BAND) {
            return false;
        }

        $candidateRank = $this->skillBandRank($candidateBand);
        $requestedRank = $this->skillBandRank($requestedBand);
        if ($candidateRank === null || $requestedRank === null) {
            return false;
        }

        return abs($candidateRank - $requestedRank) <= $this->allowedSkillDistanceForWait($waitSeconds);
    }

    /**
     * MVP-17.2 owns only the widening mechanism, not the hidden-skill model.
     * A later skill owner may assign an ordinal token such as band:12. Unknown
     * named bands remain exact-only rather than inventing an ordering here.
     */
    private function skillBandRank(string $band): ?int
    {
        if (!preg_match('/^band:(\d{1,4})$/', $band, $match)) {
            return null;
        }

        $rank = (int)$match[1];
        return $rank >= 0 && $rank <= 9999 ? $rank : null;
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
