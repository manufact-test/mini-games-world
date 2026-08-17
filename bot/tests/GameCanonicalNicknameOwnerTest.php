<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$resolver = file_get_contents($root . '/bot/accounts/RuntimeAccountIdentityResolver.php');
$userService = file_get_contents($root . '/bot/services/UserService.php');
$gameService = file_get_contents($root . '/bot/services/GameService.php');
$gameRuntime = file_get_contents($root . '/bot/services/GameRuntimeService.php');
$specialRuntime = file_get_contents($root . '/bot/services/ChessRuntimeService.php');
$catalog = file_get_contents($root . '/bot/services/GameCatalogService.php');
$gameScreen = file_get_contents($root . '/app/assets/js/screens/game-screen-v102.js');
$manifest = file_get_contents($root . '/bot/helpers/staging-e2e-runtime-files.txt');

foreach ([
    'resolver'=>$resolver,
    'user_service'=>$userService,
    'game_service'=>$gameService,
    'game_runtime'=>$gameRuntime,
    'special_runtime'=>$specialRuntime,
    'catalog'=>$catalog,
    'game_screen'=>$gameScreen,
    'manifest'=>$manifest,
] as $name => $source) {
    if (!is_string($source)) throw new RuntimeException("Unable to read {$name} source.");
}

$assertions = 0;
$assertContains = static function (string $needle, string $haystack, string $message) use (&$assertions): void {
    $assertions++;
    if (!str_contains($haystack, $needle)) throw new RuntimeException($message . ': missing ' . $needle);
};
$assertNotContains = static function (string $needle, string $haystack, string $message) use (&$assertions): void {
    $assertions++;
    if (str_contains($haystack, $needle)) throw new RuntimeException($message . ': forbidden ' . $needle);
};

// One verified provider -> canonical MGW identity bridge.
$assertContains('$canonical = $accounts->findByIdentity(', $resolver, 'Runtime resolver must read canonical account data after provider verification');
$assertContains('$user[\'mgw_nickname\'] = $nickname', $resolver, 'Runtime resolver must carry canonical nickname downstream');
$assertContains('$user[\'mgw_avatar_item_id\'] = $avatarItemId', $resolver, 'Runtime resolver must carry canonical avatar downstream');

// Existing shared legacy projection already owns all visible runtime identity fields.
$assertContains('$incomingNickname = trim((string)($authenticatedUser[\'mgw_nickname\'] ?? \'\'))', $userService, 'UserService must consume the verified MGW nickname');
$assertContains('$user[\'first_name\'] = $user[\'mgw_nickname\']', $userService, 'Canonical nickname must own visible legacy first_name');
$assertContains('$user[\'username\'] = \'\'', $userService, 'Provider username must not override visible game identity');
$assertContains('$this->syncCanonicalGameIdentity($db, $db[\'users\'][$id]);', $userService, 'Active game identity must be synchronized through one shared owner');
$assertContains('$db[\'games\'][$gameId][\'player_names\'][$userId] = $nickname', $userService, 'Active game player_names must receive canonical nickname');

// New matches keep one legacy creation path; no renderer-specific nickname patching.
$assertContains('$aId => $a[\'username\'] ?: $a[\'first_name\']', $gameService, 'First human slot must consume the already canonicalized runtime user');
$assertContains('$bId => $b[\'username\'] ?: $b[\'first_name\']', $gameService, 'Second human slot must consume the already canonicalized runtime user');
$assertContains('$userId => $user[\'username\'] ?: $user[\'first_name\']', $gameService, 'Bot match human slot must consume the already canonicalized runtime user');
$assertContains('$this->base->startSearch(', $specialRuntime, 'Non-special engines must keep the shared base runtime');
$assertContains('$this->legacyGame->startSearch(', $specialRuntime, 'Chess/Go/Domino must keep the shared legacy matcher metadata path');

// All eight games are catalog consumers of the same identity-bearing game record.
foreach (['tictactoe','four_in_a_row','battleship','checkers','reversi','chess','go','domino'] as $gameType) {
    $assertContains("'{$gameType}' =>", $catalog, "Catalog must retain shared game identity coverage for {$gameType}");
}
$assertContains('(game.players || []).map(player =>', $gameScreen, 'Match-room UI must render the shared public players projection');
$assertContains('escapeHtml(player.name)', $gameScreen, 'Visible match-room name must come from shared player.name');

// The newly changed runtime owner must participate in exact Hostinger fingerprinting.
$assertContains('bot/accounts/RuntimeAccountIdentityResolver.php', $manifest, 'Canonical game identity resolver must be deployment fingerprinted');

// Task 4 must not move identity ownership into per-game client renderers.
$assertNotContains('mgw_nickname', $gameScreen, 'Shared game screen must not implement a local nickname override');

fwrite(STDOUT, "GameCanonicalNicknameOwnerTest: {$assertions} assertions passed\n");
