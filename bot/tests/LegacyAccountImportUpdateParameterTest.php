<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/storage/contracts/StorageTransactionInterface.php';
require $root . '/storage/contracts/StorageAdapterInterface.php';
require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/database/PdoDatabaseConnection.php';
require $root . '/accounts/LegacyAccountImportService.php';

final class LegacyAccountImportUpdateSpyDatabase implements DatabaseConnectionInterface
{
    public string $sql = '';
    public array $parameters = [];

    public function driver(): string
    {
        return 'mysql';
    }

    public function execute(string $sql, array $parameters = []): int
    {
        $this->sql = $sql;
        $this->parameters = $parameters;
        return 1;
    }

    public function fetchAll(string $sql, array $parameters = []): array
    {
        return [];
    }

    public function fetchValue(string $sql, array $parameters = []): mixed
    {
        return null;
    }

    public function transaction(callable $callback): mixed
    {
        return $callback($this);
    }
}

final class LegacyAccountImportUpdateEmptyStorage implements StorageAdapterInterface
{
    public function transaction(callable $callback): mixed
    {
        $data = [];
        return $callback($data);
    }

    public function readOnly(callable $callback): mixed
    {
        return $callback([]);
    }

    public function driver(): string
    {
        return 'json';
    }
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$database = new LegacyAccountImportUpdateSpyDatabase();
$service = new LegacyAccountImportService(
    new LegacyAccountImportUpdateEmptyStorage(),
    $database
);
$method = new ReflectionMethod(LegacyAccountImportService::class, 'updateUser');
$method->invoke(
    $service,
    $database,
    'mgw_0123456789abcdef0123456789abcdef',
    [
        'display_name' => 'Player',
        'username' => 'player',
        'avatar_provider' => null,
        'avatar_external_ref' => null,
        'created_at_utc' => '2026-07-17 10:00:00.000000',
        'updated_at_utc' => '2026-07-17 11:00:00.000000',
        'last_seen_at_utc' => '2026-07-17 11:00:00.000000',
    ]
);

preg_match_all('/:[A-Za-z_][A-Za-z0-9_]*/', $database->sql, $matches);
$sqlParameters = array_values(array_unique(array_map(
    static fn(string $value): string => ltrim($value, ':'),
    $matches[0]
)));
sort($sqlParameters, SORT_STRING);
$boundParameters = array_keys($database->parameters);
sort($boundParameters, SORT_STRING);

$assertTrue(
    $sqlParameters === $boundParameters,
    'Native PDO update query placeholders must exactly match the bound parameter set.'
);
$assertTrue(
    str_contains($database->sql, 'created_at_utc = :created_at_utc'),
    'Existing account update must persist the creation timestamp used by verification.'
);
$assertTrue(
    ($database->parameters['created_at_utc'] ?? null) === '2026-07-17 10:00:00.000000',
    'Existing account update must bind the source creation timestamp.'
);

fwrite(
    STDOUT,
    "LegacyAccountImportUpdateParameterTest passed: {$assertions} assertions.\n"
);
