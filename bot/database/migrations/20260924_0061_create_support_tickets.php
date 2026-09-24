<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260924_0061_create_support_tickets';
    }

    public function description(): string
    {
        return 'Create MVP-22.1 support tickets, isolated threads, attachments and durable history.';
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
CREATE TABLE IF NOT EXISTS mgw_support_tickets (
    ticket_id VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    ticket_number VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    requester_mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    platform_code VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    category_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status_code VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    priority_code VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    owner_ref VARCHAR(191) COLLATE utf8mb4_bin NULL,
    subject VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    related_game_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
    related_payment_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
    related_tournament_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    related_operation_id VARCHAR(191) COLLATE utf8mb4_bin NULL,
    critical_alerted_at_utc DATETIME(6) NULL,
    normal_summary_alerted_at_utc DATETIME(6) NULL,
    created_at_utc DATETIME(6) NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    last_message_at_utc DATETIME(6) NOT NULL,
    resolved_at_utc DATETIME(6) NULL,
    closed_at_utc DATETIME(6) NULL,
    UNIQUE KEY uq_mgw_support_ticket_number (ticket_number),
    INDEX idx_mgw_support_ticket_queue (status_code, priority_code, updated_at_utc),
    INDEX idx_mgw_support_ticket_requester (requester_mgw_id, updated_at_utc),
    INDEX idx_mgw_support_ticket_owner (owner_ref, status_code, updated_at_utc),
    CONSTRAINT fk_mgw_support_ticket_requester FOREIGN KEY (requester_mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_support_ticket_messages (
    message_id VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    ticket_id VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_ref VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    body TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    created_at_utc DATETIME(6) NOT NULL,
    INDEX idx_mgw_support_message_ticket (ticket_id, created_at_utc, message_id),
    CONSTRAINT fk_mgw_support_message_ticket FOREIGN KEY (ticket_id)
        REFERENCES mgw_support_tickets (ticket_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_support_ticket_attachments (
    attachment_id VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    ticket_id VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    message_id VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    file_name VARCHAR(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    mime_type VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    content_base64 MEDIUMTEXT CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at_utc DATETIME(6) NOT NULL,
    INDEX idx_mgw_support_attachment_ticket (ticket_id, created_at_utc),
    INDEX idx_mgw_support_attachment_message (message_id),
    CONSTRAINT fk_mgw_support_attachment_ticket FOREIGN KEY (ticket_id)
        REFERENCES mgw_support_tickets (ticket_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_support_attachment_message FOREIGN KEY (message_id)
        REFERENCES mgw_support_ticket_messages (message_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_support_ticket_events (
    event_id VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    ticket_id VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_ref VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    previous_value VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    next_value VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    details_json JSON NULL,
    created_at_utc DATETIME(6) NOT NULL,
    INDEX idx_mgw_support_event_ticket (ticket_id, created_at_utc, event_id),
    CONSTRAINT fk_mgw_support_event_ticket FOREIGN KEY (ticket_id)
        REFERENCES mgw_support_tickets (ticket_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    private function upSqlite(DatabaseConnectionInterface $database): void
    {
        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_support_tickets (
    ticket_id TEXT NOT NULL PRIMARY KEY,
    ticket_number TEXT NOT NULL UNIQUE,
    requester_mgw_id TEXT NOT NULL,
    platform_code TEXT NOT NULL,
    category_code TEXT NOT NULL,
    status_code TEXT NOT NULL,
    priority_code TEXT NOT NULL,
    owner_ref TEXT NULL,
    subject TEXT NOT NULL,
    related_game_id TEXT NULL,
    related_payment_id TEXT NULL,
    related_tournament_id TEXT NULL,
    related_operation_id TEXT NULL,
    critical_alerted_at_utc TEXT NULL,
    normal_summary_alerted_at_utc TEXT NULL,
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL,
    last_message_at_utc TEXT NOT NULL,
    resolved_at_utc TEXT NULL,
    closed_at_utc TEXT NULL,
    FOREIGN KEY (requester_mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_support_ticket_queue ON mgw_support_tickets (status_code, priority_code, updated_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_support_ticket_requester ON mgw_support_tickets (requester_mgw_id, updated_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_support_ticket_owner ON mgw_support_tickets (owner_ref, status_code, updated_at_utc)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_support_ticket_messages (
    message_id TEXT NOT NULL PRIMARY KEY,
    ticket_id TEXT NOT NULL,
    actor_type TEXT NOT NULL,
    actor_ref TEXT NOT NULL,
    body TEXT NOT NULL,
    created_at_utc TEXT NOT NULL,
    FOREIGN KEY (ticket_id) REFERENCES mgw_support_tickets (ticket_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_support_message_ticket ON mgw_support_ticket_messages (ticket_id, created_at_utc, message_id)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_support_ticket_attachments (
    attachment_id TEXT NOT NULL PRIMARY KEY,
    ticket_id TEXT NOT NULL,
    message_id TEXT NOT NULL,
    file_name TEXT NOT NULL,
    mime_type TEXT NOT NULL,
    size_bytes INTEGER NOT NULL,
    content_base64 TEXT NOT NULL,
    created_at_utc TEXT NOT NULL,
    FOREIGN KEY (ticket_id) REFERENCES mgw_support_tickets (ticket_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY (message_id) REFERENCES mgw_support_ticket_messages (message_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_support_attachment_ticket ON mgw_support_ticket_attachments (ticket_id, created_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_support_attachment_message ON mgw_support_ticket_attachments (message_id)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_support_ticket_events (
    event_id TEXT NOT NULL PRIMARY KEY,
    ticket_id TEXT NOT NULL,
    event_type TEXT NOT NULL,
    actor_ref TEXT NOT NULL,
    previous_value TEXT NULL,
    next_value TEXT NULL,
    details_json TEXT NULL,
    created_at_utc TEXT NOT NULL,
    FOREIGN KEY (ticket_id) REFERENCES mgw_support_tickets (ticket_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_support_event_ticket ON mgw_support_ticket_events (ticket_id, created_at_utc, event_id)');
    }
};
