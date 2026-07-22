<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/storage/contracts/StorageTransactionInterface.php';
require $projectRoot . '/bot/storage/contracts/StorageAdapterInterface.php';
require $projectRoot . '/bot/database/DatabaseConnectionInterface.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStateSchemaInstaller.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryProjectionOutboxSchemaInstaller.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryProjectionOutboxWriter.php';
require $projectRoot . '/bot/runtime/DatabasePrimaryStateStorageAdapter.php';

final class ProjectionOutboxTestConnection implements DatabaseConnectionInterface
{
    private bool $stateSchema = false;
    private bool $outboxSchema = false;
    private ?array $state = null;
    private array $events = [];
    private bool $failNextEvent = false;

    public function driver(): string { return 'sqlite'; }
    public function pdo(): PDO { throw new RuntimeException('unused'); }
    public function failNextEvent(): void { $this->failNextEvent = true; }
    public function events(): array { ksort($this->events); return array_values($this->events); }

    public function execute(string $sql, array $params = []): int
    {
        $sql = strtolower(preg_replace('/\s+/', ' ', trim($sql)) ?? '');
        if (str_starts_with($sql, 'create table if not exists ' . RuntimePrimaryStateSchemaInstaller::TABLE)) {
            $this->stateSchema = true;
            return 0;
        }
        if (str_starts_with($sql, 'create table if not exists ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE)) {
            $this->outboxSchema = true;
            return 0;
        }
        if (str_starts_with($sql, 'create index if not exists')) return 0;

        if (str_starts_with($sql, 'insert into ' . RuntimePrimaryStateSchemaInstaller::TABLE)) {
            $this->state = [
                'singleton_id' => 1,
                'revision' => (int)$params['revision'],
                'state_json' => (string)$params['state_json'],
                'state_sha256' => (string)$params['state_sha256'],
                'created_at_utc' => (string)$params['created_at_utc'],
                'updated_at_utc' => (string)$params['updated_at_utc'],
            ];
            return 1;
        }
        if (str_starts_with($sql, 'update ' . RuntimePrimaryStateSchemaInstaller::TABLE)) {
            if ($this->state === null
                || (int)$this->state['revision'] !== (int)$params['expected_revision']) return 0;
            $this->state['revision'] = (int)$params['next_revision'];
            $this->state['state_json'] = (string)$params['state_json'];
            $this->state['state_sha256'] = (string)$params['state_sha256'];
            $this->state['updated_at_utc'] = (string)$params['updated_at_utc'];
            return 1;
        }
        if (str_starts_with($sql, 'insert into ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE)) {
            if ($this->failNextEvent) {
                $this->failNextEvent = false;
                throw new RuntimeException('forced event failure');
            }
            $revision = (int)$params['state_revision'];
            if (isset($this->events[$revision])) throw new RuntimeException('duplicate event');
            $this->events[$revision] = [
                'state_revision' => $revision,
                'event_id' => (string)$params['event_id'],
                'projection_version' => (string)$params['projection_version'],
                'state_sha256' => (string)$params['state_sha256'],
                'state_json' => (string)$params['state_json'],
                'status' => (string)$params['status'],
                'attempt_count' => 0,
                'created_at_utc' => (string)$params['created_at_utc'],
            ];
            return 1;
        }
        throw new RuntimeException('Unexpected SQL execute: ' . $sql);
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $sql = strtolower(preg_replace('/\s+/', ' ', trim($sql)) ?? '');
        if (str_starts_with($sql, 'pragma table_info(' . RuntimePrimaryStateSchemaInstaller::TABLE)) {
            if (!$this->stateSchema) return [];
            return [
                ['name' => 'singleton_id', 'type' => 'INTEGER', 'notnull' => 1, 'pk' => 1],
                ['name' => 'revision', 'type' => 'INTEGER', 'notnull' => 1, 'pk' => 0],
                ['name' => 'state_json', 'type' => 'TEXT', 'notnull' => 1, 'pk' => 0],
                ['name' => 'state_sha256', 'type' => 'TEXT', 'notnull' => 1, 'pk' => 0],
                ['name' => 'created_at_utc', 'type' => 'TEXT', 'notnull' => 1, 'pk' => 0],
                ['name' => 'updated_at_utc', 'type' => 'TEXT', 'notnull' => 1, 'pk' => 0],
            ];
        }
        if (str_starts_with($sql, 'pragma table_info(' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE)) {
            if (!$this->outboxSchema) return [];
            $columns = [
                'state_revision' => ['INTEGER', 1], 'event_id' => ['TEXT', 0],
                'projection_version' => ['TEXT', 0], 'state_sha256' => ['TEXT', 0],
                'state_json' => ['TEXT', 0], 'status' => ['TEXT', 0],
                'attempt_count' => ['INTEGER', 0], 'lease_token' => ['TEXT', 0],
                'lease_expires_at_utc' => ['TEXT', 0], 'last_error' => ['TEXT', 0],
                'available_at_utc' => ['TEXT', 0], 'created_at_utc' => ['TEXT', 0],
                'updated_at_utc' => ['TEXT', 0],
            ];
            $rows = [];
            foreach ($columns as $name => [$type, $pk]) {
                $rows[] = ['name' => $name, 'type' => $type, 'notnull' => 1, 'pk' => $pk];
            }
            return $rows;
        }
        if (str_starts_with($sql, 'select singleton_id, revision, state_json')) {
            return $this->state === null ? [] : [$this->state];
        }
        if (str_starts_with($sql, 'select state_revision, event_id, projection_version')) {
            $revision = (int)$params['state_revision'];
            return isset($this->events[$revision]) ? [$this->events[$revision]] : [];
        }
        throw new RuntimeException('Unexpected SQL fetch: ' . $sql);
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $rows = $this->fetchAll($sql, $params);
        if ($rows === []) return null;
        $firstRow = $rows[0] ?? null;
        if (!is_array($firstRow) || $firstRow === []) return null;
        $value = reset($firstRow);
        return $value === false ? null : $value;
    }

    public function transaction(callable $callback): mixed
    {
        $state = $this->state;
        $events = $this->events;
        try {
            return $callback($this);
        } catch (Throwable $error) {
            $this->state = $state;
            $this->events = $events;
            throw $error;
        }
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$throws = static function (callable $callback, string $part) use (&$assertions): void {
    $assertions++;
    try { $callback(); }
    catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($part))) return;
        throw $error;
    }
    throw new RuntimeException('Expected exception was not thrown.');
};

$db = new ProjectionOutboxTestConnection();
(new RuntimePrimaryStateSchemaInstaller($db))->install();
$outbox = (new RuntimePrimaryProjectionOutboxSchemaInstaller($db))->install();
$assert(($outbox['ok'] ?? false) === true, 'Outbox schema must install');

$adapter = new DatabasePrimaryStateStorageAdapter($db, new RuntimePrimaryProjectionOutboxWriter());
$source = ['users' => ['100' => ['balance' => 50]], 'games' => [], 'system' => ['sequence' => 1]];
$seed = $adapter->initializeFromSnapshot($source);
$assert(($seed['projection_event_created'] ?? false) === true, 'Seed must create projection event');
$assert(count($db->events()) === 1, 'Seed must create exactly one event');
$assert(($db->events()[0]['state_revision'] ?? 0) === 1, 'Seed event must target revision one');

$seedAgain = $adapter->initializeFromSnapshot($source);
$assert(($seedAgain['projection_event_created'] ?? true) === false, 'Repeated seed must be idempotent');
$assert(count($db->events()) === 1, 'Repeated seed must not duplicate event');

$adapter->transaction(static function (array &$data): void {
    $data['users']['100']['balance'] = 75;
    $data['system']['sequence'] = 2;
});
$assert(($adapter->status()['revision'] ?? 0) === 2, 'Mutation must advance state revision');
$assert(count($db->events()) === 2, 'Mutation must create one projection event');
$assert(($db->events()[1]['state_revision'] ?? 0) === 2, 'Mutation event must match revision two');

$adapter->transaction(static fn(array &$data): int => count($data));
$assert(count($db->events()) === 2, 'No-op transaction must not create event');

$db->failNextEvent();
$throws(static function () use ($adapter): void {
    $adapter->transaction(static function (array &$data): void {
        $data['users']['100']['balance'] = 80;
    });
}, 'forced event failure');
$assert(($adapter->status()['revision'] ?? 0) === 2, 'Event failure must roll back revision');
$assert($adapter->readOnly(static fn(array $data): int => (int)$data['users']['100']['balance']) === 75, 'Event failure must roll back state');
$assert(count($db->events()) === 2, 'Event failure must not leave partial event');

fwrite(STDOUT, "RuntimePrimaryProjectionOutboxTest passed: {$assertions} assertions.\n");
