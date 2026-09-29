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

foreach ([
    'Ваша награда временно на проверке',
    'Одна из наград временно на проверке',
    'Мы проверяем результат. Ничего повторно делать не нужно: после проверки здесь появится окончательный итог и, если результат подтвердится, награда.',
    'Один из призовых результатов находится на проверке. После проверки итоговые места и награды обновятся автоматически.',
    'Результат сетки уже зафиксирован. Награды появятся автоматически после завершения обработки.',
    'Матч перезапустится через 1 минуту.',
    'Матч не удалось продолжить · он завершён без победителя.',
    'Сейчас не в зале',
] as $copy) {
    $assert(str_contains($tournaments, $copy), 'Expected human tournament copy missing: ' . $copy);
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
    str_contains($api, "throw new RuntimeException('Один из игроков ещё не готов к запуску матча.');"),
    'Tournament launch error must use player language.'
);
$assert(
    !str_contains($api, 'Один из игроков ещё не синхронизировал игровой клиент.'),
    'Tournament launch error must not expose client synchronization jargon.'
);

$assert(
    ($locale['profile']['language_note'] ?? null) === 'Текущий язык приложения.',
    'Profile language note must use ordinary user language.'
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
    str_contains($tournaments, "return TOURNAMENT_TERMINAL_REWARD_LABELS[code] || 'Награда турнира';"),
    'Unknown tournament reward codes must use a human fallback instead of exposing an enum.'
);

// "Техническое поражение" is intentional product language: it tells the player
// the actual game outcome and must not be erased by a blanket terminology pass.
$assert(
    str_contains($tournaments, 'Техническое поражение'),
    'Player-relevant technical defeat wording must remain intact.'
);

fwrite(STDOUT, "Mvp25_2TournamentHumanCopyContractTest: {$assertions} assertions passed\n");
