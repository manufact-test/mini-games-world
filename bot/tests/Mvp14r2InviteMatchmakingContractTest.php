<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read MVP-14R.2.3 source: ' . $path);
    return $content;
};

$endpoint = $read('bot/invites.php');
$service = $read('bot/services/GameInviteService.php');
$creation = $read('bot/services/invites/GameInviteCreationTrait.php');
$actions = $read('bot/services/invites/GameInviteActionTrait.php');
$storage = $read('bot/services/invites/GameInviteStorageTrait.php');
$validation = $read('bot/services/invites/GameInviteValidationTrait.php');
$games = $read('bot/services/GameService.php');
$runtime = $read('bot/services/GameRuntimeService.php');
$chessRuntime = $read('bot/services/ChessRuntimeService.php');
$runner = $read('bot/baseline/JsonInviteMatchmakingBaselineScenario.php')
    . $read('bot/baseline/JsonInviteMatchmakingInviteTrait.php')
    . $read('bot/baseline/JsonInviteMatchmakingQueueTrait.php')
    . $read('bot/baseline/JsonInviteMatchmakingProjectionTrait.php');
$check = $read('ops/checks/mvp14r2-invites-matchmaking-local.sh');
$bootstrap = $read('bot/core/bootstrap.php');

$assert(str_contains($endpoint, "case 'create_link_draft':")
    && str_contains($endpoint, "case 'confirm_shared':")
    && str_contains($endpoint, "case 'open_link':")
    && str_contains($endpoint, "case 'create_direct':"), 'Invite endpoint action surface changed.');
$assert(str_contains($endpoint, "case 'accept':")
    && str_contains($endpoint, "case 'start':")
    && str_contains($endpoint, "case 'cancel':")
    && str_contains($endpoint, "case 'rematch':"), 'Invite lifecycle actions changed.');
$openLinkStart = strpos($endpoint, "case 'open_link':");
$openLinkEnd = strpos($endpoint, "case 'sync':", $openLinkStart ?: 0);
$openLinkBlock = $openLinkStart !== false && $openLinkEnd !== false
    ? substr($endpoint, $openLinkStart, $openLinkEnd - $openLinkStart)
    : '';
$assert(str_contains($openLinkBlock, '$invites->bindFromLink($data, $user, $token, true, false)')
    && str_contains($openLinkBlock, '$core = $invites->sync($data, $user, $token);')
    && !str_contains($openLinkBlock, '$invites->markSeen('),
    'Link opening must preserve and return the unread invite notification.');
$assert(str_contains($endpoint, '$inviteSignals->publish((string)($result[\'recipient_id\'] ?? \'\'), $result[\'invite\'])'), 'Direct invite transient signal publishing changed.');
$assert(str_contains($endpoint, 'in_array($action, [\'accept\', \'decline\', \'cancel\', \'start\'], true)')
    && str_contains($endpoint, '$inviteSignals->clear('), 'Invite terminal-action signal cleanup changed.');
$assert(str_contains($endpoint, 'in_array($action, [\'create_direct\', \'rematch\'], true)')
    && str_contains($endpoint, '$recipientRecent')
    && str_contains($endpoint, 'mgw_send_invite_message'), 'Inactive-recipient Telegram fallback boundary changed.');

$assert(str_contains($service, 'private const INVITE_TTL_SEC = 900;')
    && str_contains($service, 'private const READY_TTL_SEC = 90;'), 'Invite and accepted-start TTLs changed.');
$assert(str_contains($creation, '$this->assertAvailableForInvite($db, $user')
    && str_contains($creation, '$this->assertCanReceiveInvite($db, $invitee'), 'Direct invite availability checks changed.');
$assert(str_contains($creation, '$this->newInvite($db, $user, $gameType, $room, $bet, $boardSize, \'direct\', \'pending\')')
    && str_contains($creation, '$this->addReceivedNotification($db, $invite)'), 'Direct invite pending state or notification changed.');
