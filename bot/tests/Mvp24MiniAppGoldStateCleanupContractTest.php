<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read MVP-24.2 source: ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$state = $read('app/assets/js/state.js');
$config = $read('app/assets/js/config.js');
$search = $read('app/assets/js/screens/search-screen-v102.js');
$launcher = $read('app/assets/js/games/unified-game-launcher.js');
$apiClient = $read('app/assets/js/api/client.js');
$home = $read('app/assets/js/screens/home-screen.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$invites = $read('app/assets/js/games/game-invites-v110.js');
$inviteLink = $read('app/assets/js/games/invite-link-entry-v110r12.js');
$serverApi = $read('bot/api.php');
$serverInvites = $read('bot/invites.php');
$manifest = require $root . '/app/runtime/client/version-manifest.php';

$assert(!str_contains($state, "room: 'match'") && !str_contains($state, 'selectedBet:'),
    'Client state must not own obsolete Match/Gold room or selected-bet fields.');
$assert(!str_contains($config, 'shopHistoryBase')
        && !str_contains($config, 'defaultRoom')
        && !str_contains($config, 'goldBets')
        && !str_contains($config, 'shopMinOrder'),
    'Client config must not publish legacy Gold/order configuration.');
$assert(str_contains($search, 'api.startSearch(context.size, context.gameType)')
        && !str_contains($search, 'state.room')
        && !str_contains($search, 'state.selectedBet')
        && !str_contains($search, 'goldBets')
        && !str_contains($search, 'roomName(')
        && !str_contains($search, 'context.room')
        && !str_contains($search, 'context.bet'),
    'Search owner must be room-neutral and send only the game variant to canonical matchmaking.');
$assert(!str_contains($launcher, 'state.room')
        && !str_contains($launcher, 'state.selectedBet')
        && !str_contains($launcher, "room:'match'"),
    'Unified launcher must not recreate a legacy room/bet client owner.');
$assert(str_contains($apiClient, "startSearch: (boardSize, gameType = 'tictactoe')")
        && !str_contains($apiClient, 'shopStatus:')
        && !str_contains($apiClient, 'shopOrders:')
        && !str_contains($apiClient, 'shopOrder:')
        && !str_contains($apiClient, 'paymentCreateDraft:')
        && !str_contains($apiClient, 'shopHistoryBase'),
    'API client must drop legacy Gold commerce and room/bet search methods.');
$assert(str_contains($apiClient, 'cosmeticStoreStatus:')
        && str_contains($apiClient, 'cosmeticStorePurchase:'),
    'Modern cosmetics Store API must remain intact.');
$assert(!str_contains($home, 'export function setRoom')
        && !str_contains($shell, 'state.selectedBet = matchEntryCost'),
    'Boot/Home owners must not rehydrate obsolete room/bet state.');
$assert(!str_contains($invites, "room:'match'")
        && !str_contains($invites, 'data-invite-bet')
        && !str_contains($invites, 'data-invite-bets')
        && !str_contains($invites, '<span>Ставка</span>')
        && str_contains($invites, '<span>Участие</span>')
        && str_contains($invites, 'Стоимость участия'),
    'Invite UI must expose one fixed participation cost without legacy room/stake selection.');
$assert(!str_contains($inviteLink, 'Gold-комната')
        && !str_contains($inviteLink, 'Матч-комната')
        && !str_contains($inviteLink, '<span>Комната</span>')
        && !str_contains($inviteLink, '<span>Ставка</span>')
        && str_contains($inviteLink, '<span>Участие</span>'),
    'Deep-link invite UI must not expose Match/Gold room terminology.');
$assert(str_contains($serverApi, '$room = UnifiedGameZonePolicy::storageRoom();')
        && str_contains($serverApi, '$bet = UnifiedGameZonePolicy::entryCost($config);'),
    'Server start_search must remain the canonical room/entry-cost owner.');
$assert(str_contains($serverInvites, '$room = UnifiedGameZonePolicy::storageRoom();')
        && str_contains($serverInvites, '$bet = UnifiedGameZonePolicy::entryCost($config);'),
    'Server invite creation must remain canonical when client room/bet inputs disappear.');

$imports = is_array($manifest['imports'] ?? null) ? $manifest['imports'] : [];
$assert(str_contains((string)($imports['./assets/js/api/client.js?v=47'] ?? ''), 'mvp24=gold-client-state-v2')
        && str_contains((string)($imports['./assets/js/screens/search-screen-v102.js?v=103'] ?? ''), 'mvp24=room-neutral-client-v2')
        && str_contains((string)($imports['./assets/js/games/unified-game-launcher.js?v=1&mvp16=unified-game-setup'] ?? ''), 'mvp24=room-neutral-client-v2'),
    'Active manifest must cache-bust the room-neutral client owners.');

fwrite(STDOUT, "Mvp24MiniAppGoldStateCleanupContractTest: {$assertions} assertions passed\n");
