<?php
declare(strict_types=1);

require_once __DIR__ . '/PresenceService.php';

final class StatsService
{
    private PresenceService $presence;

    public function __construct(?PresenceService $presence = null)
    {
        $this->presence = $presence ?? new PresenceService();
    }

    public function build(array $db): array
    {
        $now = time();
        $onlineAccounts = [];

        foreach ($db['users'] ?? [] as $storageKey => $user) {
            if (!is_array($user) || !$this->presence->isOnline($user, $now)) continue;

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
