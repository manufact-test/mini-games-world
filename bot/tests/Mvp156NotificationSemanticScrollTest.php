<?php
declare(strict_types=1);
$root = dirname(__DIR__);
require_once $root . '/helpers/validators.php';
require_once $root . '/services/NotificationService.php';
$assertions = 0;
$assertTrue = static function (bool $actual, string $message) use (&$assertions): void {
    $assertions++;
    if (!$actual) throw new RuntimeException($message);
};
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
};
$read = static fn(string $path): string => file_get_contents($path) ?: throw new RuntimeException('Cannot read ' . $path);
$notificationsPhp = $read($root . '/notifications.php');
$client = $read(dirname($root) . '/app/assets/js/screens/notifications-screen-v110r12.js');
$css = $read(dirname($root) . '/app/assets/css/screens/notifications.css');
$weekly = $read(dirname($root) . '/app/assets/js/screens/weekly-match-info.js');
$v110 = $read(dirname($root) . '/app/v110.php');
$versions = require dirname($root) . '/app/runtime/client/version-manifest.php';
$manifest = $read($root . '/helpers/staging-e2e-runtime-files.txt');

$assertTrue(!str_contains($css, '.notification-card.warning'), 'Notification cards must not expose a fourth warning semantic color');
$assertTrue(!str_contains($css, '.notification-toast.warning'), 'Notification toasts must not expose a fourth warning semantic color');
$assertTrue(str_contains($css, 'var(--sk-info)') && str_contains($css, 'var(--sk-success)') && str_contains($css, 'var(--sk-error)'), 'Notification palette must use canonical blue/green/red tokens');
$assertTrue(
    str_contains($notificationsPhp, "$item['tone'] = 'success';")
    && str_contains($notificationsPhp, "$item['tone'] = 'danger';")
    && str_contains($notificationsPhp, "$item['tone'] = 'info';"),
    'Backend notification decorator must emit the three canonical tones'
);
$assertTrue(!str_contains($notificationsPhp, "$item['tone'] = 'warning';"), 'Backend notification presentation must not emit warning tone');
$assertTrue(str_contains($client, 'function semanticTone(value)') && !str_contains($client, "['success','danger','info','warning']"), 'Client must canonicalize notifications to exactly three semantic tones');
$assertTrue(str_contains($client, 'const existingList = isNotificationsSheetOpen()') && str_contains($client, 'const scrollTop = existingList.scrollTop;') && str_contains($client, 'existingList.scrollTop = scrollTop;'), 'Open notification refresh must reuse the list owner and preserve scroll position');
$assertTrue(str_contains($client, 'const POLL_MS = 30000;'), 'Background refresh remains active; scroll fix must not disable polling');
$assertTrue(str_contains($weekly, 'color:var(--sk-success)'), 'Completed 3/3 and 8/8 progress must use success green');
$assertTrue(str_contains($v110, 'green-red-blue-v1'), 'Accepted /start graph must advertise the notification semantic palette');
$assertSame(
    './assets/js/main-v110-handoff-shell.js?v=1146&mvp15=notification-polish',
    $versions['imports']['./assets/js/main-v110-handoff-shell.js?v=1137&ux=1&sk=3&icons=c1efd5af&render=5'] ?? null,
    'Version manifest must preserve the accepted notification successor shell'
);
$assertTrue(str_contains($manifest, 'app/assets/css/screens/notifications.css'), 'Exact runtime fingerprint must include notification palette CSS');

$service = new NotificationService();
$legacyDb = ['notifications' => [[
    'id' => 'legacy-first-game-no-payload-game-type',
    'event_key' => 'first_game_bonus:12345:battleship',
    'user_id' => '12345',
    'type' => 'first_game_bonus',
    'title' => 'Бонус за новую игру',
    'message' => 'Первая завершённая партия засчитана. Начислено +50 коинов.',
    'tone' => 'success',
    'created_at' => '2026-08-15T22:25:00+00:00',
    'read_at' => null,
]]];
$items = $service->userNotifications($legacyDb, '12345');
$assertSame('Первая завершённая партия в «Морской бой». Начислено +50 коинов.', $items[0]['message'] ?? null, 'Historical first-game copy must recover game title from immutable event key');
$assertSame('Первая завершённая партия засчитана. Начислено +50 коинов.', $legacyDb['notifications'][0]['message'], 'Historical notification storage must remain untouched');
fwrite(STDOUT, "Mvp156NotificationSemanticScrollTest passed: {$assertions} assertions.\n");
