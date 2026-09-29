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

$supportService = $read('bot/support/SupportTicketService.php');
$reportService = $read('bot/social/PlayerReportService.php');
$supportEndpoint = $read('bot/support.php');

$assert(str_contains($supportService, 'USER_WRITE_REPLAY_WINDOW_SECONDS'), 'Support replay window must be explicit and server-side.');
$assert(str_contains($supportService, 'lockUserWriteScope'), 'Support writes must serialize on the authenticated user scope.');
$assert(str_contains($supportService, ' FOR UPDATE'), 'MySQL Support replay guard must use a row lock.');
$assert(str_contains($supportService, 'findRecentCreateReplay'), 'Support create must own exact replay lookup.');
$assert(str_contains($supportService, 'isRecentReplyReplay'), 'Support reply must own exact replay lookup.');
$assert(str_contains($supportService, 'storedAttachmentsMatch'), 'Support replay identity must include attachment bytes.');
$assert(str_contains($supportService, "'_request_replayed'"), 'Support service must signal replay to the HTTP notifier owner.');

$assert(str_contains($supportEndpoint, 'if (!$requestReplayed)'), 'Support create notifier must be skipped on replay.');
$assert(preg_match('/\\$adminAlertSent\\s*=\\s*\\$requestReplayed\\s*\\?\\s*false\\s*:/s', $supportEndpoint) === 1, 'Support reply notifier must be skipped on replay.');
$assert(substr_count($supportEndpoint, "'request_replayed' => $requestReplayed") === 2, 'Support responses must expose replay outcome for both writes.');

$assert(str_contains($reportService, 'SUBMIT_REPLAY_WINDOW_SECONDS'), 'Player report replay window must be explicit and server-side.');
$assert(str_contains($reportService, 'lockReporterWriteScope'), 'Player report writes must serialize on authenticated reporter scope.');
$assert(str_contains($reportService, 'findRecentExactReplay'), 'Player reports must reuse exact recent replay.');
$assert(str_contains($reportService, 'return $this->database->transaction'), 'Player report replay lookup and insert must share one transaction.');

fwrite(STDOUT, "Mvp25_5WriteReplaySafetyContractTest: {$assertions} assertions passed\n");
