<?php
declare(strict_types=1);

/*
 * Copy this entire file to _private_mgw/database.php.
 * Keep the real database host, name, user and password outside GitHub.
 */
return [
    /* JSON remains the active product storage until the later cutover MVP. */
    'database' => [
        'enabled' => false,
        'driver' => 'mysql', // MySQL and MariaDB both use PDO mysql.
        'host' => 'PRIVATE_DATABASE_HOST',
        'port' => 3306,
        'name' => 'PRIVATE_DATABASE_NAME',
        'user' => 'PRIVATE_DATABASE_USER',
        'password' => 'PRIVATE_DATABASE_PASSWORD',
        'charset' => 'utf8mb4',
    ],

    /* Keep false in production during MVP-14.2. */
    'database_migrations_allow_production' => false,

    /* Test environment only: paste the SHA-256 identity fingerprint produced
     * from the reserved production database identity. */
    'environment_guard' => [
        'production_database_sha256' => '',
    ],
];
