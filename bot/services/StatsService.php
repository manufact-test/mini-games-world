<?php
declare(strict_types=1);

final class StatsService
{
    private const ONLINE_WINDOW_SEC = 75;

    public function build(array $db): array
    {
        $now = time();
        $onlineAccounts = [];

        foreach ($db['users'] ?? [] as $storageKey => $user) {
            if (!is_array($user)) continue;

            $last = strtotime((string)($user['last_seen_at'] ?? '1970-01-01')) ?: 0;
            if ($now - $last > self::ONLINE_WINDOW_SEC) continue;

            $accountId = trim((string)($user['telegram_id'] ?? $user['id'] ?? $storageKey));
            if ($accountId === '' || str_starts_with($accountId, 'bot_')) continue;

            $onlineAccounts[$accountId] = true;
        }

        $activeGames = 0;
        foreach ($db['games'] ?? [] as $game) {
            if (($game['status'] ?? '') === 'active') {
                $activeGames++;
            }
        }

        $searchMatch = 0;
        $searchGold = 0;
        foreach ($db['queue'] ?? [] as $item) {
            if (($item['room'] ?? '') === 'gold') {
                $searchGold++;
            } else {
                $searchMatch++;
            }
        }

        return [
            'online_players' => count($onlineAccounts),
            'active_games' => $activeGames,
            'search_match' => $searchMatch,
            'search_gold' => $searchGold,
        ];
    }
}
