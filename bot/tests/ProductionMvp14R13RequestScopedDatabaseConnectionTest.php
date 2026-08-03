<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$factory = file_get_contents($root . '/bot/database/PdoConnectionFactory.php');
$config = file_get_contents($root . '/bot/database/DatabaseConfig.php');
$connection = file_get_contents($root . '/bot/database/PdoDatabaseConnection.php');
if (!is_string($factory) || !is_string($config) || !is_string($connection)) {
    throw new RuntimeException('Missing request-scoped database connection source.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($factory, 'private static array $requestConnections = [];'),
    'The PDO factory must keep one private request-scoped connection registry.');
$assert(str_contains($factory, '$cacheKey = self::privateCacheKey($config);')
    && str_contains($factory, 'if (isset(self::$requestConnections[$cacheKey]))')
    && str_contains($factory, 'return self::$requestConnections[$cacheKey];'),
    'Repeated modules using the exact same private DB configuration must reuse one connection object.');

$assert(str_contains($factory, '$config->identityFingerprint()')
    && str_contains($factory, '$config->driver()')
    && str_contains($factory, '$config->user()')
    && str_contains($factory, '$config->password()')
    && str_contains($factory, "hash('sha256', implode(\"\\0\", ["),
    'The private cache key must hash DB identity, driver, username and password together.');
$assert(str_contains($config, "return hash('sha256', json_encode([")
    && str_contains($config, "'host' => strtolower(\$this->host)")
    && str_contains($config, "'port' => (string)\$this->port")
    && str_contains($config, "'name' => \$this->name"),
    'Database identity fingerprint must continue to separate host, port and database name.');

$assert(str_contains($factory, 'PDO::ATTR_PERSISTENT => false'),
    'Request reuse must never become a cross-request persistent PDO connection.');
$assert(str_contains($factory, "throw new RuntimeException('Database identity is not configured.')"),
    'An enabled connection without an identity must fail closed before caching.');

$pdoPosition = strpos($factory, '$pdo = new PDO(');
$wrapperPosition = strpos($factory, '$connection = new PdoDatabaseConnection($pdo);');
$cachePosition = strpos($factory, 'self::$requestConnections[$cacheKey] = $connection;');
$returnPosition = strpos($factory, 'return $connection;');
$assert($pdoPosition !== false
    && $wrapperPosition !== false
    && $cachePosition !== false
    && $returnPosition !== false
    && $pdoPosition < $wrapperPosition
    && $wrapperPosition < $cachePosition
    && $cachePosition < $returnPosition,
    'The registry must be populated only after PDO and its wrapper are constructed successfully.');

$assert(str_contains($factory, "throw new RuntimeException('Database connection failed. Check the private configuration and server availability.', 0, \$error)"),
    'Connection failures must keep the existing sanitized public error.');
$assert(!str_contains($factory, 'error_log(')
    && !str_contains($factory, 'var_dump(')
    && !str_contains($factory, 'print_r(')
    && !str_contains($factory, 'safeSummary()'),
    'The private cache key and credentials must never be logged or returned.');
$assert(!str_contains($factory, 'lemonchiffon-gerbil-545102.hostingersite.com')
    && !str_contains($factory, 'seashell-okapi-889488.hostingersite.com')
    && !str_contains($factory, 'mini-games-world.com'),
    'Connection reuse must remain configuration-driven and contain no staging or production host literal.');
$assert(!str_contains($factory, 'public static function reset')
    && !str_contains($factory, 'public static function clear'),
    'Runtime code must not expose a public method that can swap or clear shared connections mid-request.');

$assert(str_contains($connection, 'private int $transactionDepth = 0;')
    && str_contains($connection, "SAVEPOINT ' . \$savepoint")
    && str_contains($connection, "ROLLBACK TO SAVEPOINT ' . \$savepoint"),
    'The shared connection wrapper must retain nested-transaction savepoint safety.');

fwrite(STDOUT, "ProductionMvp14R13RequestScopedDatabaseConnectionTest: {$assertions} assertions passed\n");
