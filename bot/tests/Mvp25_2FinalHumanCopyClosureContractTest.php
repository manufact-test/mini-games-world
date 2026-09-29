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

$entry = $read('app/v110.php');
$client = $read('app/assets/js/api/client.js');
$response = $read('bot/helpers/response.php');
$tournaments = $read('app/assets/js/screens/tournaments-screen-v1.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v110r13.js');
$localeSource = $read('app/locales/ru.json');
$locale = json_decode($localeSource, true, 512, JSON_THROW_ON_ERROR);
$audit = $read('docs/MVP25_2_HUMAN_FACING_COPY_AUDIT.md');
$closure = $read('docs/MVP25_2_HUMAN_FACING_COPY_CLOSURE.md');

$assert(str_contains($audit, 'MANDATORY HUMAN-FACING COPY AUDIT') || str_contains($audit, 'Mandatory human-facing copy audit'), 'MVP-25.2 source audit must remain recorded.');
$assert(str_contains($closure, 'MVP-25.2 is closed'), 'MVP-25.2 closure statement must be recorded.');

$assert(str_contains($entry, 'function mgw_v110_public_failure'), 'v110 must own one public fatal boundary.');
$assert(str_contains($entry, 'Не удалось открыть Mini Games World. Попробуйте ещё раз через минуту.'), 'v110 must present a human fatal message.');
foreach (['client version manifest is unavailable', 'accepted game owner is unavailable', 'accepted stylesheet is unavailable', 'source anchor is unavailable', 'transformed target is unavailable', 'must expose exactly one top-level module bootstrap'] as $technical) {
    $assert(!preg_match('/echo[^;]*' . preg_quote($technical, '/') . '/i', $entry), 'v110 must not echo implementation detail: ' . $technical);
}

$assert(!str_contains($client, 'Ошибка API:'), 'Canonical client must not show API/HTTP fallback wording.');
$assert(str_contains($client, 'Не удалось выполнить запрос. Попробуйте ещё раз.'), 'Canonical client must have a human request fallback.');
$assert(str_contains($response, 'function mgw_public_api_error'), 'Shared public error sanitizer must remain active.');
foreach ([
    'SQLSTATE[HY000] database failure',
    'Invite DB bridge requires a stable JSON snapshot capability.',
    'Ошибка API: 500',
] as $technicalMessage) {
    $assert(
        mgw_public_api_error($technicalMessage) === 'Не удалось выполнить действие. Попробуйте ещё раз.',
        'Public sanitizer must hide technical message: ' . $technicalMessage
    );
}
$assert(
    mgw_public_api_error('Недостаточно коинов для принятия приглашения.') === 'Недостаточно коинов для принятия приглашения.',
    'Public sanitizer must preserve useful player-domain errors.'
);

foreach ([
    'Admin review',
    'канонический settlement',
    'идемпотентно',
    'призовая ветка временно удержана',
    'Технический перезапуск',
    'Технический сбой повторился',
    'Нет активного присутствия',
] as $badCopy) {
    $assert(!str_contains($tournaments, $badCopy), 'Tournament player UI must not contain internal copy: ' . $badCopy);
}
foreach ([
    'Ваша награда временно на проверке',
    'Результат сетки уже зафиксирован. Награды появятся автоматически после завершения обработки.',
    'Матч перезапустится через 1 минуту.',
    'Матч не удалось продолжить · он завершён без победителя.',
    'Награда турнира',
] as $humanCopy) {
    $assert(str_contains($tournaments, $humanCopy), 'Tournament human copy missing: ' . $humanCopy);
}
$assert(str_contains($tournaments, 'Техническое поражение'), 'Actual game-outcome wording “Техническое поражение” must remain intentionally preserved.');

$assert(($locale['profile']['language_note'] ?? null) === 'Текущий язык приложения.', 'Profile language note must be human wording.');
$assert(!str_contains($localeSource, 'Текущая локализация приложения.'), 'Profile must not expose localization terminology.');

$assert(!str_contains($notifications, "import { openStoreOrders } from './store-orders.js?v=36';"), 'Active notifications must not depend on retired Store orders UI.');
$assert(!str_contains($notifications, 'openStoreOrders()'), 'Active notifications must not invoke retired Store orders UI.');
$assert(str_contains($notifications, "if (link === 'store:orders') return 'store';"), 'Historical Store-order links must converge to current Store.');

foreach ([
    'bot/tests/Mvp25_2HumanFacingErrorBoundaryContractTest.php',
    'bot/tests/Mvp25_2NotificationLegacyOrderDeepLinkContractTest.php',
    'bot/tests/Mvp25_2TournamentHumanCopyContractTest.php',
] as $proof) {
    $assert(is_file($root . '/' . $proof), 'Required MVP-25.2 proof missing: ' . $proof);
}

fwrite(STDOUT, "Mvp25_2FinalHumanCopyClosureContractTest: {$assertions} assertions passed\n");
