<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$repoRoot = dirname($root);

require_once $root . '/services/BotProfilePolicy.php';
require_once $root . '/services/GameInviteService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$policy = new BotProfilePolicy();

$humanStored = [
    'id' => 'human-finished',
    'status' => 'finished',
    'player_ids' => ['u1', 'u2'],
];
$humanPublic = $policy->sanitizePublicGame([
    'id' => 'human-finished',
    'status' => 'finished',
    'players' => [['id'=>'u1'], ['id'=>'u2']],
], $humanStored);
$assert(
    ($humanPublic['rematch_available'] ?? null) === true,
    'A finished two-human match must expose only the neutral direct-rematch capability.'
);

$botStored = [
    'id' => 'auto-finished',
    'status' => 'finished',
    'is_bot_game' => true,
    'bot_id' => 'bot_auto_1',
    'bot_name' => 'Sam',
    'bot_difficulty' => 'hard',
    'player_ids' => ['u1', 'bot_auto_1'],
    'player_names' => ['u1'=>'Player', 'bot_auto_1'=>'Sam'],
];
$botPublic = $policy->sanitizePublicGame([
    'id' => 'auto-finished',
    'status' => 'finished',
    'is_bot_game' => true,
    'bot_id' => 'bot_auto_1',
    'bot_difficulty' => 'hard',
    'players' => [['id'=>'u1','name'=>'Player'], ['id'=>'bot_auto_1','name'=>'Sam']],
], $botStored);
$assert(
    ($botPublic['rematch_available'] ?? null) === false,
    'An automated match must expose only rematch_available=false, never the technical opponent type.'
);
$assert(
    !array_key_exists('is_bot_game', $botPublic)
        && !array_key_exists('bot_id', $botPublic)
        && !array_key_exists('bot_difficulty', $botPublic),
    'Bot technical markers must remain absent from the result projection.'
);

$reflection = new ReflectionClass(GameInviteService::class);
$inviteService = $reflection->newInstanceWithoutConstructor();
$botDb = ['games' => ['auto-finished' => $botStored]];
$botUser = ['id' => 'u1'];
$neutralError = '';
try {
    $inviteService->createRematch($botDb, $botUser, 'auto-finished');
} catch (RuntimeException $error) {
    $neutralError = $error->getMessage();
}
$assert(
    $neutralError === 'Реванш сейчас недоступен. Выберите «Сыграть ещё».',
    'A stale client rematch request must receive neutral copy without disclosing opponent type.'
);
$assert(
    !str_contains($neutralError, 'бот')
        && !str_contains($neutralError, 'AI')
        && !str_contains($neutralError, 'жив'),
    'Fallback rematch copy must not reveal or strongly imply automation.'
);

$clientPolicy = file_get_contents($repoRoot . '/app/assets/js/games/game-invites-v110-rematch-policy-v175.js');
$legacyInvites = file_get_contents($repoRoot . '/app/assets/js/games/game-invites-v110.js');
$manifest = require $repoRoot . '/app/runtime/client/version-manifest.php';
$launch = file_get_contents($root . '/helpers/WebAppLaunchUrl.php');
$inviteServiceSource = file_get_contents($root . '/services/GameInviteService.php');

$assert(is_string($clientPolicy), 'Active v110 rematch presentation policy must exist.');
$assert(
    is_string($clientPolicy)
        && str_contains($clientPolicy, 'game?.rematch_available === true')
        && !str_contains($clientPolicy, 'is_bot_game'),
    'Client result UX must use the neutral capability rather than a bot marker.'
);
$assert(
    is_string($clientPolicy) && str_contains($clientPolicy, "playAgain.textContent = 'Сыграть ещё';"),
    'The ordinary replay path must use neutral Play again copy.'
);

$policyInit = is_string($clientPolicy) ? strpos($clientPolicy, 'initRematchPresentationPolicy();') : false;
$legacyInit = is_string($clientPolicy) ? strpos($clientPolicy, 'initBaseGameInvites();') : false;
$assert(
    is_int($policyInit) && is_int($legacyInit) && $policyInit < $legacyInit,
    'Bot-opaque result policy must initialize before the legacy result enhancer can observe a result sheet.'
);
$assert(
    is_string($clientPolicy)
        && str_contains($clientPolicy, "playAgain.classList.remove('ghost');")
        && str_contains($clientPolicy, "playAgain.classList.add('primary');")
        && str_contains($clientPolicy, "playAgain.classList.remove('primary');")
        && str_contains($clientPolicy, "playAgain.classList.add('ghost');"),
    'Neutral rematch capability must own the result-button hierarchy so a hidden direct rematch cannot leave a one-frame style flash.'
);
$assert(
    is_string($legacyInvites)
        && str_contains($legacyInvites, 'resultEnhanceTimer = window.setTimeout(enhanceResultSheet, 40);')
        && str_contains($legacyInvites, "newOpponent.classList.remove('primary');")
        && str_contains($legacyInvites, "newOpponent.classList.add('ghost');"),
    'The contract must cover the legacy delayed enhancer that originally caused the visible first-paint jump.'
);
$assert(
    is_array($manifest)
        && str_contains(
            (string)($manifest['imports']['./assets/js/games/game-invites-v110.js?v=1137&ux=1'] ?? ''),
            'game-invites-v110-rematch-policy-v175.js?v=2'
        ),
    'The active v110 invite import must cache-bust to the stable first-paint rematch presentation owner.'
);
$assert(
    is_string($launch) && str_contains($launch, "private const ENTRY_PATH = '/app/v110.php?v=1127';"),
    'The contract must remain anchored to the Telegram/Test v110 entry actually launched by the bot.'
);
$assert(
    is_string($inviteServiceSource)
        && str_contains($inviteServiceSource, 'Реванш сейчас недоступен. Выберите «Сыграть ещё».')
        && !str_contains($inviteServiceSource, 'живым соперником'),
    'The public rematch service boundary must contain only opponent-neutral fallback copy.'
);

fwrite(STDOUT, "Mvp17_5BotOpaqueRematchContractTest: {$assertions} assertions passed\n");
