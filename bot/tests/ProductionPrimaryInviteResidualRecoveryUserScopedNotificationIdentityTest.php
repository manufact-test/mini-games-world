<?php
declare(strict_types=1);

$root = dirname(__DIR__);

require_once $root . '/database/DatabaseConnectionInterface.php';
require_once $root . '/runtime/RuntimePrimaryProjectionAuditorInterface.php';
require_once $root . '/runtime/ProductionPrimaryInviteResidualRecoveryService.php';

$database = new class implements DatabaseConnectionInterface {
    public function driver(): string
    {
        return 'test';
    }

    public function execute(string $sql, array $parameters = []): int
    {
        throw new LogicException('Database execution is not expected in identity test.');
    }

    public function fetchAll(string $sql, array $parameters = []): array
    {
        throw new LogicException('Database reads are not expected in identity test.');
    }

    public function fetchValue(string $sql, array $parameters = []): mixed
    {
        throw new LogicException('Database reads are not expected in identity test.');
    }

    public function transaction(callable $callback): mixed
    {
        throw new LogicException('Transactions are not expected in identity test.');
    }
};

$auditor = new class implements RuntimePrimaryProjectionAuditorInterface {
    public function auditOnly(array $snapshot, int $stateRevision, string $stateSha256): array
    {
        throw new LogicException('Projection audit is not expected in identity test.');
    }
};

$service = new ProductionPrimaryInviteResidualRecoveryService(
    $database,
    $auditor
);

$method = new ReflectionMethod(
    ProductionPrimaryInviteResidualRecoveryService::class,
    'stateNotificationIdentity'
);
$method->setAccessible(true);

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$inspect = static function (array $notifications) use ($method, $service): array {
    $blockers = [];
    $identity = $method->invokeArgs(
        $service,
        [
            ['notifications' => $notifications],
            &$blockers,
        ]
    );

    return [$identity, $blockers];
};

[$differentUsers, $differentUserBlockers] = $inspect([
    [
        'id' => 'notification-user-a',
        'user_id' => 'user-a',
        'event_key' => 'weekly_bonus:credited',
    ],
    [
        'id' => 'notification-user-b',
        'user_id' => 'user-b',
        'event_key' => 'weekly_bonus:credited',
    ],
]);

[$differentUserIds, $differentUserEvents] = $differentUsers;

$assertTrue(
    $differentUserBlockers === [],
    'The same event key must be valid for different users.'
);
$assertTrue(
    count($differentUserIds) === 2,
    'Both cross-user notification IDs must remain in state identity.'
);
$assertTrue(
    count($differentUserEvents) === 2,
    'Cross-user event scopes must remain distinct.'
);
$assertTrue(
    isset($differentUserEvents['user-a|weekly_bonus:credited'])
        && isset($differentUserEvents['user-b|weekly_bonus:credited']),
    'State event identity must include legacy user ID and event key.'
);

[$sameUser, $sameUserBlockers] = $inspect([
    [
        'id' => 'notification-user-a-1',
        'user_id' => 'user-a',
        'event_key' => 'weekly_bonus:credited',
    ],
    [
        'id' => 'notification-user-a-2',
        'user_id' => 'user-a',
        'event_key' => 'weekly_bonus:credited',
    ],
]);

[$sameUserIds, $sameUserEvents] = $sameUser;

$assertTrue(
    in_array(
        'DB-primary notifications contain invalid or duplicate identity.',
        $sameUserBlockers,
        true
    ),
    'The same event key for one user must remain blocked.'
);
$assertTrue(
    count($sameUserIds) === 1 && count($sameUserEvents) === 1,
    'A same-user duplicate must not enter notification identity twice.'
);

[, $missingUserBlockers] = $inspect([
    [
        'id' => 'notification-without-user',
        'event_key' => 'weekly_bonus:credited',
    ],
]);

$assertTrue(
    in_array(
        'DB-primary notifications contain invalid or duplicate identity.',
        $missingUserBlockers,
        true
    ),
    'Notification identity without user ID must remain blocked.'
);

$source = file_get_contents(
    $root . '/runtime/ProductionPrimaryInviteResidualRecoveryService.php'
);

$assertTrue(
    is_string($source)
        && str_contains(
            $source,
            "\$eventScope = \$legacyUserId . '|' . \$event;"
        )
        && str_contains(
            $source,
            '$eventPresent = isset($stateNotificationEvents[$eventScope]);'
        )
        && !str_contains(
            $source,
            '$eventPresent = isset($stateNotificationEvents[$event]);'
        ),
    'Database recovery identity must compare the same user-scoped event key as state.'
);

fwrite(
    STDOUT,
    'ProductionPrimaryInviteResidualRecoveryUserScopedNotificationIdentityTest: '
    . $assertions
    . " assertions passed\n"
);