$assert(str_contains($creation, '$this->newInvite($db, $user, $gameType, $room, $bet, $boardSize, \'link\', \'draft\')')
    && str_contains($creation, '$invite[\'status\'] = \'pending\'')
    && str_contains($creation, '$invite[\'shared_at\'] = $now'), 'Link draft/share lifecycle changed.');
$assert(str_contains($creation, '$invite[\'opened_at\'] = (string)($invite[\'opened_at\'] ?? $now)')
    && str_contains($creation, '$this->hideReceivedNotification($db, $userId'), 'Link binding visibility behavior changed.');

$assert(str_contains($actions, '$invite[\'status\'] = \'awaiting_start\'')
    && str_contains($actions, "gmdate('c', time() + self::READY_TTL_SEC)")
    && str_contains($actions, "'invite_accepted'"), 'Invite acceptance state, deadline or notification changed.');
$assert(substr_count($actions, 'if ((string)($invite[\'source\'] ?? \'\') === \'rematch\')') >= 2
    && str_contains($actions, 'return $this->startInternal($db, $invite, $userId);'), 'Rematch acceptance must auto-start.');
$assert(str_contains($actions, 'Запустить матч может только пригласивший игрок.')
    && str_contains($actions, 'return $this->startInternal($db, $invite, $userId);'), 'Invite start ownership changed.');
$assert(str_contains($actions, '$invite[\'status\'] = \'active\'')
    && str_contains($actions, '$invite[\'game_id\'] = $gameId')
    && substr_count($actions, '$this->markSeen(') >= 2, 'Invite start activation or seen-state behavior changed.');
$assert(str_contains($actions, 'in_array($status, [\'draft\', \'pending\', \'awaiting_start\'], true)')
    && str_contains($actions, "'invite_cancelled'")
    && str_contains($actions, '$otherId = $isOwner'), 'Invite cancellation participant notification changed.');
$assert(str_contains($actions, 'Реванш доступен только после завершённой партии.')
    && str_contains($actions, 'Реванш доступен только с живым соперником.'), 'Rematch eligibility changed.');
$assert(str_contains($actions, '$this->findOpenRematchIndex(')
    && str_contains($actions, "'reused' => true")
    && str_contains($actions, '$acceptResult = $this->accept('), 'Rematch reuse/idempotency changed.');

$assert(str_contains($storage, '$balanceKey = $room === \'gold\' ? \'balance_gold\' : \'balance_match\'')
    && str_contains($storage, 'Недостаточно коинов для выбранной ставки.'), 'Invite creation balance guard changed.');
$assert(str_contains($storage, "'source' => \$source")
    && str_contains($storage, "'expires_at' => gmdate('c', time() + self::INVITE_TTL_SEC)"), 'Stored invite identity/expiry changed.');
$assert(str_contains($storage, "'invite_received'")
    && str_contains($storage, "'invite_rematch_received'"), 'Received invite notification types changed.');
$assert(str_contains($storage, 'if (in_array($type, [\'invite_received\', \'invite_rematch_received\'], true)) return $status === \'pending\';')
    && str_contains($storage, 'if ($type === \'invite_accepted\') return $status === \'awaiting_start\';'), 'Invite notification visibility by status changed.');
$assert(str_contains($validation, '$status = $storedStatus === \'awaiting_start\' ? \'accepted\' : $storedStatus')
    && str_contains($validation, "'can_start' => \$isOwner && \$status === 'accepted'"), 'Public accepted-status compatibility changed.');
$assert(str_contains($validation, 'in_array((string)($invite[\'status\'] ?? \'\'), [\'pending\', \'awaiting_start\'], true)'), 'Open-invite product guard changed.');

$assert(str_contains($games, 'if (($queueItem[\'room\'] ?? \'match\') !== \'match\')')
    && str_contains($games, 'if (time() - $created < $this->botAfterSec())'), 'Bot fallback room or wait threshold changed.');
