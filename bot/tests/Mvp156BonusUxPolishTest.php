<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/helpers/validators.php';
require_once $root . '/services/NotificationService.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $actual, string $message) use (&$assertions): void {
    $assertions++;
    if (!$actual) throw new RuntimeException($message);
};

$service = new NotificationService();
$games = [
    'tictactoe' => 'Крестики-нолики',
    'four_in_a_row' => 'Четыре в ряд',
    'battleship' => 'Морской бой',
    'checkers' => 'Шашки',
    'reversi' => 'Реверси',
    'chess' => 'Шахматы',
    'go' => 'Го',
    'domino' => 'Домино',
];

$db = ['notifications' => []];
$user = ['id' => 'bonus-ux-player'];

foreach ($games as $gameType => $title) {
    $notification = $service->addFirstGameBonus($db, $user, [
        'game_type' => $gameType,
        'amount' => 50,
        'created_at' => '2026-08-16T00:00:00+00:00',
    ]);
    $assertTrue(is_array($notification), 'First-game notification must be created for ' . $gameType);
    $assertSame(
        "Первая завершённая партия в «{$title}». Начислено +50 коинов.",
        $notification['message'] ?? null,
        'Notification must name the exact game'
    );
}

$assertSame(8, count($db['notifications']), 'Exactly one notification must exist for each game');
$duplicate = $service->addFirstGameBonus($db, $user, [
    'game_type' => 'battleship',
    'amount' => 50,
]);
$assertSame('first_game_bonus:bonus-ux-player:battleship', $duplicate['event_key'] ?? null, 'Duplicate lookup must return original notification');
$assertSame(8, count($db['notifications']), 'Duplicate first-game notification must not be appended');

$legacyDb = [
    'notifications' => [[
        'id' => 'legacy-first-game',
        'event_key' => 'first_game_bonus:legacy:battleship',
        'user_id' => 'legacy',
        'type' => 'first_game_bonus',
        'title' => 'Бонус за новую игру',
        'message' => 'Первая завершённая партия засчитана. Начислено +50 коинов.',
        'tone' => 'success',
        'created_at' => '2026-08-15T22:25:00+00:00',
        'read_at' => null,
    ]],
];
$legacyItems = $service->userNotifications($legacyDb, 'legacy');
$assertSame(1, count($legacyItems), 'Legacy first-game notification must remain visible');
$assertSame(
    'Первая завершённая партия в «Морской бой». Начислено +50 коинов.',
    $legacyItems[0]['message'] ?? null,
    'Legacy stored notification must receive the new display copy without DB mutation'
);
$assertSame(
    'Первая завершённая партия засчитана. Начислено +50 коинов.',
    $legacyDb['notifications'][0]['message'],
    'Read-time copy upgrade must not mutate stored history'
);

fwrite(STDOUT, "Mvp156BonusUxPolishTest passed: {$assertions} assertions.\n");
