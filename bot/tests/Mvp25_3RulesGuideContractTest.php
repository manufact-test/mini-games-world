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
$manifestData = require $root . '/app/runtime/client/version-manifest.php';
$findImportTarget = static function (array $data, string $marker): string {
    foreach (($data['imports'] ?? []) as $target) {
        if (is_string($target) && str_contains($target, $marker)) return $target;
    }
    return '';
};
$localeSource = $read('app/locales/ru.json');
$locale = json_decode($localeSource, true, 512, JSON_THROW_ON_ERROR);
$homeCopy = json_encode($locale['home'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

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
    'История баланса',
    'История матчей',
] as $needle) {
    $assert(str_contains($homeCopy, $needle), 'Localized human rules guide missing: ' . $needle);
}
foreach ([
    "t('home.rules_guide.title')",
    "t('home.rules_guide.start_title')",
    "t('home.rules_guide.cost_title')",
    "t('home.rules_guide.result_title')",
    "t('home.rules_guide.history_title')",
    'winner_reward',
    'system_sink',
    'draw_refund',
] as $needle) {
    $assert(str_contains($home, $needle), 'Rules guide owner wiring missing: ' . $needle);
}

$assert(
    !str_contains($home, 'награда начисляется по действующим серверным правилам экономики')
    && !str_contains($homeCopy, 'награда начисляется по действующим серверным правилам экономики'),
    'Old machine-like economy copy must be removed.'
);
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

$homeTarget = $findImportTarget($manifestData, 'mvp25_3=human-rules-guide-v1');
$homeVersion = [];
$assert(
    preg_match('~home-screen\.js\?v=(\d+)~', $homeTarget, $homeVersion) === 1
    && (int)$homeVersion[1] >= 90,
    'Manifest must publish the Home rules owner at or beyond the accepted cache identity.'
);
$bootstrapTarget = $findImportTarget($manifestData, 'mvp25_3=match-economy-guide-v1');
$bootstrapVersion = [];
$assert(
    preg_match('~main-v110-handoff-shell\.js\?v=(\d+)~', $bootstrapTarget, $bootstrapVersion) === 1
    && (int)$bootstrapVersion[1] >= 1159,
    'Manifest must publish the bootstrap economy owner at or beyond the accepted cache identity.'
);
$cssVersion = [];
$assert(
    preg_match('~main\.css\?v=(\d+)[^\n]*mvp25_3=ux-consistency-v2[^\n]*rules=human-guide-top-spacing-v1~', $manifest, $cssVersion) === 1
    && (int)$cssVersion[1] >= 209,
    'Manifest must publish the rules CSS identity at or beyond the accepted cache identity.'
);
$assert(str_contains($css, 'padding-top:8px'), 'Rules guide must use the reduced first-card top inset accepted by the product owner.');

fwrite(STDOUT, "Mvp25_3RulesGuideContractTest: {$assertions} assertions passed\n");
