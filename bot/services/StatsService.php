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
        if ($this->presence->isEnabled()) {
            foreach ($this->presence->onlineAccountIds() as $accountId) {
                $accountId = trim((string)$accountId);
                if ($accountId === '' || str_starts_with($accountId, 'bot_')) continue;
                if ($this->hideStagingTestAccount($accountId)) continue;
                $onlineAccounts[$accountId] = true;
            }
        } else {
            // Before the first v104 heartbeat, keep a short compatibility window
            // for an already-open retained client. Presence files become the only
            // source as soon as v104 is active.
            $now = time();
            foreach ($db['users'] ?? [] as $storageKey => $user) {
                if (!is_array($user)) continue;
                $last = strtotime((string)($user['last_seen_at'] ?? '')) ?: 0;
                if ($last <= 0 || $now - $last > $this->presence->onlineWindowSec()) continue;
                $accountId = trim((string)($user['telegram_id'] ?? $user['id'] ?? $storageKey));
                if ($accountId === '' || str_starts_with($accountId, 'bot_')) continue;
                if ($this->hideStagingTestAccount($accountId)) continue;
                $onlineAccounts[$accountId] = true;
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
            'online_players' => count($onlineAccounts),
            'active_games' => $activeGames,
            'search_match' => $searchMatch,
            'search_gold' => $searchGold,
        ];
    }

    private function hideStagingTestAccount(string $accountId): bool
    {
        $environment = strtolower(trim((string)($GLOBALS['config']['environment'] ?? '')));
        if ($environment !== 'staging') return false;

        // GitHub Actions authenticates its isolated A/B browser contexts with this
        // HTTP-only staging cookie. Those contexts must still see each other so the
        // presence regression remains meaningful, while ordinary Telegram users must
        // never see automation accounts in the public online-player number.
        $testSession = trim((string)($_COOKIE['mgw_staging_test_session'] ?? ''));
        if ($testSession !== '') return false;

        return in_array($accountId, self::STAGING_TEST_ACCOUNT_IDS, true);
    }
}
