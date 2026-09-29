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

$bootstrap = $read('bot/core/bootstrap.php');
$response = $read('bot/helpers/response.php');
$telegram = $read('bot/services/TelegramService.php');
$invites = $read('bot/invites.php');
$ratingArchive = $read('bot/rating-archive.php');
$tournamentStatus = $read('bot/tournament-status.php');
$tournamentHall = $read('bot/tournament-hall.php');

$displayPos = strpos($bootstrap, "ini_set('display_errors', '0')");
$configPos = strpos($bootstrap, "getenv('MGW_CONFIG_FILE')");
$assert($displayPos !== false, 'Bootstrap must disable browser display_errors.');
$assert($configPos !== false && $displayPos < $configPos, 'Browser diagnostics must be disabled before private config discovery.');
$assert(strpos($bootstrap, "ini_set('html_errors', '0')") !== false, 'Bootstrap must disable HTML diagnostics before runtime loading.');
$assert(str_contains($response, "ini_set('display_errors', '0')"), 'Response helper should retain its API-boundary defense in depth.');

$assert(!str_contains($telegram, "failed for ' . \$chatId"), 'Telegram service logs must not contain raw admin/user chat ids.');
$assert(str_contains($telegram, 'safeDeliveryError'), 'Telegram service must route delivery failures through a redaction helper.');
$assert(str_contains($telegram, "'[redacted-bot-token]'"), 'Telegram log redaction must remove an accidentally surfaced bot token.');
$assert(!str_contains($invites, "failed for ' . \$recipientId"), 'Invite failure logs must not contain raw recipient ids.');
$assert(str_contains($invites, "'[redacted-bot-token]'"), 'Invite failure logs must redact an accidentally surfaced bot token.');

foreach ([
    'bot/rating-archive.php'=>$ratingArchive,
    'bot/tournament-status.php'=>$tournamentStatus,
    'bot/tournament-hall.php'=>$tournamentHall,
] as $path=>$source) {
    $assert(
        str_contains($source, "'environment'] ?? '') === 'staging'"),
        $path . ' debug exception must be staging-only.'
    );
    $assert(
        str_contains($source, "['is_staging_test_user']"),
        $path . ' debug exception must require authenticated staging-test identity.'
    );
    $assert(
        str_contains($source, "['debug_error']"),
        $path . ' diagnostic owner must remain explicit for staging test evidence.'
    );
}

fwrite(STDOUT, "Mvp25_5PublicErrorLogSanitizationContractTest: {$assertions} assertions passed\n");
