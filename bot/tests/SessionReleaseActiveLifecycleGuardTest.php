<?php
declare(strict_types=1);

if (!function_exists('now_iso')) {
    function now_iso(): string { return gmdate('c'); }
}

require_once dirname(__DIR__) . '/services/SessionService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$service = new SessionService(['active_session_timeout_sec' => 180]);
$makeUser = static fn(string $status): array => [
    'id' => 'u1',
    'status' => $status,
    'current_game_id' => $status === 'playing' ? 'new_game' : null,
    'active_session_id' => 'session-a',
    'active_session_at' => now_iso(),
];

$playing = $makeUser('playing');
$service->releaseIfCurrent($playing, 'session-a');
$assert(($playing['active_session_id'] ?? null) === 'session-a', 'A stale finished-game response must not release a currently playing session.');
$assert(($playing['active_session_at'] ?? null) !== null, 'Playing session heartbeat must remain intact.');

$searching = $makeUser('searching');
$service->releaseIfCurrent($searching, 'session-a');
$assert(($searching['active_session_id'] ?? null) === 'session-a', 'A stale response must not release an active matchmaking session.');
$assert(($searching['active_session_at'] ?? null) !== null, 'Searching session heartbeat must remain intact.');

$idle = $makeUser('idle');
$service->releaseIfCurrent($idle, 'session-a');
$assert(($idle['active_session_id'] ?? 'sentinel') === null, 'Idle current session must still release exactly as before.');
$assert(($idle['active_session_at'] ?? 'sentinel') === null, 'Idle release must clear its heartbeat.');

$other = $makeUser('idle');
$service->releaseIfCurrent($other, 'session-b');
$assert(($other['active_session_id'] ?? null) === 'session-a', 'A non-current session must never release another session.');
$assert(($other['active_session_at'] ?? null) !== null, 'Non-current release attempt must preserve heartbeat.');

$finished = $makeUser('finished');
$service->releaseIfCurrent($finished, 'session-a');
$assert(($finished['active_session_id'] ?? 'sentinel') === null, 'Non-active lifecycle statuses must remain releasable.');

fwrite(STDOUT, "SessionReleaseActiveLifecycleGuardTest: {$assertions} assertions passed\n");
