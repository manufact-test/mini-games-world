<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/helpers/response.php';

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

$generic = 'Не удалось выполнить действие. Попробуйте ещё раз.';
$technical = [
    'Invite DB bridge requires a stable JSON snapshot capability.',
    'Notification bridge requires exclusive JSON snapshots.',
    'Cosmetic Store requires transactional runtime storage.',
    'Tournament launch requires exactly two runtime players.',
    'Tournament pair is attached to an unexpected game.',
    'Tournament game was not persisted.',
    'Регистрация турниров требует канонической MGW account identity.',
    'Staging tournament request token is invalid.',
    'unknown_action',
    'SQLSTATE[HY000] database failure',
    'Ошибка API: 500',
];
foreach ($technical as $message) {
    $assert(
        mgw_public_api_error($message) === $generic,
        'Technical public error must be sanitized: ' . $message
    );
}

$human = [
    'Недостаточно коинов для принятия приглашения.',
    'Игрок сейчас занят поиском, матчем или другим приглашением.',
    'Приглашение не найдено или уже недоступно.',
    'Ник должен содержать минимум 3 символа.',
    'Турниры временно недоступны.',
];
foreach ($human as $message) {
    $assert(
        mgw_public_api_error($message) === $message,
        'Human domain error must remain specific: ' . $message
    );
}

$client = $read('app/assets/js/api/client.js');
$assert(!str_contains($client, 'Ошибка API:'), 'Player API client must not expose API/HTTP fallback wording.');
$assert(str_contains($client, 'Не удалось выполнить запрос. Попробуйте ещё раз.'), 'Human generic request fallback must exist.');

$entry = $read('app/v110.php');
$assert(str_contains($entry, 'function mgw_v110_public_failure'), 'v110 must own one human fatal boundary.');
$assert(str_contains($entry, 'Не удалось открыть Mini Games World. Попробуйте ещё раз через минуту.'), 'v110 human fatal message missing.');
$assert(!preg_match('/echo\s+[\'\"]Mini Games World .*?(manifest|owner|stylesheet|anchor|bootstrap)/i', $entry), 'v110 must not echo implementation diagnostics to players.');

$manifest = $read('app/runtime/client/version-manifest.php');
$assert(
    substr_count($manifest, './assets/js/api/client.js?v=1148&mvp25_2=human-error-boundary-v1') === 4,
    'All active API client import aliases must publish the fresh human-error-boundary identity.'
);

foreach ([
    'bot/friends.php',
    'bot/support.php',
    'bot/moderation.php',
    'bot/account-data.php',
    'bot/profile-v2.php',
    'bot/rating-archive.php',
    'bot/leaderboard.php',
    'bot/game-reaction.php',
] as $path) {
    $source = $read($path);
    $assert(
        !preg_match('/[\'\"]error[\'\"]\s*=>\s*\$error->getMessage\(\)/', $source),
        $path . ' must not publish raw exception text.'
    );
}

fwrite(STDOUT, "Mvp25_2HumanFacingErrorBoundaryContractTest: {$assertions} assertions passed\n");
