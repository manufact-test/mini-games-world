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
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};

$tournaments = $read('app/assets/js/screens/tournaments-screen-v1.js');
$api = $read('bot/api.php');
$manifest = $read('app/runtime/client/version-manifest.php');
$localeSource = $read('app/locales/ru.json');
$locale = json_decode($localeSource, true, 512, JSON_THROW_ON_ERROR);

$localizedCopy = [
    ["arena.terminal.self_hold_title",'Ваша награда временно на проверке'],
    ["arena.terminal.other_hold_title",'Одна из наград временно на проверке'],
    ["arena.terminal.self_hold_note",'Мы проверяем результат. Ничего повторно делать не нужно: после проверки здесь появится окончательный итог и, если результат подтвердится, награда.'],
    ["arena.terminal.other_hold_note",'Один из призовых результатов находится на проверке. После проверки итоговые места и награды обновятся автоматически.'],
    ["arena.terminal.settling_note",'Результат сетки уже зафиксирован. Награды появятся автоматически после завершения обработки.'],
    ["arena.bracket.technical_restart_scheduled",'Матч перезапустится через 1 минуту.'],
    ["arena.bracket.technical_restart_exhausted",'Матч не удалось продолжить · он завершён без победителя.'],
    ["arena.hall.left",'Сейчас не в зале'],
];
foreach ($localizedCopy as [$key,$copy]) {
    $value=$locale;
    foreach(explode('.', $key) as $part) $value=is_array($value)?($value[$part]??null):null;
    $assert($value === $copy, 'Expected human tournament locale copy missing: ' . $key);
    if (str_starts_with($key, 'arena.bracket.technical_restart_')) {
        $code=substr($key, strlen('arena.bracket.'));
        $assert(str_contains($tournaments, $code . ":'" . $code . "'")
                && str_contains($tournaments, 'arena.bracket.${key}'),
            'Tournament runtime must resolve localized technical outcome copy dynamically: ' . $key);
    } else {
        $assert(str_contains($tournaments, $key), 'Tournament runtime must consume localized human copy: ' . $key);
    }
}

foreach ([
    'Admin review',
    'канонический settlement',
    'идемпотентно',
    'призовая ветка временно удержана',
    'Технический перезапуск',
    'Технический сбой повторился',
    'Нет активного присутствия',
] as $copy) {
    $assert(!str_contains($tournaments, $copy), 'Developer/internal tournament copy must not remain: ' . $copy);
}

$assert(
    str_contains($api, "ServerLocalization::copy('server.api.tournament_player_not_ready'")
        && (($locale['server']['api']['tournament_player_not_ready'] ?? null) === 'Один из игроков ещё не готов к запуску матча.'),
    'Tournament launch error must resolve player language through the canonical locale catalog.'
);
$assert(
    !str_contains($api, 'Один из игроков ещё не синхронизировал игровой клиент.'),
    'Tournament launch error must not expose client synchronization jargon.'
);

$assert(
    ($locale['settings']['language_note'] ?? null) === 'Выберите язык интерфейса Mini Games World.',
    'Language setting note must use ordinary user language.'
);
$assert(
    !str_contains($localeSource, 'Текущая локализация приложения.'),
    'Profile must not expose localization terminology.'
);

$assert(
    str_contains($manifest, './assets/js/screens/tournaments-screen-v1.js?v=32&mvp25_2=human-copy-v1&arena=rating-archive-v1'),
    'Canonical manifest must publish the fresh tournament human-copy identity.'
);

$assert(
    str_contains($tournaments, "t('arena.terminal.reward_fallback')")
        && (($locale['arena']['terminal']['reward_fallback'] ?? null) === 'Награда турнира'),
    'Unknown tournament reward codes must use a localized human fallback instead of exposing an enum.'
);

// "Техническое поражение" is intentional product language: it tells the player
// the actual game outcome and must not be erased by a blanket terminology pass.
$assert(
    str_contains($tournaments, "t('arena.hall.technical_loss')")
        && (($locale['arena']['hall']['technical_loss'] ?? null) === 'Техническое поражение'),
    'Player-relevant technical defeat wording must remain intact through localization.'
);

fwrite(STDOUT, "Mvp25_2TournamentHumanCopyContractTest: {$assertions} assertions passed\n");
