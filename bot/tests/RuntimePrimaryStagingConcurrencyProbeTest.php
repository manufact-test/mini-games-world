<?php
declare(strict_types=1);

interface DatabaseConnectionInterface
{
    public function driver(): string;
    public function execute(string $sql, array $parameters = []): int;
    public function fetchAll(string $sql, array $parameters = []): array;
    public function fetchValue(string $sql, array $parameters = []): mixed;
    public function transaction(callable $callback): mixed;
}
final class RuntimePrimaryStagingConcurrencyProbeStore
{
    public bool $tableInstalled = false;
    public array $rows = [];
}
final class RuntimePrimaryStagingConcurrencyProbeDatabase implements DatabaseConnectionInterface
{
    public function __construct(private RuntimePrimaryStagingConcurrencyProbeStore $store) {}
    public function driver(): string { return 'mysql'; }
    public function execute(string $sql, array $parameters = []): int
    {
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($sql)) ?? '');
        if (str_starts_with($normalized, 'create table if not exists mgw_runtime_primary_lease_probe')) {
            $this->store->tableInstalled = true;
            return 0;
        }
        if (str_starts_with($normalized, 'insert into mgw_runtime_primary_lease_probe')) {
            $id = (string)$parameters['probe_id'];
            if (isset($this->store->rows[$id])) throw new RuntimeException('duplicate probe');
            $this->store->rows[$id] = [
                'probe_id' => $id,
                'state_revision' => (int)$parameters['state_revision'],
                'status' => (string)$parameters['status'],
                'lease_token' => (string)$parameters['lease_token'],
                'lease_expires_at_utc' => (string)$parameters['lease_expires_at_utc'],
                'created_at_utc' => (string)$parameters['created_at_utc'],
                'updated_at_utc' => (string)$parameters['updated_at_utc'],
            ];
            return 1;
        }
        if (str_starts_with($normalized, 'update mgw_runtime_primary_lease_probe')) {
            $id = (string)$parameters['probe_id'];
            if (!isset($this->store->rows[$id])) return 0;
            if ($this->store->rows[$id]['status'] !== (string)$parameters['expected_status']) return 0;
            $this->store->rows[$id]['status'] = (string)$parameters['status'];
            $this->store->rows[$id]['updated_at_utc'] = (string)$parameters['updated_at_utc'];
            if (array_key_exists('lease_token', $parameters)) {
                $this->store->rows[$id]['lease_token'] = (string)$parameters['lease_token'];
                $this->store->rows[$id]['lease_expires_at_utc'] = (string)$parameters['lease_expires_at_utc'];
            }
            return 1;
        }
        if (str_starts_with($normalized, 'delete from mgw_runtime_primary_lease_probe')) {
            $id = (string)$parameters['probe_id'];
            if (!isset($this->store->rows[$id])) return 0;
            unset($this->store->rows[$id]);
            return 1;
        }
        throw new RuntimeException('Unexpected concurrency probe execute SQL: ' . $normalized);
    }
    public function fetchAll(string $sql, array $parameters = []): array
    {
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($sql)) ?? '');
        if (str_starts_with($normalized, "show table status like 'mgw_runtime_primary_lease_probe'")) {
            return $this->store->tableInstalled ? [['Engine' => 'InnoDB']] : [];
        }
        if (str_contains($normalized, 'from mgw_runtime_primary_lease_probe')) {
            $id = (string)$parameters['probe_id'];
            return isset($this->store->rows[$id]) ? [$this->store->rows[$id]] : [];
        }
        throw new RuntimeException('Unexpected concurrency probe fetch SQL: ' . $normalized);
    }
    public function fetchValue(string $sql, array $parameters = []): mixed { return null; }
    public function transaction(callable $callback): mixed { return $callback($this); }
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingConcurrencyProbe.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $messagePart) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($messagePart))) return;
        throw new RuntimeException('Unexpected exception: ' . $error->getMessage());
    }
    throw new RuntimeException('Expected exception was not thrown.');
};

$directory = sys_get_temp_dir() . '/mgw-concurrency-probe-' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
$lockPath = $directory . '/probe.lock';
try {
    $store = new RuntimePrimaryStagingConcurrencyProbeStore();
    $first = new RuntimePrimaryStagingConcurrencyProbeDatabase($store);
    $second = new RuntimePrimaryStagingConcurrencyProbeDatabase($store);
    $result = (new RuntimePrimaryStagingConcurrencyProbe(
        $first,
        $second,
        $lockPath,
        120
    ))->run();

    $assertTrue(($result['cli_lock']['ok'] ?? false) === true, 'CLI lock probe must block the second handle');
    $assertTrue(($result['cli_lock']['second_exit_code'] ?? 0) === 2, 'CLI lock probe must map blocking to exit code two');
    $assertTrue(($result['cli_lock']['second_result'] ?? '') === 'rehearsal_lock_blocked', 'CLI lock result must match evidence contract');
    $assertTrue(($result['worker_lease']['ok'] ?? false) === true, 'Worker lease probe must pass');
    $assertTrue(($result['worker_lease']['first_claimed'] ?? false) === true, 'First worker claim must succeed');
    $assertTrue(($result['worker_lease']['second_action'] ?? '') === 'projection_busy', 'Second worker must observe an active lease');
    $assertTrue(($result['worker_lease']['state_revision'] ?? 0) === ($result['worker_lease']['second_state_revision'] ?? -1), 'Both lease results must use one revision');
    $assertTrue(($result['worker_lease']['lease_seconds'] ?? 0) === 120, 'Lease duration must remain explicit');
    $assertTrue($store->rows === [], 'Worker lease probe row must be removed in finally');
    $assertTrue(!file_exists($lockPath), 'CLI probe lock file must be removed in finally');

    $assertThrows(
        static fn() => new RuntimePrimaryStagingConcurrencyProbe($first, $second, $lockPath, 10),
        'between 30 and 900 seconds'
    );
} finally {
    @unlink($lockPath);
    @rmdir($directory);
}

fwrite(STDOUT, "RuntimePrimaryStagingConcurrencyProbeTest passed: {$assertions} assertions.\n");
