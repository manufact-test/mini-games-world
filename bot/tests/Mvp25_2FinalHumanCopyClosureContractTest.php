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
$accountData = $read('app/assets/js/screens/account-data-sheet-v1.js');
$accountShortcuts = $read('app/assets/js/components/account-shortcuts.js');
$invites = $read('app/assets/js/games/game-invites-v110.js');
$manifest = $read('app/runtime/client/version-manifest.php');
$localeSource = $read('app/locales/ru.json');
$locale = json_decode($localeSource, true, 512, JSON_THROW_ON_ERROR);
$arenaCopy = json_encode($locale['arena'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$accountDataCopy = json_encode($locale['account_data'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$audit = $read('docs/MVP25_2_HUMAN_FACING_COPY_AUDIT.md');
$closure = $read('docs/MVP25_2_HUMAN_FACING_COPY_CLOSURE.md');
$manual = $read('docs/MVP25_2_MANUAL_ACCEPTANCE.md');

$assert(str_contains($audit, 'MANDATORY HUMAN-FACING COPY AUDIT') || str_contains($audit, 'Mandatory human-facing copy audit'), 'MVP-25.2 source audit must remain recorded.');
$assert(str_contains($closure, 'CLOSED / MANUALLY ACCEPTED / FROZEN'), 'MVP-25.2 must record final manual acceptance and freeze.');
$assert(str_contains($closure, '36595264108'), 'MVP-25.2 must record the exact final green staging E2E run.');
$assert(str_contains($manual, 'Manual acceptance checklist'), 'MVP-25.2 manual acceptance checklist must remain recorded.');
$assert(str_contains($manual, 'Result: **PASS**'), 'MVP-25.2 manual acceptance result must be recorded as PASS.');
$assert(str_contains($manual, 'MVP-25.3 — Final UX consistency'), 'MVP-25.2 closure must point to MVP-25.3 as the next roadmap step.');

$assert(str_contains($entry, 'function mgw_v110_public_failure'), 'v110 must own one public fatal boundary.');
$assert(str_contains($entry, 'Не удалось открыть Mini Games World. Попробуйте ещё раз через минуту.'), 'v110 must present a human fatal message.');
foreach (['client version manifest is unavailable', 'accepted game owner is unavailable', 'accepted stylesheet is unavailable', 'source anchor is unavailable', 'transformed target is unavailable', 'must expose exactly one top-level module bootstrap'] as $technical) {
    $assert(!preg_match('/echo[^;]*' . preg_quote($technical, '/') . '/i', $entry), 'v110 must not echo implementation detail: ' . $technical);
}

$assert(!str_contains($client, 'Ошибка API:'), 'Canonical client must not show API/HTTP fallback wording.');
$assert(str_contains($client, 'Не удалось выполнить запрос. Попробуйте ещё раз.'), 'Canonical client must have a human request fallback.');
$assert(str_contains($client, 'Не удалось связаться с сервером. Проверьте интернет и попробуйте ещё раз.'), 'Canonical client must humanize transport/network failures.');
$assert(str_contains($client, "error.code = 'network_unavailable';"), 'Canonical client must classify network failure without exposing browser exception text.');
$assert(
    str_contains($invites, "inviteText('network.server_unreachable')")
    && (($locale['game_invites']['network']['server_unreachable'] ?? null) === 'Не удалось связаться с сервером. Проверьте интернет и попробуйте ещё раз.'),
    'Invite direct transport must humanize network failures through the localized invite owner.'
);
$assert(str_contains($invites, "if (error?.name === 'AbortError') throw error;"), 'Invite cancellation must remain distinguishable from real network failure.');
$assert(!str_contains($accountDataCopy, 'Ограниченная техническая история'), 'Account deletion copy must not expose developer-style technical-history wording.');
$assert(!str_contains($accountDataCopy, 'финансового аудита'), 'Account deletion copy must not expose audit terminology to ordinary players.');
$assert(
    str_contains($accountData, "accountDataText('confirm.retention_note')")
    && str_contains($accountDataCopy, 'операций с игровыми монетами'),
    'Account deletion copy must explain retained history in product language through the localized Account Data owner.'
);
$accountChildVersion = [];
$assert(
    preg_match('~account-data-sheet-v1\.js\?v=(\d+)[^\'"]*mvp25_2=human-copy-v1~', $accountShortcuts, $accountChildVersion) === 1
    && (int)$accountChildVersion[1] >= 6,
    'Account Data child copy change must stay at or beyond the accepted import identity.'
);
$clientAliasMatches = [];
$clientAliasCount = preg_match_all('~\./assets/js/api/client\.js\?v=(\d+)&mvp25_2=network-human-error-v2~', $manifest, $clientAliasMatches);
$assert(
    $clientAliasCount === 4 && min(array_map('intval', $clientAliasMatches[1] ?? [])) >= 1149,
    'All canonical API client aliases must stay at or beyond the final MVP-25.2 network-safe identity.'
);
$inviteAliasMatches = [];
$inviteAliasCount = preg_match_all('~\./assets/js/games/game-invites-v110\.js\?v=(\d+)&mvp25_2=network-human-error-v1~', $manifest, $inviteAliasMatches);
$assert(
    $inviteAliasCount === 3 && min(array_map('intval', $inviteAliasMatches[1] ?? [])) >= 1148,
    'All canonical invite aliases must stay at or beyond the final MVP-25.2 network-safe identity.'
);
$accountParentVersion = [];
$assert(
    preg_match('~account-shortcuts\.js\?v=(\d+)[^\n]*mvp25_2=account-data-human-copy-v1~', $manifest, $accountParentVersion) === 1
    && (int)$accountParentVersion[1] >= 56,
    'Account shortcut parent must stay at or beyond the accepted human-copy identity.'
);
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
    $assert(
        !str_contains($tournaments, $badCopy) && !str_contains($arenaCopy, $badCopy),
        'Tournament player UI must not contain internal copy: ' . $badCopy
    );
}
foreach ([
    'Ваша награда временно на проверке',
    'Результат сетки уже зафиксирован. Награды появятся автоматически после завершения обработки.',
    'Матч перезапустится через 1 минуту.',
    'Матч не удалось продолжить · он завершён без победителя.',
    'Награда турнира',
] as $humanCopy) {
    $assert(str_contains($arenaCopy, $humanCopy), 'Tournament localized human copy missing: ' . $humanCopy);
}
$assert(
    str_contains($tournaments, "t('arena.hall.technical_loss')")
    && str_contains($arenaCopy, 'Техническое поражение'),
    'Actual game-outcome wording “Техническое поражение” must remain intentionally preserved through localization.'
);

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
