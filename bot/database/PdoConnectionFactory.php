<?php
declare(strict_types=1);

final class PdoConnectionFactory
{
    /**
     * PHP userland statics are request-scoped under the web runtime. Keeping one
     * connection object per exact private identity prevents each runtime bridge
     * from opening another concurrent PDO connection during the same API call.
     *
     * @var array<string,PdoDatabaseConnection>
     */
    private static array $requestConnections = [];

    public static function create(DatabaseConfig $config): PdoDatabaseConnection
    {
        if (!$config->enabled()) {
            throw new RuntimeException('Database is not enabled.');
        }
        if (!extension_loaded('pdo') || !extension_loaded('pdo_mysql')) {
            throw new RuntimeException('PDO MySQL extension is not available.');
        }

        $cacheKey = self::privateCacheKey($config);
        if (isset(self::$requestConnections[$cacheKey])) {
            return self::$requestConnections[$cacheKey];
        }

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

        $connection = new PdoDatabaseConnection($pdo);
        self::$requestConnections[$cacheKey] = $connection;
        return $connection;
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
