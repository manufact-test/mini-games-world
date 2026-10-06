<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/localization/ServerLocalization.php';

final class InviteOpponentService
{
    private const MAX_ITEMS = 10;
    private const RECENT_WINDOW_SEC = 86400 * 30;

    /**
     * @param array<string,mixed> $data
     * @param list<string> $onlineAccountIds
     * @return list<array<string,mixed>>
     */
    public function list(array $data, string $userId, array $onlineAccountIds): array
    {
        $userId = trim($userId);
        if ($userId === '') return [];

        $onlineIds = array_fill_keys(array_map('strval', $onlineAccountIds), true);
        $lastGameAt = $this->lastFinishedGames($data, $userId);
        $viewer = is_array($data['users'][$userId] ?? null) ? $data['users'][$userId] : [];
        $viewerCanSeeTestUsers = $this->isStagingTestIdentity($userId, $viewer);
        $result = [];

        foreach ($data['users'] ?? [] as $candidateId => $candidate) {
            $candidateId = (string)$candidateId;
            if ($candidateId === ''
                || $candidateId === $userId
                || str_starts_with($candidateId, 'bot_')
                || !is_array($candidate)
                || (!$viewerCanSeeTestUsers && $this->isStagingTestIdentity($candidateId, $candidate))) {
                continue;
            }

            $presenceOnline = isset($onlineIds[$candidateId]);
            $lastSeen = strtotime((string)($candidate['last_seen_at'] ?? '')) ?: 0;
            $hasHistory = isset($lastGameAt[$candidateId]);
            if (!$presenceOnline
                && !$hasHistory
                && ($lastSeen <= 0 || time() - $lastSeen > self::RECENT_WINDOW_SEC)) {
                continue;
            }

            $activity = $this->activity($candidate, $presenceOnline);
            $gameTime = strtotime((string)($lastGameAt[$candidateId] ?? '')) ?: 0;
            $score = (!empty($activity['online']) ? 10000000000 : 0)
                + (!empty($activity['busy']) ? 1000000000 : 0)
                + ($hasHistory ? 100000000 : 0)
                + max($gameTime, $lastSeen);

            $result[] = [
                'id' => $candidateId,
                'name' => $this->name($candidate),
                'activity' => (string)$activity['label'],
                'online' => (bool)$activity['online'],
                'busy' => (bool)$activity['busy'],
                'last_game_at' => (string)($lastGameAt[$candidateId] ?? ''),
                'last_seen_at' => (string)($candidate['last_seen_at'] ?? ''),
                '_score' => $score,
            ];
        }

        usort($result, static function (array $left, array $right): int {
            $scoreCompare = (int)($right['_score'] ?? 0) <=> (int)($left['_score'] ?? 0);
            return $scoreCompare !== 0
                ? $scoreCompare
                : strcasecmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
        });

        $result = array_slice($result, 0, self::MAX_ITEMS);
        foreach ($result as &$item) unset($item['_score']);
        unset($item);
        return $result;
    }

    private function isStagingTestIdentity(string $userId, array $user): bool
    {
        if (!empty($user['is_staging_test_user']) || !empty($user['is_dev_user'])) {
            return true;
        }
        if ((string)($user['mgw_identity_provider'] ?? '') === 'staging_fixture') {
            return true;
        }
        foreach (['stg_test_player_', 'stg_tour_', 'dev_'] as $prefix) {
            if (str_starts_with($userId, $prefix)) return true;
        }
        return false;
    }

    /** @return array<string,string> */
    private function lastFinishedGames(array $data, string $userId): array
    {
        $lastGameAt = [];
        foreach ($data['games'] ?? [] as $game) {
            if (!is_array($game)
                || (string)($game['status'] ?? '') !== 'finished'
                || !empty($game['is_bot_game'])) {
                continue;
            }

            $players = array_values(array_map('strval', $game['player_ids'] ?? []));
            if (count($players) !== 2 || !in_array($userId, $players, true)) continue;
            $opponentId = $players[0] === $userId ? ($players[1] ?? '') : ($players[0] ?? '');
            if ($opponentId === '' || str_starts_with($opponentId, 'bot_')) continue;

            $timestamp = (string)($game['finished_at'] ?? $game['updated_at'] ?? $game['created_at'] ?? '');
            $current = strtotime((string)($lastGameAt[$opponentId] ?? '')) ?: 0;
            $candidate = strtotime($timestamp) ?: 0;
            if ($candidate >= $current) $lastGameAt[$opponentId] = $timestamp;
        }
        return $lastGameAt;
    }

    /** @return array{label:string,online:bool,busy:bool} */
    private function activity(array $user, bool $presenceOnline): array
    {
        $status = (string)($user['status'] ?? 'idle');
        $lastSeen = strtotime((string)($user['last_seen_at'] ?? '')) ?: 0;
        $secondsAgo = $lastSeen > 0 ? max(0, time() - $lastSeen) : null;

        if ($status === 'playing') return ['label' => ServerLocalization::copy('server.invite_chain.opponents.playing', 'playing now'), 'online' => true, 'busy' => true];
        if ($status === 'searching') return ['label' => ServerLocalization::copy('server.invite_chain.opponents.searching', 'looking for an opponent'), 'online' => true, 'busy' => true];
        if ($presenceOnline) return ['label' => ServerLocalization::copy('server.invite_chain.opponents.online', 'online'), 'online' => true, 'busy' => false];
        if ($secondsAgo !== null && $secondsAgo <= 3600) return ['label' => ServerLocalization::copy('server.invite_chain.opponents.recent', 'seen recently'), 'online' => false, 'busy' => false];
        if ($secondsAgo !== null && $secondsAgo <= 86400 * 7) return ['label' => ServerLocalization::copy('server.invite_chain.opponents.week', 'seen this week'), 'online' => false, 'busy' => false];
        return ['label' => ServerLocalization::copy('server.invite_chain.opponents.recent_player', 'recent player'), 'online' => false, 'busy' => false];
    }

    private function name(array $user): string
    {
        $username = trim((string)($user['username'] ?? ''));
        if ($username !== '') return '@' . ltrim($username, '@');
        $name = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
        return $name !== '' ? $name : ServerLocalization::copy('server.invites.player_fallback', 'Player');
    }
}
