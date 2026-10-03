<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$weekly = (string)file_get_contents($root . '/weekly/WeeklyBonusRuntimeBridge.php');
$notifications = (string)file_get_contents($root . '/notifications/RuntimeNotificationRepository.php');

$assertions = 0;
$assertContains = static function (string $needle, string $haystack, string $message) use (&$assertions): void {
    $assertions++;
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message . ': missing ' . $needle);
    }
};
$assertNotContains = static function (string $needle, string $haystack, string $message) use (&$assertions): void {
    $assertions++;
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException($message . ': unexpected ' . $needle);
    }
};

$assertContains(
    'synchronizationCandidateLegacyUserIds($snapshot)',
    $weekly,
    'Weekly projection must enumerate only notification owners with non-trivial parity'
);
$assertNotContains(
    "foreach (is_array(\$snapshot['users'] ?? null) ? \$snapshot['users'] : []",
    $weekly,
    'Weekly projection must not query notification parity once per runtime user'
);
$assertContains(
    'SELECT DISTINCT legacy_user_id',
    $notifications,
    'Notification candidate discovery must retain DB-only recipients'
);
$assertContains(
    "array_map('strval', array_keys(\$candidates))",
    $notifications,
    'Notification candidate IDs must preserve the string legacy-ID contract'
);
$assertContains(
    "isset(\$eligible[\$legacyUserId])",
    $notifications,
    'Notification candidate discovery must stay bounded to the current eligible runtime snapshot'
);

fwrite(STDOUT, "Mvp27_1ProjectionScaleRecoveryContractTest passed: {$assertions} assertions.\n");
