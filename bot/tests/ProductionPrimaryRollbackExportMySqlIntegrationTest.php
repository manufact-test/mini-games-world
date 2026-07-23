<?php
declare(strict_types=1);

$dsn = trim((string)(getenv('MGW_TEST_MYSQL_DSN') ?: ''));
$user = (string)(getenv('MGW_TEST_MYSQL_USER') ?: '');
$password = (string)(getenv('MGW_TEST_MYSQL_PASSWORD') ?: '');
if ($dsn === '') {
    fwrite(STDOUT, "ProductionPrimaryRollbackExportMySqlIntegrationTest skipped: no CI database.\n");
    return;
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/bot/database/DatabaseConnectionInterface.php';
require_once $projectRoot . '/bot/database/DatabaseConfig.php';
require_once $projectRoot . '/bot/database/PdoDatabaseConnection.php';
require_once $projectRoot . '/bot/database/PdoConnectionFactory.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStateSchemaInstaller.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryProjectionOutboxSchemaInstaller.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryProjectionAuditorInterface.php';
require_once $projectRoot . '/bot/runtime/ProductionPrimaryRollbackExportGate.php';
require_once $projectRoot . '/bot/runtime/ProductionPrimaryRollbackExportVerifier.php';
require_once $projectRoot . '/bot/runtime/ProductionPrimaryRollbackExportService.php';

final class ProductionRollbackMySqlAuditor implements RuntimePrimaryProjectionAuditorInterface
{
    public int $calls = 0;

    public function auditOnly(array $snapshot, int $stateRevision, string $stateSha256): array
    {
        $this->calls++;
        return [
            'ok' => true,
            'parity_ok' => true,
            'read_only' => true,
            'state_revision' => $stateRevision,
            'state_sha256' => $stateSha256,
            'projected_modules' => [
                'accounts', 'realtime', 'economy', 'notifications', 'invites',
                'history', 'shop', 'payments', 'weekly_bonus',
            ],
            'all_module_fingerprint' => hash(
                'sha256',
                'mysql-rollback-audit|' . $stateRevision . '|' . $stateSha256
            ),
        ];
    }
}

$dsnValue = static function (string $key) use ($dsn): string {
    return preg_match('/(?:^|[:;])' . preg_quote($key, '/') . '=([^;]+)/', $dsn, $matches) === 1
        ? trim((string)$matches[1])
        : '';
};
$host = $dsnValue('host');
$port = (int)($dsnValue('port') !== '' ? $dsnValue('port') : '3306');
$name = $dsnValue('dbname');
$config = DatabaseConfig::fromApplicationConfig([
    'database' => [
        'enabled' => true,
        'driver' => 'mysql',
        'host' => $host,
        'port' => $port,
        'name' => $name,
        'user' => $user,
        'password' => $password,
        'charset' => 'utf8mb4',
    ],
]);
$database = PdoConnectionFactory::create($config);

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true)
                . ', got ' . var_export($actual, true)
        );
    }
};
$remove = static function (string $path) use (&$remove): void {
    if (!file_exists($path) && !is_link($path)) return;
    if (is_dir($path) && !is_link($path)) {
        $items = scandir($path);
        if (!is_array($items)) throw new RuntimeException('Integration directory could not be listed.');
        foreach ($items as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $remove($path . '/' . $entry);
        }
        if (!rmdir($path)) throw new RuntimeException('Integration directory could not be removed.');
        return;
    }
    if (!unlink($path)) throw new RuntimeException('Integration file could not be removed.');
};
$canonicalJson = static function (array $value): string {
    $canonicalize = static function (mixed $item) use (&$canonicalize): mixed {
        if (!is_array($item)) return $item;
        if (!array_is_list($item)) ksort($item, SORT_STRING);
        foreach ($item as $key => $child) $item[$key] = $canonicalize($child);
        return $item;
    };
    return json_encode(
        $canonicalize($value),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
};
$cleanup = static function () use ($database): void {
    $database->execute(
        'DROP TABLE IF EXISTS ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE
    );
    $database->execute(
        'DROP TABLE IF EXISTS ' . RuntimePrimaryStateSchemaInstaller::TABLE
    );
};

$root = sys_get_temp_dir() . '/mgw-rollback-mysql-' . bin2hex(random_bytes(6));
$deployedProject = $root . '/public_html';
$outputRoot = $root . '/exports';

$cleanup();
try {
    foreach ([$root, $deployedProject, $outputRoot] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new RuntimeException('Integration directory could not be created.');
        }
        if (!chmod($directory, 0700)) {
            throw new RuntimeException('Integration directory permissions could not be secured.');
        }
    }

    (new RuntimePrimaryStateSchemaInstaller($database))->install();
    (new RuntimePrimaryProjectionOutboxSchemaInstaller($database))->install();

    $snapshot = [
        'users' => [['id' => 'mysql_user', 'balance_match' => 91]],
        'games' => [],
        'queue' => [],
        'transactions' => [],
        'support' => [],
        'shop_orders' => [],
        'payments' => [],
        'notifications' => [],
        'invites' => [],
        'system' => ['fees_match' => 3, 'fees_gold' => 1],
    ];
    $stateJson = $canonicalJson($snapshot);
    $stateSha = hash('sha256', $stateJson);
    $revision = 2;
    $now = '2026-07-23T15:00:00+00:00';

    $database->execute(
        'INSERT INTO ' . RuntimePrimaryStateSchemaInstaller::TABLE . '
            (singleton_id, revision, state_json, state_sha256, created_at_utc, updated_at_utc)
         VALUES
            (1, :revision, :state_json, :state_sha256, :created_at_utc, :updated_at_utc)',
        [
            'revision' => $revision,
            'state_json' => $stateJson,
            'state_sha256' => $stateSha,
            'created_at_utc' => $now,
            'updated_at_utc' => $now,
        ]
    );
    for ($eventRevision = 1; $eventRevision <= $revision; $eventRevision++) {
        $database->execute(
            'INSERT INTO ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE . '
                (state_revision, event_id, projection_version, state_sha256, state_json,
                 status, attempt_count, lease_token, lease_expires_at_utc, last_error,
                 available_at_utc, created_at_utc, updated_at_utc)
             VALUES
                (:state_revision, :event_id, :projection_version, :state_sha256, :state_json,
                 :status, :attempt_count, :lease_token, :lease_expires_at_utc, :last_error,
                 :available_at_utc, :created_at_utc, :updated_at_utc)',
            [
                'state_revision' => $eventRevision,
                'event_id' => hash('sha256', 'mysql-rollback-event|' . $eventRevision),
                'projection_version' => 'mysql-rollback-test-v1',
                'state_sha256' => $stateSha,
                'state_json' => $stateJson,
                'status' => 'completed',
                'attempt_count' => 1,
                'lease_token' => '',
                'lease_expires_at_utc' => '',
                'last_error' => '',
                'available_at_utc' => $now,
                'created_at_utc' => $now,
                'updated_at_utc' => $now,
            ]
        );
    }

    $stateBefore = $database->fetchAll(
        'SELECT * FROM ' . RuntimePrimaryStateSchemaInstaller::TABLE
    );
    $outboxBefore = $database->fetchAll(
        'SELECT * FROM ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE
        . ' ORDER BY state_revision'
    );

    $requestId = str_repeat('9', 32);
    $gateReport = [
        'ready' => true,
        'contract_version' => ProductionPrimaryRollbackExportGate::CONTRACT_VERSION,
        'activation_build' => ProductionPrimaryRollbackExportGate::ACTIVATION_BUILD,
        'request_id' => $requestId,
        'expected_state_revision' => $revision,
        'expected_state_sha256' => $stateSha,
        'database_identity_fingerprint' => str_repeat('a', 64),
        'activation_plan_fingerprint' => str_repeat('b', 64),
        'activation_source_fingerprint' => str_repeat('c', 64),
        'output_root_fingerprint' => hash(
            'sha256',
            realpath($outputRoot) ?: $outputRoot
        ),
        'authorization_expires_at_utc' => '2026-07-23T15:09:00+00:00',
        'reason_fingerprint' => str_repeat('d', 64),
    ];
    $auditor = new ProductionRollbackMySqlAuditor();
    $result = (new ProductionPrimaryRollbackExportService(
        $database,
        $auditor,
        new ProductionPrimaryRollbackExportVerifier()
    ))->export(
        realpath($deployedProject) ?: $deployedProject,
        realpath($outputRoot) ?: $outputRoot,
        $gateReport
    );

    $assertSame(true, $result['ok'], 'Real MySQL rollback export must pass');
    $assertSame($revision, $result['state_revision'], 'Real MySQL revision must remain exact');
    $assertSame($stateSha, $result['state_sha256'], 'Real MySQL state SHA must remain exact');
    $assertSame(false, $result['database_write_executed'], 'Exporter must report no DB write');
    $assertSame(1, $auditor->calls, 'Real MySQL export must run one parity audit');
    $assertTrue(
        is_dir($outputRoot . '/rollback-' . $requestId),
        'Real MySQL export artifact must be finalized'
    );

    $stateAfter = $database->fetchAll(
        'SELECT * FROM ' . RuntimePrimaryStateSchemaInstaller::TABLE
    );
    $outboxAfter = $database->fetchAll(
        'SELECT * FROM ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE
        . ' ORDER BY state_revision'
    );
    $assertSame($stateBefore, $stateAfter, 'Rollback exporter must not mutate MySQL state row');
    $assertSame($outboxBefore, $outboxAfter, 'Rollback exporter must not mutate MySQL outbox rows');
} finally {
    $cleanup();
    $remove($root);
}

fwrite(
    STDOUT,
    "ProductionPrimaryRollbackExportMySqlIntegrationTest passed: {$assertions} assertions.\n"
);
