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

$limiter = $read('bot/services/UserActionRateLimiter.php');
$support = $read('bot/support.php');
$friends = $read('bot/friends.php');
$fingerprint = $read('bot/helpers/staging-e2e-runtime-files.txt');

foreach ([
    "'support_create' => ['max_requests'=>5, 'window_seconds'=>900]",
    "'support_reply' => ['max_requests'=>12, 'window_seconds'=>600]",
    "'player_report' => ['max_requests'=>10, 'window_seconds'=>600]",
] as $policy) {
    $assert(str_contains($limiter, $policy), 'Canonical default policy missing: ' . $policy);
}

$assert(str_contains($limiter, "security_rate_limits"), 'Rate limits must allow bounded private config overrides.');
$assert(str_contains($limiter, "max(1, min(500"), 'Request-count override must remain bounded.');
$assert(str_contains($limiter, "max(30, min(86400"), 'Window override must remain bounded.');
$assert(str_contains($limiter, 'mgw_support_tickets'), 'Support-create limit must use canonical support rows.');
$assert(str_contains($limiter, 'mgw_support_ticket_messages'), 'Support-reply limit must use canonical support messages.');
$assert(str_contains($limiter, 'mgw_player_reports'), 'Report limit must use canonical report rows.');

$assert(str_contains($support, "assertAllowed('support_create', \$mgwId)"), 'Support create must be rate-limited by authenticated MGW id.');
$assert(str_contains($support, "assertAllowed('support_reply', \$mgwId)"), 'Support reply must be rate-limited by authenticated MGW id.');
$assert(str_contains($friends, "assertAllowed('player_report', \$actorMgwId)"), 'Player reports must be rate-limited by authenticated actor.');
foreach ([$support, $friends] as $source) {
    $assert(str_contains($source, "header('Retry-After: '"), '429 response must publish Retry-After.');
    $assert(str_contains($source, "'code'=>'rate_limited'"), 'Rate-limited response must have a stable public code.');
    $assert(str_contains($source, "], 429);"), 'Rate-limited response must use HTTP 429.');
}

foreach ([
    'bot/services/UserActionRateLimiter.php',
    'bot/support.php',
    'bot/support/SupportTicketService.php',
    'bot/support/SupportTelegramNotifier.php',
    'bot/friends.php',
    'bot/social/PlayerReportService.php',
] as $path) {
    $assert(str_contains($fingerprint, $path), 'Exact staging fingerprint must include ' . $path . '.');
}

fwrite(STDOUT, "Mvp25_5RateLimitContractTest: {$assertions} assertions passed\n");
