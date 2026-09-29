<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;

$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$read = static function (string $path) use ($root): string {
    $value = file_get_contents($root . '/' . $path);
    if (!is_string($value)) throw new RuntimeException('Cannot read ' . $path);
    return $value;
};

$main = $read('app/assets/js/main-v110-handoff-shell.js');
$home = $read('app/assets/js/screens/home-screen.js');
$css = $read('app/assets/css/mvp25-3-ux-consistency-v1.css');
$manifest = $read('app/runtime/client/version-manifest.php');

foreach ([
    'matchWinnerReward',
    'matchSystemSink',
    'matchDrawRefund',
    'APP_CONFIG.matchEconomy = Object.freeze',
    'winner_reward:matchWinnerReward',
    'system_sink:matchSystemSink',
    'draw_refund:matchDrawRefund',
] as $needle) {
    $assert(str_contains($main, $needle), 'Bootstrap must retain authoritative public match economy: ' . $needle);
}

foreach ([
    'Как работают обычные матчи',
    '1. Как начать игру',
    '2. Сколько стоит матч',
    '3. Победа, поражение и ничья',
    '4. Не выходите из незавершённого матча',
    '5. Правила конкретной игры',
    '6. Где проверить результат',
    '7. Бонусы и турниры',
    '8. Если что-то пошло не так',
    'Пока идёт поиск, коины не списываются.',
    'Пригласить в игру',
    'winner_reward',
    'system_sink',
    'draw_refund',
    'История баланса',
    'История матчей',
] as $needle) {
    $assert(str_contains($home, $needle), 'Human rules guide missing: ' . $needle);
}

$assert(!str_contains($home, 'награда начисляется по действующим серверным правилам экономики'), 'Old machine-like economy copy must be removed.');
$assert(str_contains($home, 'amount(entry)'), 'Entry amount must come from the bootstrap economy snapshot.');
$assert(str_contains($home, 'amount(winnerReward)'), 'Winner reward must come from the bootstrap economy snapshot.');
$assert(str_contains($home, 'amount(commission)'), 'Commission must come from the bootstrap economy snapshot.');
$assert(str_contains($home, 'amount(drawRefund)'), 'Draw refund must come from the bootstrap economy snapshot.');

foreach ([
    '.rules-guide-section',
    '.rules-guide-economy',
    '.rules-guide-numbers',
] as $needle) {
    $assert(str_contains($css, $needle), 'Rules guide styling missing: ' . $needle);
}

$assert(str_contains($manifest, 'home-screen.js?v=90&mvp25_3=human-rules-guide-v1'), 'Manifest must publish the fresh Home rules owner.');
$assert(str_contains($manifest, 'main-v110-handoff-shell.js?v=1159&mvp25_3=match-economy-guide-v1'), 'Manifest must publish the fresh bootstrap economy owner.');
$assert(str_contains($manifest, 'main.css?v=209&mvp25_3=ux-consistency-v2&rules=human-guide-top-spacing-v1'), 'Manifest must publish the fresh rules CSS identity.');
$assert(str_contains($css, 'padding-top:8px'), 'Rules guide must use the reduced first-card top inset accepted by the product owner.');

fwrite(STDOUT, "Mvp25_3RulesGuideContractTest: {$assertions} assertions passed\n");