$humanBeforeBot = strpos($games, '$opponentIndex = $this->findHumanOpponentIndex', strpos($games, 'maybeCreateBotGameForSearchingUser'));
$createBot = strpos($games, '$game = $this->createBotGame', strpos($games, 'maybeCreateBotGameForSearchingUser'));
$assert($humanBeforeBot !== false && $createBot !== false && $humanBeforeBot < $createBot, 'Human opponent must be retried before bot fallback.');
$assert(str_contains($games, '($item[\'room\'] ?? \'\') !== $room')
    && str_contains($games, '(int)($item[\'bet\'] ?? 0) !== $bet')
    && str_contains($games, '(int)($item[\'board_size\'] ?? 0) !== $boardSize'), 'Human matchmaking equality contract changed.');
$assert(str_contains($games, '// Один пользователь — одна запись в очереди.')
    && str_contains($games, '$user[\'status\'] = \'searching\'')
    && str_contains($games, "return ['queued' => true]"), 'Queue creation contract changed.');
$assert(str_contains($games, 'public function leaveSearch(')
    && str_contains($games, '$user[\'status\'] = \'idle\'')
    && str_contains($games, '$user[\'current_game_id\'] = null'), 'Search cancellation state reset changed.');
$assert(str_contains($games, '$a[$balanceKey] = (int)($a[$balanceKey] ?? 0) - $bet;')
    && str_contains($games, '$b[$balanceKey] = (int)($b[$balanceKey] ?? 0) - $bet;')
    && str_contains($games, "'type' => 'game_start'"), 'Human match debit/start ledger contract changed.');
$assert(str_contains($games, '$user[$balanceKey] = (int)($user[$balanceKey] ?? 0) - $bet')
    && str_contains($games, "'is_bot_game' => true")
    && str_contains($games, "'bot_difficulty' => \$botProfile['difficulty']"), 'Bot game debit/metadata contract changed.');
$assert(str_contains($runtime, '$gameType = $this->gameTypeFromRecord($queueItem)')
    && str_contains($runtime, '$this->withIsolatedQueue('), 'Runtime matcher game-type isolation changed.');
$assert(str_contains($chessRuntime, '$this->assertNoOpenInviteBeforeSearch($db, $user);')
    && str_contains($chessRuntime, 'in_array($status, [\'pending\', \'awaiting_start\'], true)'), 'Search must remain blocked by an open invite.');

foreach (['create_direct', 'create_link_draft', 'confirm_shared', 'open_link', 'accept', 'start', 'cancel', 'rematch', 'start_search', 'leave_search', 'bot_fallback'] as $action) {
    $assert(str_contains($runner, "'{$action}' =>"), 'Baseline runner lost action: ' . $action . '.');
}
$assert(str_contains($runner, '$fixture->nextId(')
    && str_contains($runner, '$fixture->resetIdSequences()')
    && !str_contains($runner, 'random_bytes(')
    && !str_contains($runner, 'random_int('), 'Baseline IDs/retries must remain deterministic.');
$assert(!str_contains($runner, 'curl ')
    && !str_contains($runner, "file_get_contents('http")
    && !str_contains($runner, 'PDO(')
    && !str_contains($runner, 'StorageFactory'), 'Baseline runner must remain isolated from network and storage.');
$assert(!str_contains($bootstrap, 'JsonInviteMatchmakingBaselineScenario'), 'Production bootstrap must not load the baseline runner.');
$assert(str_contains($check, 'Mvp14r2InviteMatchmakingBaselineTest.php')
    && str_contains($check, 'Mvp14r2InviteMatchmakingContractTest.php'), 'Focused check must run behavior and source contracts.');
$assert(!str_contains($check, 'curl ')
    && !str_contains($check, 'wget ')
    && !str_contains($check, 'mysql ')
    && !str_contains($check, 'ssh ')
    && !str_contains($check, 'git push'), 'Focused check must not contact live infrastructure.');

fwrite(STDOUT, "Mvp14r2InviteMatchmakingContractTest passed: {$assertions} assertions.\n");
