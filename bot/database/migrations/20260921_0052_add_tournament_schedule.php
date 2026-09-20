<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260921_0052_add_tournament_schedule';
    }

    public function description(): string
    {
        return 'Add MVP-21.3 immutable official tournament start date/time.';
    }

    public function transactional(): bool
    {
        return false;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        if ($database->driver() === 'sqlite') {
            $database->execute('ALTER TABLE mgw_tournaments ADD COLUMN scheduled_start_at_utc TEXT NULL');
            $database->execute('ALTER TABLE mgw_tournaments ADD COLUMN scheduled_by_ref TEXT NULL');
            $database->execute('ALTER TABLE mgw_tournaments ADD COLUMN scheduled_at_utc TEXT NULL');
            $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_tournaments_schedule ON mgw_tournaments (tournament_state, scheduled_start_at_utc)');
            return;
        }

        $database->execute(<<<'SQL'
ALTER TABLE mgw_tournaments
    ADD COLUMN scheduled_start_at_utc DATETIME(6) NULL AFTER registration_closed_reason,
    ADD COLUMN scheduled_by_ref VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER scheduled_start_at_utc,
    ADD COLUMN scheduled_at_utc DATETIME(6) NULL AFTER scheduled_by_ref,
    ADD INDEX idx_mgw_tournaments_schedule (tournament_state, scheduled_start_at_utc)
SQL);
    }
};
