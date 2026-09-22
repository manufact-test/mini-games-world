<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260922_0056_add_tournament_registration_publication';
    }

    public function description(): string
    {
        return 'Add a server-owned publication boundary for official tournament registration visibility.';
    }

    public function transactional(): bool
    {
        return false;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        $date = $database->driver() === 'sqlite' ? 'TEXT' : 'DATETIME(6)';
        $database->execute("ALTER TABLE mgw_tournament_registrations ADD COLUMN published_at_utc {$date} NULL");

        // Everything created before this migration was already public product
        // state. Backfill it so the new barrier changes only future mutations.
        $database->execute(
            'UPDATE mgw_tournament_registrations
             SET published_at_utc = registered_at_utc
             WHERE registration_state = :state
               AND published_at_utc IS NULL',
            ['state'=>TournamentRegistrationService::REGISTRATION_REGISTERED]
        );

        if ($database->driver() === 'sqlite') {
            $database->execute(
                'CREATE INDEX IF NOT EXISTS idx_mgw_tournament_registration_publication
                 ON mgw_tournament_registrations (tournament_id, registration_state, published_at_utc)'
            );
            return;
        }

        $database->execute(
            'ALTER TABLE mgw_tournament_registrations
             ADD INDEX idx_mgw_tournament_registration_publication
             (tournament_id, registration_state, published_at_utc)'
        );
    }
};
