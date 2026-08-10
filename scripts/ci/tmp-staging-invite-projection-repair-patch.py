from pathlib import Path


def replace_exact(path: str, old: str, new: str) -> None:
    target = Path(path)
    text = target.read_text(encoding='utf-8')
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{path}: expected one replacement, found {count}')
    target.write_text(text.replace(old, new, 1), encoding='utf-8')


reset = 'bot/services/StagingTestPlayerStateResetService.php'
replace_exact(
    reset,
    """        if ($removedInvites === []) {
            $this->assertInviteParity($snapshot);
""",
    """        if ($removedInvites === []) {
            $this->synchronizeAndAssertInviteParity($snapshot);
""",
)
replace_exact(
    reset,
    """        $this->assertInviteParity($snapshot);
        return $deleted + ['parity' => true];
    }

    private function assertInviteParity(array $snapshot): void
""",
    """        $this->synchronizeAndAssertInviteParity($snapshot);
        return $deleted + ['parity' => true];
    }

    private function synchronizeAndAssertInviteParity(array $snapshot): void
""",
)
replace_exact(
    reset,
    """        $database = PdoConnectionFactory::create($databaseConfig);
        $inviteAudit = (new RuntimeInviteRepository($this->config, $this->router, $database))
            ->auditParity($snapshot);
        if (($inviteAudit['ok'] ?? false) !== true) {
            throw new RuntimeException('Staging test invite cleanup did not restore invite parity.');
        }
""",
    """        $database = PdoConnectionFactory::create($databaseConfig);
        $repository = new RuntimeInviteRepository($this->config, $this->router, $database);
        // The JSON snapshot is canonical. Reset must repair a missing/stale DB
        // projection before it claims parity, just like the economy reset path.
        $synchronized = $repository->synchronize($snapshot);
        $inviteAudit = $repository->auditParity($snapshot);
        if (($synchronized['parity'] ?? false) !== true || ($inviteAudit['ok'] ?? false) !== true) {
            throw new RuntimeException('Staging test invite cleanup did not restore invite parity.');
        }
""",
)

recovery = 'bot/services/StagingTestOnlyInviteOrphanRecoveryService.php'
replace_exact(
    recovery,
    """        if ($candidates === []) {
            return [
                'ok' => true,
                'service' => 'mini-games-world-staging-test-only-invite-orphan-recovery',
                'status' => 'already_clean',
                'candidate_count' => 0,
                'deleted' => ['invite_rows' => 0, 'notification_rows' => 0, 'invite_event_rows' => 0],
                'parity' => ['invites' => true, 'test_notifications' => true],
                'production_changed' => false,
                'live_payments_used' => false,
            ];
        }
""",
    """        if ($candidates === []) {
            $inviteAudit = (new RuntimeInviteRepository($this->config, $this->router, $database))
                ->auditParity($snapshot);
            $testNotificationsParity = true;
            foreach (self::TEST_PLAYER_IDS as $legacyUserId) {
                $notificationAudit = (new RuntimeNotificationRepository($this->config, $this->router, $database))
                    ->auditParity($snapshot, $legacyUserId);
                $testNotificationsParity = $testNotificationsParity
                    && (($notificationAudit['ok'] ?? false) === true);
            }
            return [
                'ok' => true,
                'service' => 'mini-games-world-staging-test-only-invite-orphan-recovery',
                'status' => 'already_clean',
                'candidate_count' => 0,
                'deleted' => ['invite_rows' => 0, 'notification_rows' => 0, 'invite_event_rows' => 0],
                'parity' => [
                    'invites' => ($inviteAudit['ok'] ?? false) === true,
                    'test_notifications' => $testNotificationsParity,
                ],
                'production_changed' => false,
                'live_payments_used' => false,
            ];
        }
""",
)

contract = 'bot/tests/StagingTestOnlyInviteOrphanRecoveryContractTest.php'
path = Path(contract)
text = path.read_text(encoding='utf-8')
marker = """$assert(str_contains($service, '$deleted = $database->transaction(')
    && str_contains($service, '$inviteAudit = (new RuntimeInviteRepository'),
    'Recovery must delete in one DB transaction and audit afterwards.');
"""
insert = marker + """$assert(str_contains($service, 'if ($candidates === [])')
    && str_contains($service, "'invites' => (\$inviteAudit['ok'] ?? false) === true")
    && !str_contains($service, "'parity' => ['invites' => true, 'test_notifications' => true]"),
    'Already-clean recovery must report real invite parity instead of hard-coding success.');
"""
if text.count(marker) != 1:
    raise SystemExit(f'{contract}: contract marker count={text.count(marker)}')
path.write_text(text.replace(marker, insert, 1), encoding='utf-8')

Path('bot/tests/StagingTestPlayerInviteProjectionRecoveryContractTest.php').write_text(r'''<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = file_get_contents($root . '/bot/services/StagingTestPlayerStateResetService.php');
if (!is_string($service)) {
    throw new RuntimeException('Cannot read staging test-player reset service.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(substr_count($service, '$this->synchronizeAndAssertInviteParity($snapshot);') === 2,
    'Both zero-delete and post-delete invite cleanup paths must repair canonical projection parity.');
$methodStart = strpos($service, 'private function synchronizeAndAssertInviteParity(array $snapshot): void');
$methodEnd = $methodStart === false ? false : strpos($service, 'private function assertAvailable(', $methodStart);
$method = $methodStart === false || $methodEnd === false
    ? ''
    : substr($service, $methodStart, $methodEnd - $methodStart);
$syncPos = strpos($method, '$repository->synchronize($snapshot);');
$auditPos = strpos($method, '$repository->auditParity($snapshot);');
$assert($method !== '' && $syncPos !== false && $auditPos !== false && $syncPos < $auditPos,
    'Reset invite owner must synchronize canonical JSON into DB before the final read-only parity audit.');
$assert(str_contains($method, "(\$synchronized['parity'] ?? false) !== true")
    && str_contains($method, "(\$inviteAudit['ok'] ?? false) !== true"),
    'Reset must require both synchronization and final audit success.');
$assert(!str_contains($method, 'INSERT INTO') && !str_contains($method, 'UPDATE mgw_invites'),
    'Reset must delegate projection repair to RuntimeInviteRepository rather than owning SQL writes.');

fwrite(STDOUT, "StagingTestPlayerInviteProjectionRecoveryContractTest: {$assertions} assertions passed\n");
''', encoding='utf-8')
