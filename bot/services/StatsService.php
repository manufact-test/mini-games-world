<?php
declare(strict_types=1);

require_once __DIR__ . '/PresenceService.php';

final class StatsService
{
    private const STAGING_TEST_ACCOUNT_IDS = ['stg_test_player_a', 'stg_test_player_b'];
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
      if ($accountId === '' || str_starts_with($accountId, 'bot_') || $this->hideStagingTestAccount($accountId)) continue;
      $onlineAccounts[$accountId] = true;
  }
        } else {
  $now = time();
  foreach ($db['users'] ?? [] as $storageKey => $user) {
      if (!is_array($user)) continue;
      $last = strtotime((string)($user['last_seen_at'] ?? '')) ?: 0;
      if ($last <= 0 || $now - $last > $this->presence->onlineWindowSec()) continue;
      $accountId = $this->accountIdForUser($user, (string)$storageKey);
      if ($accountId === '' || str_starts_with($accountId, 'bot_') || $this->hideStagingTestAccount($accountId)) continue;
      $onlineAccounts[$accountId] = true;
  }
        }

        $activeGames = 0;
        foreach ($db['games'] ?? [] as $game) {
  if (!is_array($game) || ($game['status'] ?? '') !== 'active') continue;
  if ($this->hideStagingTestGame($game)) continue;
  $activeGames++;
        }

        return [
  'online_players' => count($onlineAccounts),
  'active_games' => $activeGames,
        ];
    }

    private function accountIdForUser(array $user, string $storageKey): string
    {
        return trim((string)($user['telegram_id'] ?? $user['id'] ?? $storageKey));
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
        if (trim((string)($_COOKIE['mgw_staging_test_session'] ?? '')) !== '') return false;
        return in_array($accountId, self::STAGING_TEST_ACCOUNT_IDS, true);
    }
}
