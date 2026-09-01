<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$repoRoot = dirname($root);

require_once $root . '/economy/UnifiedBalanceRuntimeState.php';
require_once $root . '/services/UserService.php';
require_once $root . '/services/HistoryService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$users = (new ReflectionClass(UserService::class))->newInstanceWithoutConstructor();
$history = new HistoryService(['storage_driver'=>'json'], $users);

$db = [
    'games' => [
        'game_win_1' => [
            'id'=>'game_win_1','status'=>'finished','game_type'=>'tictactoe','room'=>'match',
            'player_ids'=>['u1','bot_private_1'],'player_names'=>['u1'=>'Player','bot_private_1'=>'Leo'],
            'winner_id'=>'u1','loser_id'=>'bot_private_1','finish_reason'=>'normal_win',
            'board_size'=>3,'bet'=>10,'payout'=>18,'commission'=>2,
            'is_bot_game'=>true,'bot_id'=>'bot_private_1','bot_difficulty'=>'hard',
            'created_at'=>'2026-08-18T10:00:00Z','finished_at'=>'2026-08-18T10:01:00Z',
        ],
        'game_loss_1' => [
            'id'=>'game_loss_1','status'=>'finished','game_type'=>'chess','room'=>'match',
            'player_ids'=>['u1','u2'],'player_names'=>['u1'=>'Player','u2'=>'Rival'],
            'winner_id'=>'u2','loser_id'=>'u1','finish_reason'=>'normal_win',
            'board_size'=>8,'bet'=>10,'payout'=>18,'commission'=>2,
            'created_at'=>'2026-08-18T11:00:00Z','finished_at'=>'2026-08-18T11:10:00Z',
        ],
        'game_refund_1' => [
            'id'=>'game_refund_1','status'=>'finished','game_type'=>'reversi','room'=>'match',
            'player_ids'=>['u1','u3'],'player_names'=>['u1'=>'Player','u3'=>'Rival 2'],
            'winner_id'=>null,'loser_id'=>null,'finish_reason'=>'preparation_timeout',
            'board_size'=>8,'bet'=>10,'payout'=>0,'commission'=>0,
            'created_at'=>'2026-08-18T12:00:00Z','finished_at'=>'2026-08-18T12:00:30Z',
        ],
    ],
    'transactions' => [
        ['id'=>'tx1','type'=>'balance_change','category'=>'game_entry','user_id'=>'u1','game_id'=>'game_win_1','amount'=>-10,'balance_after'=>90,'is_bot_game'=>true,'bot_difficulty'=>'hard','created_at'=>'2026-08-18T10:00:00Z'],
        ['id'=>'tx2','type'=>'game_start','game_id'=>'game_win_1','players'=>['u1','bot_private_1'],'bet'=>10,'is_bot_game'=>true,'bot_difficulty'=>'hard','created_at'=>'2026-08-18T10:00:00Z'],
        ['id'=>'tx3','type'=>'balance_change','category'=>'game_win','user_id'=>'u1','game_id'=>'game_win_1','amount'=>18,'balance_after'=>108,'is_bot_game'=>true,'bot_difficulty'=>'hard','created_at'=>'2026-08-18T10:01:00Z'],
        ['id'=>'tx4','type'=>'game_finish','game_id'=>'game_win_1','winner_id'=>'u1','payout'=>18,'commission'=>2,'is_bot_game'=>true,'bot_difficulty'=>'hard','created_at'=>'2026-08-18T10:01:00Z'],
        ['id'=>'tx5','type'=>'balance_change','category'=>'game_entry','user_id'=>'u1','game_id'=>'game_loss_1','amount'=>-10,'balance_after'=>98,'created_at'=>'2026-08-18T11:00:00Z'],
        ['id'=>'tx6','type'=>'game_finish','game_id'=>'game_loss_1','winner_id'=>'u2','payout'=>18,'commission'=>2,'created_at'=>'2026-08-18T11:10:00Z'],
        ['id'=>'tx7','type'=>'balance_change','category'=>'game_entry','user_id'=>'u1','game_id'=>'game_refund_1','amount'=>-10,'balance_after'=>88,'created_at'=>'2026-08-18T12:00:00Z'],
        ['id'=>'tx8','type'=>'balance_change','category'=>'game_refund','user_id'=>'u1','game_id'=>'game_refund_1','amount'=>10,'balance_after'=>98,'created_at'=>'2026-08-18T12:00:30Z'],
        ['id'=>'tx9','type'=>'game_finish','game_id'=>'game_refund_1','winner_id'=>null,'payout'=>0,'commission'=>0,'finish_reason'=>'preparation_timeout','created_at'=>'2026-08-18T12:00:30Z'],
    ],
];

$formatted = $history->formatHistory($db, 'u1', 24);
$matches = [];
foreach ($formatted['matches'] ?? [] as $match) $matches[(string)($match['id'] ?? '')] = $match;

$win = $matches['game_win_1']['economy'] ?? null;
$assert(is_array($win), 'Winning match must expose canonical economy presentation.');
$assert(($win['entry'] ?? null) === 10, 'Winning entry must come from the negative game_entry ledger row.');
$assert(($win['reward'] ?? null) === 18, 'Winning reward must come from the positive game_win ledger row.');
$assert(($win['net'] ?? null) === 8 && ($win['ledger_delta'] ?? null) === 8, 'Winning net must equal the exact summed ledger delta.');
$assert(($win['new_balance'] ?? null) === 108, 'Winning new balance must use the final ledger balance_after.');

