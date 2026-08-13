<?php
declare(strict_types=1);

require_once __DIR__ . '/PresenceService.php';

final class StatsService
{
    private const STAGING_TEST_ACCOUNT_IDS = [
        'stg_test_player_a',
        'stg_test_player_b',
    ];

    private PresenceService $presence;

    public function __construct(?PresenceService $presence = null)
    {
        $this->presence = $presence ?? new PresenceService();
    }

    public function build(array $db): array
    {
        $onlineAccounts = [];
        $roomByAccount = [];
        $storageToAccount = $this->storageAccountMap($db);

        if ($this->presence->isEnabled()) {
            foreach ($this->presence->onlineAccountRooms() as $accountId => $room) {
                $accountId = trim((string)$accountId);
                if ($accountId === '' || str_starts_with($accountId, 'bot_')) continue;
                if ($this->hideStagingTestAccount($accountId)) continue;
                $onlineAccounts[$accountId] = true;
                $room = $this->normalizeRoom((string)$room);
                if ($room !== '') $roomByAccount[$accountId] = $room;
            }
        } else {
            // Before the first presence heartbeat, keep a short compatibility
            // window for retained clients. Once presence files exist they become
            // the only online source.
            $now = time();
            foreach ($db['users'] ?? [] as $storageKey => $user) {
                if (!is_array($user)) continue;
                $last = strtotime((string)($user['last_seen_at'] ?? '')) ?: 0;
                if ($last <= 0 || $now - $last > $this->presence->onlineWindowSec()) continue;
                $accountId = $this->accountIdForUser($user, (string)$storageKey);
                if ($accountId === '' || str_starts_with($accountId, 'bot_')) continue;
                if ($this->hideStagingTestAccount($accountId)) continue;
                $onlineAccounts[$accountId] = true;
                $room = $this->normalizeRoom((string)($user['last_matchmaking_room'] ?? ''));
                if ($room !== '') $roomByAccount[$accountId] = $room;
            }
        }

        // Queue/game state is more authoritative than the selected UI room once
        // matchmaking or a match has started. It only refines room ownership for
        // accounts that are actually online; stale queue/game rows never create
        // phantom room occupants.
        foreach ($db['queue'] ?? [] as $item) {
            if (!is_array($item)) continue;
            $storageUserId = trim((string)($item['user_id'] ?? ''));
            if ($storageUserId === '') continue;
            $accountId = $storageToAccount[$storageUserId] ?? $storageUserId;
            if (!isset($onlineAccounts[$accountId]) || $this->hideStagingTestAccount($accountId)) continue;
            $room = $this->normalizeRoom((string)($item['room'] ?? 'match'));
            if ($room !== '') $roomByAccount[$accountId] = $room;
        }

        $activeGames = 0;
        foreach ($db['games'] ?? [] as $game) {
            if (!is_array($game) || ($game['status'] ?? '') !== 'active') continue;
            if ($this->hideStagingTestGame($game)) continue;
            $activeGames++;

            $room = $this->normalizeRoom((string)($game['room'] ?? 'match'));
            if ($room === '') continue;
            foreach ($game['player_ids'] ?? [] as $playerId) {
                $storageUserId = trim((string)$playerId);
                if ($storageUserId === '' || str_starts_with($storageUserId, 'bot_')) continue;
                $accountId = $storageToAccount[$storageUserId] ?? $storageUserId;
                if (!isset($onlineAccounts[$accountId]) || $this->hideStagingTestAccount($accountId)) continue;
                $roomByAccount[$accountId] = $room;
            }
        }

        $roomMatch = 0;
        $roomGold = 0;
        foreach ($onlineAccounts as $accountId => $_) {
            $room = $this->normalizeRoom((string)($roomByAccount[$accountId] ?? ''));
            if ($room === 'gold') $roomGold++;
            elseif ($room === 'match') $roomMatch++;
        }

        return [
            'online_players' => count($onlineAccounts),
            'active_games' => $activeGames,
            // Legacy response keys are retained for client compatibility. Their
            // product meaning is now the label shown in the UI: online players
            // currently in Match/Gold room, not merely queue row counts.
            'search_match' => $roomMatch,
            'search_gold' => $roomGold,
        ];
    }

    /** @return array<string,string> */
    private function storageAccountMap(array $db): array
    {
        $map = [];
        foreach ($db['users'] ?? [] as $storageKey => $user) {
            if (!is_array($user)) continue;
            $storageId = trim((string)($user['id'] ?? $storageKey));
            if ($storageId === '') continue;
            $accountId = $this->accountIdForUser($user, (string)$storageKey);
            if ($accountId !== '') $map[$storageId] = $accountId;
        }
        return $map;
    }

    private function accountIdForUser(array $user, string $storageKey): string
    {
        return trim((string)($user['telegram_id'] ?? $user['id'] ?? $storageKey));
    }

    private function normalizeRoom(string $room): string
    {
        $room = strtolower(trim($room));
        return in_array($room, ['match', 'gold'], true) ? $room : '';
    }

    private function hideStagingTestGame(array $game): bool
    {
        foreach ($game['player_ids'] ?? [] as $playerId) {
            if ($this->hideStagingTestAccount((string)$playerId)) return true;
        }
        return false;
    }

    private function hideStagingTestAccount(string $accountId): bool
    {
        $environment = strtolower(trim((string)($GLOBALS['config']['environment'] ?? '')));
        if ($environment !== 'staging') return false;

        // GitHub Actions authenticates its isolated A/B browser contexts with this
        // HTTP-only staging cookie. Those contexts must still see their own public
        // counters while ordinary Telegram users must never see automation users,
        // queues or matches mixed into the product statistics.
        $testSession = trim((string)($_COOKIE['mgw_staging_test_session'] ?? ''));
        if ($testSession !== '') return false;

        return in_array($accountId, self::STAGING_TEST_ACCOUNT_IDS, true);
    }
}
