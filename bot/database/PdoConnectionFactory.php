<?php
declare(strict_types=1);

final class PdoConnectionFactory
{
    public static function create(DatabaseConfig $config): PdoDatabaseConnection
    {
        if (!$config->enabled()) {
            throw new RuntimeException('Database is not enabled.');
        }
        if (!extension_loaded('pdo') || !extension_loaded('pdo_mysql')) {
            throw new RuntimeException('PDO MySQL extension is not available.');
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
                    PDO::ATTR_TIMEOUT => 5,
                ]
            );
        } catch (PDOException $error) {
            throw new RuntimeException('Database connection failed. Check the private configuration and server availability.', 0, $error);
        }

        return new PdoDatabaseConnection($pdo);
    }
}