$loss = $matches['game_loss_1']['economy'] ?? null;
$assert(is_array($loss), 'Losing match must expose canonical economy presentation.');
$assert(($loss['entry'] ?? null) === 10 && ($loss['reward'] ?? null) === 0, 'Loss must show entry with zero reward.');
$assert(($loss['ledger_delta'] ?? null) === -10, 'Loss ledger delta must remain the charged entry.');
$assert(($loss['new_balance'] ?? null) === 98, 'Loss new balance must be the entry ledger balance_after.');

$refund = $matches['game_refund_1']['economy'] ?? null;
$assert(is_array($refund), 'Refunded preparation timeout must expose canonical economy presentation.');
$assert(($refund['entry'] ?? null) === 10 && ($refund['reward'] ?? null) === 10, 'Refund must show the charged entry and exact refund credit.');
$assert(($refund['ledger_delta'] ?? null) === 0 && ($refund['new_balance'] ?? null) === 98, 'Refund net must be zero with the final refunded balance.');
$assert(($matches['game_refund_1']['result'] ?? '') === 'Матч не начался', 'Preparation timeout result copy must remain neutral and explicit.');

$serialized = json_encode($formatted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$assert(!str_contains($serialized, 'is_bot_game'), 'History projection must not expose is_bot_game.');
$assert(!str_contains($serialized, 'bot_difficulty'), 'History projection must not expose bot difficulty.');
$assert(!str_contains($serialized, 'bot_private_1'), 'History projection must not expose the technical bot identifier.');
$assert(str_contains($serialized, 'Leo'), 'Neutral opponent display name remains allowed in history.');

$resultClient = file_get_contents($repoRoot . '/app/assets/js/screens/game-screen-v102.js');
$profileClient = file_get_contents($repoRoot . '/app/assets/js/screens/profile-screen-v110.js');
$manifest = require $repoRoot . '/app/runtime/client/version-manifest.php';
$launch = file_get_contents($root . '/helpers/WebAppLaunchUrl.php');

$assert(is_string($resultClient) && str_contains($resultClient, 'await api.history()'), 'Result sheet must hydrate from canonical server history.');
$assert(is_string($resultClient) && str_contains($resultClient, 'economy.ledger_delta'), 'Result sheet must display the server-projected ledger delta.');
$assert(is_string($resultClient) && str_contains($resultClient, 'economy.new_balance'), 'Result sheet must display server-projected new balance.');
$assert(is_string($resultClient) && str_contains($resultClient, 'За игру: ${escapeHtml(delta)} · Баланс: ${escapeHtml(balance)}'), 'Result must show only viewer net delta and final balance.');
$assert(is_string($resultClient) && !str_contains($resultClient, 'Вход: ${escapeHtml(entry)}'), 'Result must not repeat the entry debit as a separate visible row.');
$assert(is_string($resultClient) && !str_contains($resultClient, 'Награда: ${escapeHtml(reward)}'), 'Result must not repeat the reward credit as a separate visible row.');
$assert(is_string($resultClient) && str_contains($resultClient, 'resultSummaryPlaceholder(game, me'), 'Result must reserve the final two-line summary shape before economy hydration.');
$assert(is_string($resultClient) && str_contains($resultClient, 'window.requestAnimationFrame(() => openResultSheet(game, me));'), 'Result must open on the next paint without the legacy extra 80ms delay.');
$assert(is_string($resultClient) && !str_contains($resultClient, 'window.setTimeout(() => openResultSheet(game, me), 80)'), 'Result must not restore the visible 80ms terminal delay.');
$assert(is_string($resultClient) && !str_contains($resultClient, 'const variant = columns > 0'), 'Result context must stay compact: game and opponent only, without board-size metadata.');
$assert(is_string($resultClient) && !str_contains($resultClient, '${game.payout'), 'Result copy must not calculate or display money from raw game payout.');
$assert(is_string($resultClient) && str_contains($resultClient, 'id="newOpponent"') && str_contains($resultClient, 'id="goHome"'), 'Accepted result action IDs must remain unchanged for rematch policy ownership.');
$assert(is_string($profileClient) && str_contains($profileClient, 'match?.economy'), 'Profile history must consume the same canonical match economy projection.');
$assert(is_string($profileClient) && str_contains($profileClient, 'economy.ledger_delta'), 'Profile history must display canonical ledger delta.');
$assert(
    str_contains((string)($manifest['imports']['./assets/js/screens/game-screen-v102.js?v=102'] ?? ''), 'v=106&clock=phase-b-single-writer&battleship=leave-guard&mvp17=result-history-economy&live=owner-v3&result=compact-fast-v1'),
    'Active v110 manifest must publish the compact fast Result owner while preserving accepted game ownership prefixes.'
);
$profileTarget = (string)($manifest['imports']['./assets/js/screens/profile-screen-v110.js?v=1108'] ?? '');
$assert(
    preg_match('/profile-screen-v110\.js\?v=(\d+)/', $profileTarget, $profileTargetMatch) === 1
        && (int)$profileTargetMatch[1] >= 1119
        && str_contains($profileTarget, 'mvp16=profile-pass-a')
        && str_contains($profileTarget, 'mvp17=result-history-economy'),
    'Active v110 manifest must preserve the accepted Profile pass A and Result/History economy lineage while allowing later bounded Profile work.'
);
$assert(
    str_contains((string)($manifest['imports']['./assets/js/games/game-invites-v110.js?v=1137&ux=1'] ?? ''), 'game-invites-v110-rematch-policy-v175.js?v=1&fp=2'),
    'Accepted MVP-17.5 rematch presentation policy must remain frozen.'
);
$assert(is_string($launch) && str_contains($launch, "private const ENTRY_PATH = '/app/v110.php?v=1127';"), 'Result/history test must remain anchored to actual Telegram v110 launch.');

fwrite(STDOUT, "Mvp17_5ResultHistoryEconomyPresentationTest: {$assertions} assertions passed\n");
