<?php
declare(strict_types=1);

interface DatabaseMigrationInterface
{
    public function version(): string;

    public function description(): string;

    /**
     * MySQL/MariaDB DDL performs implicit commits, so schema migrations must
     * explicitly opt out of a wrapping transaction. Pure DML migrations may
     * opt in and receive atomic commit/rollback behavior.
     */
    public function transactional(): bool;

    public function up(DatabaseConnectionInterface $database): void;
}
