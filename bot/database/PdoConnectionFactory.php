<?php
declare(strict_types=1);

final class PdoConnectionFactory
{
    /**
     * PHP userland statics are request-scoped under web runtimes. Keeping one
     * connection object per exact private identity prevents each runtime bridge
     * from opening another concurrent PDO connection during the same API call.
     * CLI processes deliberately bypass this registry because forked workers
     * must never inherit a retained parent PDO socket.
     *
     * @var array<string,PdoDatabaseConnection>
     */
    private static array $requestConnections = [];

    public static function create(DatabaseConfig $config): PdoDatabaseConnection
    {
        self::assertAvailable($config);

        if (PHP_SAPI === 'cli') {
            return self::connect($config);
        }

        $cacheKey = self::privateCacheKey($config);
        if (isset(self::$requestConnections[$cacheKey])) {
            return self::$requestConnections[$cacheKey];
        }

        $connection = self::connect($config);
        self::$requestConnections[$cacheKey] = $connection;
        return $connection;
    }

    private static function assertAvailable(DatabaseConfig $config): void
    {
        if (!$config->enabled()) {
            throw new RuntimeException('Database is not enabled.');
        }
        if (!extension_loaded('pdo') || !extension_loaded('pdo_mysql')) {
            throw new RuntimeException('PDO MySQL extension is not available.');
        }
    }

    private static function connect(DatabaseConfig $config): PdoDatabaseConnection
    {
        try {
            $pdo = new PDO(
                $config->dsn(),
                $config->user(),
                $config->password(),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_PERSISTENT => false,
                    PDO::ATTR_TIMEOUT => 5,
                ]
            );
        } catch (PDOException $error) {
            throw new RuntimeException('Database connection failed. Check the private configuration and server availability.', 0, $error);
        }

        return new PdoDatabaseConnection($pdo);
    }

    private static function privateCacheKey(DatabaseConfig $config): string
    {
        $identity = $config->identityFingerprint();
        if ($identity === '') {
            throw new RuntimeException('Database identity is not configured.');
        }

        return hash('sha256', implode("\0", [
            $identity,
            $config->driver(),
            $config->user(),
            $config->password(),
        ]));
    }
}
