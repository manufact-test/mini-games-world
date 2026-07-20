<?php
declare(strict_types=1);

interface StorageAdapterInterface
{
    public function driver(): string;
    public function transaction(callable $callback): mixed;
    public function readOnly(callable $callback): mixed;
}
final class RuntimePrimaryJsonEvidenceTestStorage implements StorageAdapterInterface
{
    public int $readOnlyCalls = 0;
    public int $transactionCalls = 0;
    public function __construct(private array $snapshot, private string $driverName = 'json') {}
    public function driver(): string { return $this->driverName; }
    public function transaction(callable $callback): mixed
    {
        $this->transactionCalls++;
        return $callback($this->snapshot);
    }
    public function readOnly(callable $callback): mixed
    {
        $this->readOnlyCalls++;
        return $callback($this->snapshot);
    }
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryJsonEvidence.php';

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

$snapshotA = [
    'users' => ['2' => ['id' => '2'], '1' => ['id' => '1']],
    'games' => ['g1' => ['id' => 'g1']],
    'transactions' => [],
    'system' => ['b' => 2, 'a' => 1],
];
$snapshotB = [
    'system' => ['a' => 1, 'b' => 2],
    'transactions' => [],
    'games' => ['g1' => ['id' => 'g1']],
    'users' => ['1' => ['id' => '1'], '2' => ['id' => '2']],
];
$storageA = new RuntimePrimaryJsonEvidenceTestStorage($snapshotA);
$storageB = new RuntimePrimaryJsonEvidenceTestStorage($snapshotB);
$evidenceA = RuntimePrimaryJsonEvidence::capture($storageA);
$evidenceB = RuntimePrimaryJsonEvidence::capture($storageB);
$assertTrue(hash_equals((string)$evidenceA['sha256'], (string)$evidenceB['sha256']), 'Canonical snapshot SHA must ignore associative key order');
$assertTrue(hash_equals((string)$evidenceA['inventory_fingerprint'], (string)$evidenceB['inventory_fingerprint']), 'Inventory fingerprint must ignore source key order');
$assertTrue(($evidenceA['inventory']['users_count'] ?? 0) === 2, 'Inventory must count users');
$assertTrue(($evidenceA['inventory']['games_count'] ?? 0) === 1, 'Inventory must count games');
$assertTrue(($evidenceA['inventory']['payments_count'] ?? -1) === 0, 'Missing inventory sections must count as zero');
$assertTrue($storageA->readOnlyCalls === 1 && $storageA->transactionCalls === 0, 'Evidence capture must use only read-only storage');
$assertTrue(($evidenceA['production_changed'] ?? true) === false, 'JSON evidence must report no production change');
$assertTrue(($evidenceA['sensitive_identifiers_exposed'] ?? true) === false, 'JSON evidence must not expose identifiers');
$assertTrue(!array_key_exists('snapshot', $evidenceA) && !array_key_exists('users', $evidenceA), 'JSON evidence must not return snapshot payloads');

$databaseStorage = new RuntimePrimaryJsonEvidenceTestStorage([], 'database');
$assertThrows(
    static fn() => RuntimePrimaryJsonEvidence::capture($databaseStorage),
    'must be the json rollback driver'
);

fwrite(STDOUT, "RuntimePrimaryJsonEvidenceTest passed: {$assertions} assertions.\n");
