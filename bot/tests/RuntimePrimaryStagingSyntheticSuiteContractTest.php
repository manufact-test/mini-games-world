<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$suite = file_get_contents($projectRoot . '/bot/runtime/RuntimePrimaryStagingSyntheticSuite.php');
$rollback = file_get_contents($projectRoot . '/bot/runtime/RuntimePrimarySyntheticRollback.php');
if (!is_string($suite) || !is_string($rollback)) {
    throw new RuntimeException('Synthetic staging suite sources are unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assertTrue(
    str_contains($suite, "if (\$environment !== 'staging')")
        && str_contains($suite, 'DB-primary synthetic suite is staging-only.'),
    'Synthetic suite must remain staging-only'
);
$assertTrue(
    str_contains($suite, 'RuntimePrimaryStagingStorageResolution')
        && str_contains($suite, '$this->resolution->storage()')
        && str_contains($suite, "'application_entrypoint_routed'"),
    'Synthetic suite must require a guarded unresolved-entrypoint storage resolution'
);
$assertTrue(
    str_contains($suite, 'new UserService(')
        && str_contains($suite, 'new NotificationService()')
        && str_contains($suite, 'new WeeklyMatchEconomyService('),
    'Synthetic suite must exercise real application services'
);
$assertTrue(
    str_contains($suite, '$storage->transaction(function (array &$db): void')
        && str_contains($suite, 'throw new RuntimePrimarySyntheticRollback($report)')
        && str_contains($rollback, 'final class RuntimePrimarySyntheticRollback'),
    'Synthetic service mutations must end through the explicit rollback signal'
);
$assertTrue(
    str_contains($suite, 'Synthetic staging transaction committed unexpectedly.')
        && str_contains($suite, 'catch (RuntimePrimarySyntheticRollback $rollback)'),
    'Synthetic suite must fail if the transaction commits instead of rolling back'
);
$assertTrue(
    substr_count($suite, '$storage->status()') >= 2
        && substr_count($suite, '$storage->readOnly(') >= 2
        && substr_count($suite, '$this->queueSummary()') >= 2,
    'Synthetic suite must compare state, snapshot and outbox before and after rollback'
);
$assertTrue(
    str_contains($suite, 'DB-primary state status changed after synthetic rollback.')
        && str_contains($suite, 'DB-primary state snapshot changed after synthetic rollback.')
        && str_contains($suite, 'Projection outbox changed after synthetic rollback.'),
    'Synthetic suite must fail closed on every persisted drift signal'
);
$assertTrue(
    str_contains($suite, 'ensureWelcomeGrant(')
        && str_contains($suite, 'addPaymentDecision(')
        && str_contains($suite, 'addShopOrderDecision(')
        && str_contains($suite, 'markAllRead(')
        && str_contains($suite, 'profileStats(')
        && str_contains($suite, 'publicUser('),
    'Synthetic suite must cover account, economy, notification and profile behavior'
);
$assertTrue(
    str_contains($suite, "'transaction_rolled_back' => true")
        && str_contains($suite, "'state_unchanged' => true")
        && str_contains($suite, "'snapshot_unchanged' => true")
        && str_contains($suite, "'outbox_unchanged' => true")
        && str_contains($suite, "'application_entrypoint_routed' => false"),
    'Synthetic success report must explicitly prove rollback and no routing'
);
$assertTrue(
    !str_contains($suite, 'bot/api.php')
        && !str_contains($suite, 'WebhookHandler.php')
        && !str_contains($suite, 'crontab')
        && !str_contains($suite, 'production-cutover.php'),
    'Synthetic suite must remain disconnected from real entrypoints and production controls'
);

fwrite(STDOUT, "RuntimePrimaryStagingSyntheticSuiteContractTest passed: {$assertions} assertions.\n");
