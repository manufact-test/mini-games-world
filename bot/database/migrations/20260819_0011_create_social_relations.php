<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260819_0011_create_social_relations';
    }

    public function description(): string
    {
        return 'Create the canonical pair-owned MGW friends and blocks relation state.';
    }

    public function transactional(): bool
    {
        return false;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        if ($database->driver() === 'sqlite') {
            $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_social_relations (
    user_low_mgw_id TEXT NOT NULL,
    user_high_mgw_id TEXT NOT NULL,
    friend_status TEXT NOT NULL DEFAULT 'none',
    requested_by_mgw_id TEXT NULL,
    blocked_by_low INTEGER NOT NULL DEFAULT 0,
    blocked_by_high INTEGER NOT NULL DEFAULT 0,
    friend_requested_at_utc TEXT NULL,
    friend_resolved_at_utc TEXT NULL,
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL,
    PRIMARY KEY (user_low_mgw_id, user_high_mgw_id),
    FOREIGN KEY (user_low_mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY (user_high_mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY (requested_by_mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);
            $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_social_low_status ON mgw_social_relations (user_low_mgw_id, friend_status, updated_at_utc)');
            $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_social_high_status ON mgw_social_relations (user_high_mgw_id, friend_status, updated_at_utc)');
            $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_social_requester_status ON mgw_social_relations (requested_by_mgw_id, friend_status, updated_at_utc)');
            return;
        }

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_social_relations (
    user_low_mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_high_mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    friend_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'none',
    requested_by_mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    blocked_by_low TINYINT(1) NOT NULL DEFAULT 0,
    blocked_by_high TINYINT(1) NOT NULL DEFAULT 0,
    friend_requested_at_utc DATETIME(6) NULL,
    friend_resolved_at_utc DATETIME(6) NULL,
    created_at_utc DATETIME(6) NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    PRIMARY KEY (user_low_mgw_id, user_high_mgw_id),
    INDEX idx_mgw_social_low_status (user_low_mgw_id, friend_status, updated_at_utc),
    INDEX idx_mgw_social_high_status (user_high_mgw_id, friend_status, updated_at_utc),
    INDEX idx_mgw_social_requester_status (requested_by_mgw_id, friend_status, updated_at_utc),
    CONSTRAINT fk_mgw_social_low_user FOREIGN KEY (user_low_mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_social_high_user FOREIGN KEY (user_high_mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_social_requester_user FOREIGN KEY (requested_by_mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }
};
