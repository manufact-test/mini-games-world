<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/services/PresenceService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$root = sys_get_temp_dir() . '/mgw-presence-lease-' . bin2hex(random_bytes(8));
$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        if (is_file($path)) @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $removeTree($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
};

try {
    $presence = new PresenceService($root);
    $accountId = 'test-account-a';
    $sessionId = 'persistent-device-session';
    $oldLease = 'document-old';
    $newLease = 'document-new';

    $presence->touch($accountId, $sessionId, $oldLease);
    $presence->touch($accountId, $sessionId, $newLease);

    $online = $presence->onlineAccountIds();
    $assert($online === [$accountId],
        'Two open documents for one account must count as one online player.');

    $accountDirectory = $root . DIRECTORY_SEPARATOR . 'account-' . hash('sha256', $accountId);
    $leases = glob($accountDirectory . DIRECTORY_SEPARATOR . 'session-*.presence') ?: [];
    $assert(count($leases) === 2,
        'Each visible document must own an independent lease even with the same persistent session.');

    $presence->leave($accountId, $sessionId, $oldLease);
    $onlineAfterOldLeave = $presence->onlineAccountIds();
    $assert($onlineAfterOldLeave === [$accountId],
        'A delayed leave from the old document must not turn the new document offline.');

    $oldPath = $accountDirectory . DIRECTORY_SEPARATOR . 'session-'
        . hash('sha256', $sessionId . "\0presence:" . $oldLease) . '.presence';
    $newPath = $accountDirectory . DIRECTORY_SEPARATOR . 'session-'
        . hash('sha256', $sessionId . "\0presence:" . $newLease) . '.presence';
    $assert(is_file($oldPath) && is_file($newPath),
        'Old and new document leases must remain independently addressable during handoff grace.');

    file_put_contents($oldPath, json_encode([
        'touched_at' => time(),
        'leave_after' => time() - 1,
    ], JSON_UNESCAPED_SLASHES), LOCK_EX);
    $onlineAfterOldExpiry = $presence->onlineAccountIds();
    $assert($onlineAfterOldExpiry === [$accountId] && !is_file($oldPath) && is_file($newPath),
        'Pruning the expired old lease must preserve the still-live new lease.');

    $presence->touch($accountId, $sessionId, $newLease);
    $presence->leave($accountId, $sessionId, $newLease);
    file_put_contents($newPath, json_encode([
        'touched_at' => time(),
        'leave_after' => time() - 1,
    ], JSON_UNESCAPED_SLASHES), LOCK_EX);
    $assert($presence->onlineAccountIds() === [],
        'Closing and expiring the final document must remove the account from online players.');
    $assert(!is_dir($accountDirectory),
        'The final expired lease must clean up the empty account directory.');

    $client = file_get_contents(dirname(__DIR__, 2) . '/app/assets/js/production-v110-presence.js');
    $endpoint = file_get_contents(dirname(__DIR__) . '/presence.php');
    $service = file_get_contents(dirname(__DIR__) . '/services/PresenceService.php');
    $statsOwner = file_get_contents(dirname(__DIR__, 2) . '/app/assets/js/stats-owner-v110.js');
    $assert(is_string($client)
        && str_contains($client, 'const presenceLeaseId = createPresenceLeaseId();')
        && str_contains($client, '// Presence transport starts before the profile bootstrap.')
        && str_contains($client, "window.addEventListener('pagehide'")
        && str_contains($client, 'if (!event.persisted) sendLeaveBeacon();'),
        'The client must create a document lease before bootstrap and leave only on a real document exit.');
    $assert(is_string($endpoint)
        && str_contains($endpoint, '$presence->touch($accountId, $sessionId, $presenceLeaseId);')
        && str_contains($endpoint, '$presence->leave($accountId, $sessionId, $presenceLeaseId);'),
        'The endpoint must pass the exact document lease through touch and leave.');
    $assert(is_string($service)
        && str_contains($service, '$sessionId . "\\0presence:" . $presenceLeaseId')
        && str_contains($service, 'LEAVE_GRACE_SEC = 12'),
        'The server must isolate document leases and use bounded handoff grace.');
    $assert(is_string($statsOwner)
        && !str_contains($statsOwner, 'stableOnlineCount')
        && !str_contains($statsOwner, 'ONLINE_DROP_GRACE_MS'),
        'Online correctness must come from lease ordering, never UI smoothing.');

    fwrite(STDOUT, "PresenceServiceDocumentLeaseAcceptanceTest: {$assertions} assertions passed\n");
} finally {
    $removeTree($root);
}
