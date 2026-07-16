<?php
declare(strict_types=1);

interface DatabaseMigrationInterface
{
    public function version(): string;

    public function description(): string;

    public function up(DatabaseConnectionInterface $database): void;
}
