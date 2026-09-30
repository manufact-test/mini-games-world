<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20261001_0071_add_android_session_reauth';
    }

    public function description(): string
    {
        return 'Add short-lived Android sensitive-action reauthentication timestamp to canonical MGW sessions.';
    }

    public function transactional(): bool
    {
        return false;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        if ($database->driver() === 'sqlite') {
            $database->execute('ALTER TABLE mgw_sessions ADD COLUMN last_reauthenticated_at_utc TEXT NULL');
            return;
        }

        $database->execute(
            'ALTER TABLE mgw_sessions
             ADD COLUMN last_reauthenticated_at_utc DATETIME(6) NULL AFTER last_seen_at_utc'
        );
    }
};
