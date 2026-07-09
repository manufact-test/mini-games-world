<?php
declare(strict_types=1);

final class StatsService
{
    public function build(array $db): array
    {
        $now = time();
        $online = 0;
        foreach ($db['users'] ?? [] as $user) {
            $last = strtotime($user['last_seen_at'] ?? '1970-01-01') ?: 0;
            if ($now - $last <= 300) {
                $online++;
            }
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
            'online_players' => $online,
            'active_games' => $activeGames,
            'search_match' => $searchMatch,
            'search_gold' => $searchGold,
        ];
    }
}
