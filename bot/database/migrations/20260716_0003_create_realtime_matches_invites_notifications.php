<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260716_0003_create_realtime_matches_invites_notifications';
    }

    public function description(): string
    {
        return 'Create realtime matches, players, snapshots, queue, invites, events and notifications.';
    }

    public function transactional(): bool
    {
        return false;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        if ($database->driver() === 'sqlite') {
            $this->upSqlite($database);
            return;
        }

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_matches (
    match_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    game_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    room VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    board_size SMALLINT UNSIGNED NOT NULL,
    bet BIGINT UNSIGNED NOT NULL DEFAULT 0,
    match_source VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    invite_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
    source_match_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
    turn_player_ref VARCHAR(255) COLLATE utf8mb4_bin NULL,
    winner_player_ref VARCHAR(255) COLLATE utf8mb4_bin NULL,
    finish_reason VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    state_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    public_state_json JSON NULL,
    server_state_json JSON NULL,
    created_at_utc DATETIME(6) NOT NULL,
    started_at_utc DATETIME(6) NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    finished_at_utc DATETIME(6) NULL,
    INDEX idx_mgw_matches_status_updated (status, updated_at_utc),
    INDEX idx_mgw_matches_game_status (game_type, status),
    INDEX idx_mgw_matches_invite (invite_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_match_players (
    match_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    seat SMALLINT UNSIGNED NOT NULL,
    player_ref VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    legacy_user_id VARCHAR(191) COLLATE utf8mb4_bin NULL,
    player_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'human',
    symbol VARCHAR(32) NULL,
    display_name VARCHAR(80) NULL,
    result VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    joined_at_utc DATETIME(6) NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    PRIMARY KEY (match_id, seat),
    UNIQUE KEY uq_mgw_match_player_ref (match_id, player_ref),
    INDEX idx_mgw_match_players_mgw (mgw_id, updated_at_utc),
    INDEX idx_mgw_match_players_legacy (legacy_user_id, updated_at_utc),
    CONSTRAINT fk_mgw_match_players_match FOREIGN KEY (match_id)
        REFERENCES mgw_matches (match_id) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_match_players_user FOREIGN KEY (mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_match_snapshots (
    snapshot_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    match_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state_version BIGINT UNSIGNED NOT NULL,
    public_state_json JSON NULL,
    server_state_json JSON NULL,
    created_at_utc DATETIME(6) NOT NULL,
    UNIQUE KEY uq_mgw_match_snapshot_version (match_id, state_version),
    CONSTRAINT fk_mgw_match_snapshots_match FOREIGN KEY (match_id)
        REFERENCES mgw_matches (match_id) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_match_player_snapshots (
    match_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state_version BIGINT UNSIGNED NOT NULL,
    player_ref VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
    private_state_json JSON NULL,
    created_at_utc DATETIME(6) NOT NULL,
    PRIMARY KEY (match_id, state_version, player_ref),
    CONSTRAINT fk_mgw_match_player_snapshots_match FOREIGN KEY (match_id)
        REFERENCES mgw_matches (match_id) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_match_queue (
    queue_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    player_ref VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    legacy_user_id VARCHAR(191) COLLATE utf8mb4_bin NULL,
    game_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    room VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    bet BIGINT UNSIGNED NOT NULL DEFAULT 0,
    board_size SMALLINT UNSIGNED NOT NULL,
    status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'waiting',
    reserved_match_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at_utc DATETIME(6) NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    expires_at_utc DATETIME(6) NULL,
    UNIQUE KEY uq_mgw_match_queue_player (player_ref),
    INDEX idx_mgw_match_queue_lookup (status, game_type, room, bet, board_size, created_at_utc),
    INDEX idx_mgw_match_queue_expiry (expires_at_utc),
    CONSTRAINT fk_mgw_match_queue_user FOREIGN KEY (mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_invites (
    invite_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    token VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    inviter_ref VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
    inviter_mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    inviter_legacy_user_id VARCHAR(191) COLLATE utf8mb4_bin NULL,
    inviter_name VARCHAR(80) NOT NULL,
    invitee_ref VARCHAR(255) COLLATE utf8mb4_bin NULL,
    invitee_mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    invitee_legacy_user_id VARCHAR(191) COLLATE utf8mb4_bin NULL,
    invitee_name VARCHAR(80) NULL,
    game_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    game_title VARCHAR(120) NOT NULL,
    room VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    bet BIGINT UNSIGNED NOT NULL DEFAULT 0,
    board_size SMALLINT UNSIGNED NOT NULL,
    board_columns SMALLINT UNSIGNED NULL,
    board_rows SMALLINT UNSIGNED NULL,
    source_match_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
    match_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
    version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    created_at_utc DATETIME(6) NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    expires_at_utc DATETIME(6) NULL,
    shared_at_utc DATETIME(6) NULL,
    opened_at_utc DATETIME(6) NULL,
    accepted_at_utc DATETIME(6) NULL,
    ready_deadline_at_utc DATETIME(6) NULL,
    started_at_utc DATETIME(6) NULL,
    declined_at_utc DATETIME(6) NULL,
    cancelled_at_utc DATETIME(6) NULL,
    cancelled_by_ref VARCHAR(255) COLLATE utf8mb4_bin NULL,
    UNIQUE KEY uq_mgw_invites_token (token),
    INDEX idx_mgw_invites_inviter_status (inviter_ref, status, updated_at_utc),
    INDEX idx_mgw_invites_invitee_status (invitee_ref, status, updated_at_utc),
    INDEX idx_mgw_invites_expiry (status, expires_at_utc),
    CONSTRAINT fk_mgw_invites_inviter_user FOREIGN KEY (inviter_mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_invites_invitee_user FOREIGN KEY (invitee_mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_invite_events (
    event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    invite_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_key VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    event_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_ref VARCHAR(255) COLLATE utf8mb4_bin NULL,
    payload_json JSON NULL,
    created_at_utc DATETIME(6) NOT NULL,
    UNIQUE KEY uq_mgw_invite_event_key (invite_id, event_key),
    INDEX idx_mgw_invite_events_created (invite_id, created_at_utc),
    CONSTRAINT fk_mgw_invite_events_invite FOREIGN KEY (invite_id)
        REFERENCES mgw_invites (invite_id) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_notifications (
    notification_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    event_key VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    recipient_ref VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    legacy_user_id VARCHAR(191) COLLATE utf8mb4_bin NULL,
    type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    title VARCHAR(160) NOT NULL,
    message TEXT NOT NULL,
    tone VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    invite_token VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
    payload_json JSON NULL,
    created_at_utc DATETIME(6) NOT NULL,
    read_at_utc DATETIME(6) NULL,
    hidden_at_utc DATETIME(6) NULL,
    UNIQUE KEY uq_mgw_notification_event (recipient_ref, event_key),
    INDEX idx_mgw_notifications_feed (recipient_ref, hidden_at_utc, created_at_utc),
    INDEX idx_mgw_notifications_unread (recipient_ref, read_at_utc, created_at_utc),
    CONSTRAINT fk_mgw_notifications_user FOREIGN KEY (mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    private function upSqlite(DatabaseConnectionInterface $database): void
    {
        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_matches (
    match_id TEXT NOT NULL PRIMARY KEY,
    game_type TEXT NOT NULL,
    room TEXT NOT NULL,
    status TEXT NOT NULL,
    board_size INTEGER NOT NULL,
    bet INTEGER NOT NULL DEFAULT 0,
    match_source TEXT NULL,
    invite_id TEXT NULL,
    source_match_id TEXT NULL,
    turn_player_ref TEXT NULL,
    winner_player_ref TEXT NULL,
    finish_reason TEXT NULL,
    state_version INTEGER NOT NULL DEFAULT 0,
    public_state_json TEXT NULL,
    server_state_json TEXT NULL,
    created_at_utc TEXT NOT NULL,
    started_at_utc TEXT NULL,
    updated_at_utc TEXT NOT NULL,
    finished_at_utc TEXT NULL
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_matches_status_updated ON mgw_matches (status, updated_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_matches_game_status ON mgw_matches (game_type, status)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_matches_invite ON mgw_matches (invite_id)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_match_players (
    match_id TEXT NOT NULL,
    seat INTEGER NOT NULL,
    player_ref TEXT NOT NULL,
    mgw_id TEXT NULL,
    legacy_user_id TEXT NULL,
    player_type TEXT NOT NULL DEFAULT 'human',
    symbol TEXT NULL,
    display_name TEXT NULL,
    result TEXT NULL,
    joined_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL,
    PRIMARY KEY (match_id, seat),
    UNIQUE (match_id, player_ref),
    FOREIGN KEY (match_id) REFERENCES mgw_matches (match_id) ON DELETE CASCADE ON UPDATE RESTRICT,
    FOREIGN KEY (mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE SET NULL ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_match_players_mgw ON mgw_match_players (mgw_id, updated_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_match_players_legacy ON mgw_match_players (legacy_user_id, updated_at_utc)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_match_snapshots (
    snapshot_id INTEGER PRIMARY KEY AUTOINCREMENT,
    match_id TEXT NOT NULL,
    state_version INTEGER NOT NULL,
    public_state_json TEXT NULL,
    server_state_json TEXT NULL,
    created_at_utc TEXT NOT NULL,
    UNIQUE (match_id, state_version),
    FOREIGN KEY (match_id) REFERENCES mgw_matches (match_id) ON DELETE CASCADE ON UPDATE RESTRICT
)
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_match_player_snapshots (
    match_id TEXT NOT NULL,
    state_version INTEGER NOT NULL,
    player_ref TEXT NOT NULL,
    private_state_json TEXT NULL,
    created_at_utc TEXT NOT NULL,
    PRIMARY KEY (match_id, state_version, player_ref),
    FOREIGN KEY (match_id) REFERENCES mgw_matches (match_id) ON DELETE CASCADE ON UPDATE RESTRICT
)
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_match_queue (
    queue_id TEXT NOT NULL PRIMARY KEY,
    player_ref TEXT NOT NULL UNIQUE,
    mgw_id TEXT NULL,
    legacy_user_id TEXT NULL,
    game_type TEXT NOT NULL,
    room TEXT NOT NULL,
    bet INTEGER NOT NULL DEFAULT 0,
    board_size INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'waiting',
    reserved_match_id TEXT NULL,
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL,
    expires_at_utc TEXT NULL,
    FOREIGN KEY (mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE SET NULL ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_match_queue_lookup ON mgw_match_queue (status, game_type, room, bet, board_size, created_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_match_queue_expiry ON mgw_match_queue (expires_at_utc)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_invites (
    invite_id TEXT NOT NULL PRIMARY KEY,
    token TEXT NOT NULL UNIQUE,
    status TEXT NOT NULL,
    source TEXT NOT NULL,
    inviter_ref TEXT NOT NULL,
    inviter_mgw_id TEXT NULL,
    inviter_legacy_user_id TEXT NULL,
    inviter_name TEXT NOT NULL,
    invitee_ref TEXT NULL,
    invitee_mgw_id TEXT NULL,
    invitee_legacy_user_id TEXT NULL,
    invitee_name TEXT NULL,
    game_type TEXT NOT NULL,
    game_title TEXT NOT NULL,
    room TEXT NOT NULL,
    bet INTEGER NOT NULL DEFAULT 0,
    board_size INTEGER NOT NULL,
    board_columns INTEGER NULL,
    board_rows INTEGER NULL,
    source_match_id TEXT NULL,
    match_id TEXT NULL,
    version INTEGER NOT NULL DEFAULT 1,
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL,
    expires_at_utc TEXT NULL,
    shared_at_utc TEXT NULL,
    opened_at_utc TEXT NULL,
    accepted_at_utc TEXT NULL,
    ready_deadline_at_utc TEXT NULL,
    started_at_utc TEXT NULL,
    declined_at_utc TEXT NULL,
    cancelled_at_utc TEXT NULL,
    cancelled_by_ref TEXT NULL,
    FOREIGN KEY (inviter_mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE SET NULL ON UPDATE RESTRICT,
    FOREIGN KEY (invitee_mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE SET NULL ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_invites_inviter_status ON mgw_invites (inviter_ref, status, updated_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_invites_invitee_status ON mgw_invites (invitee_ref, status, updated_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_invites_expiry ON mgw_invites (status, expires_at_utc)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_invite_events (
    event_id INTEGER PRIMARY KEY AUTOINCREMENT,
    invite_id TEXT NOT NULL,
    event_key TEXT NOT NULL,
    event_type TEXT NOT NULL,
    actor_ref TEXT NULL,
    payload_json TEXT NULL,
    created_at_utc TEXT NOT NULL,
    UNIQUE (invite_id, event_key),
    FOREIGN KEY (invite_id) REFERENCES mgw_invites (invite_id) ON DELETE CASCADE ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_invite_events_created ON mgw_invite_events (invite_id, created_at_utc)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_notifications (
    notification_id TEXT NOT NULL PRIMARY KEY,
    event_key TEXT NOT NULL,
    recipient_ref TEXT NOT NULL,
    mgw_id TEXT NULL,
    legacy_user_id TEXT NULL,
    type TEXT NOT NULL,
    title TEXT NOT NULL,
    message TEXT NOT NULL,
    tone TEXT NULL,
    invite_token TEXT NULL,
    payload_json TEXT NULL,
    created_at_utc TEXT NOT NULL,
    read_at_utc TEXT NULL,
    hidden_at_utc TEXT NULL,
    UNIQUE (recipient_ref, event_key),
    FOREIGN KEY (mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE SET NULL ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_notifications_feed ON mgw_notifications (recipient_ref, hidden_at_utc, created_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_notifications_unread ON mgw_notifications (recipient_ref, read_at_utc, created_at_utc)');
    }
};
