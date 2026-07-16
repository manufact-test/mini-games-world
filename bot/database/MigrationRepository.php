<?php
declare(strict_types=1);

final class MigrationRepository
{
    public function __construct(private DatabaseConnectionInterface $database) {}

    public function ensureTable(): void
    {
        $sql = $this->database->driver() === 'sqlite'
            ? <<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_schema_migrations (
    version TEXT NOT NULL PRIMARY KEY,
    checksum TEXT NOT NULL,
    description TEXT NOT NULL,
    applied_at_utc TEXT NOT NULL
)
SQL
            : <<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_schema_migrations (
    version VARCHAR(191) NOT NULL PRIMARY KEY,
    checksum CHAR(64) NOT NULL,
    description VARCHAR(255) NOT NULL,
    applied_at_utc DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

        $this->database->execute($sql);
    }

    public function applied(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT version, checksum, description, applied_at_utc FROM mgw_schema_migrations ORDER BY version ASC'
        );

        $applied = [];
        foreach ($rows as $row) {
            $version = (string)($row['version'] ?? '');
            if ($version === '') continue;
            $applied[$version] = [
                'checksum' => (string)($row['checksum'] ?? ''),
                'description' => (string)($row['description'] ?? ''),
                'applied_at_utc' => (string)($row['applied_at_utc'] ?? ''),
            ];
        }

        return $applied;
    }

    public function record(string $version, string $checksum, string $description): void
    {
        $this->database->execute(
            'INSERT INTO mgw_schema_migrations (version, checksum, description, applied_at_utc) VALUES (:version, :checksum, :description, :applied_at_utc)',
            [
                'version' => $version,
                'checksum' => $checksum,
                'description' => $description,
                'applied_at_utc' => gmdate('Y-m-d H:i:s.u'),
            ]
        );
    }
}
