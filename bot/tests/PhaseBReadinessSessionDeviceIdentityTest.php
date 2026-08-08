<?php
declare(strict_types=1);

if (!function_exists('now_iso')) {
    function now_iso(): string
    {
        return gmdate('c');
    }
}

require_once dirname(__DIR__) . '/services/MatchPreparationClockService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$service = new MatchPreparationClockService();
$deadline = gmdate('c', time() + 10);
$base = [
    'id' => 'game_ready',
    'status' => 'active',
    'launch_phase' => 'preparing',
    'player_ids' => ['u1', 'u2'],
    'preparation_deadline_at' => $deadline,
    'preparation_ready_devices' => [],
    'updated_at' => '2026-08-08T10:00:00Z',
];

$missingDevice = $base;
$service->markReady($missingDevice, 'u1', 'session-1', '');
$assert($missingDevice === $base, 'Readiness must require deviceId as well as sessionId.');

$missingSession = $base;
$service->markReady($missingSession, 'u1', '', 'device-1');
$assert($missingSession === $base, 'Readiness must require sessionId as well as deviceId.');

$nonParticipant = $base;
$service->markReady($nonParticipant, 'outsider', 'session-1', 'device-1');
$assert($nonParticipant === $base, 'Readiness must remain participant-bound.');

$game = $base;
$service->markReady($game, 'u1', 'session-1', 'device-1');
$expectedHash = hash('sha256', 'session-1|device-1');
$assert(($game['preparation_ready_devices']['u1']['device_hash'] ?? '') === $expectedHash, 'Ready identity must hash sessionId and deviceId together.');
$assert(($game['preparation_deadline_at'] ?? '') === $deadline, 'First readiness must not move the immutable preparation deadline.');
$assert(count($game['preparation_ready_devices']) === 1, 'Human readiness must add exactly that human identity.');

$afterFirstReady = $game;
$service->markReady($game, 'u1', 'session-1', 'device-1');
$assert($game === $afterFirstReady, 'Repeated readiness from the same session/device must be fully idempotent.');
$assert(($game['preparation_deadline_at'] ?? '') === $deadline, 'Repeated readiness must never extend preparation timeout.');

$oldHash = (string)$game['preparation_ready_devices']['u1']['device_hash'];
$service->markReady($game, 'u1', 'session-1', 'device-2');
$newHash = (string)$game['preparation_ready_devices']['u1']['device_hash'];
$assert($newHash !== $oldHash, 'A different device identity must not be treated as the same ready device.');
$assert($newHash === hash('sha256', 'session-1|device-2'), 'Changed device identity must still use the canonical combined hash.');
$assert(($game['preparation_deadline_at'] ?? '') === $deadline, 'Changing ready device identity must not extend preparation timeout.');
$assert(count($game['preparation_ready_devices']) === 1, 'Replacing a user device identity must not increase ready player count.');

$service->markReady($game, 'u2', 'session-2', 'device-2');
$assert(count($game['preparation_ready_devices']) === 2, 'Second participant readiness must complete the human ready set.');
$assert(($game['preparation_deadline_at'] ?? '') === $deadline, 'Second participant readiness must keep the original deadline.');

$botGame = $base;
$botGame['player_ids'] = ['u1', 'bot_1'];
$service->markReady($botGame, 'u1', 'session-1', 'device-1');
$assert(($botGame['preparation_ready_devices']['bot_1']['device_hash'] ?? '') === 'server-bot', 'Bot readiness must remain server-owned.');
$assert(count($botGame['preparation_ready_devices']) === 2, 'Human readiness must auto-ready the bot exactly once.');
$botReadySnapshot = $botGame['preparation_ready_devices']['bot_1'];
$service->markReady($botGame, 'u1', 'session-1', 'device-2');
$assert($botGame['preparation_ready_devices']['bot_1'] === $botReadySnapshot, 'Changing human device must not rewrite server bot readiness.');
$assert(($botGame['preparation_deadline_at'] ?? '') === $deadline, 'Bot auto-readiness must never alter the preparation deadline.');

$source = file_get_contents(dirname(__DIR__) . '/services/MatchPreparationClockService.php');
if (!is_string($source)) throw new RuntimeException('Clock source is unavailable.');
$assert(
    str_contains($source, 'public function markReady(array &$game, string $userId, string $sessionId, string $deviceId): void'),
    'Readiness API must require user, session, and device identity explicitly.'
);
$assert(
    str_contains($source, "hash('sha256', \$sessionId . '|' . \$deviceId)"),
    'Readiness implementation must use the combined session/device hash.'
);
$assert(
    str_contains($source, 'hash_equals('),
    'Readiness implementation must compare repeated identities safely before writing.'
);

fwrite(STDOUT, "PhaseBReadinessSessionDeviceIdentityTest: {$assertions} assertions passed\n");
