<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260816_0009_add_canonical_profile_identity';
    }

    public function description(): string
    {
        return 'Add MGW-owned nickname, avatar slot and saved locale to the canonical account.';
    }

    public function transactional(): bool
    {
        return false;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        if ($database->driver() === 'sqlite') {
            $database->execute('ALTER TABLE mgw_users ADD COLUMN nickname TEXT NULL');
            $database->execute("ALTER TABLE mgw_users ADD COLUMN equipped_avatar_item_id TEXT NULL DEFAULT 'starter-default-01'");
            $database->execute('ALTER TABLE mgw_users ADD COLUMN preferred_locale TEXT NULL');
            $database->execute("UPDATE mgw_users SET equipped_avatar_item_id = 'starter-default-01' WHERE equipped_avatar_item_id IS NULL OR equipped_avatar_item_id = ''");
            $database->execute('CREATE UNIQUE INDEX IF NOT EXISTS uq_mgw_users_nickname ON mgw_users (nickname)');
            return;
        }

        $database->execute(<<<'SQL'
ALTER TABLE mgw_users
    ADD COLUMN nickname VARCHAR(80) COLLATE utf8mb4_bin NULL AFTER status,
    ADD COLUMN equipped_avatar_item_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT 'starter-default-01' AFTER avatar_height,
    ADD COLUMN preferred_locale VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER equipped_avatar_item_id
SQL);
        $database->execute("UPDATE mgw_users SET equipped_avatar_item_id = 'starter-default-01' WHERE equipped_avatar_item_id IS NULL OR equipped_avatar_item_id = ''");
        $database->execute('CREATE UNIQUE INDEX uq_mgw_users_nickname ON mgw_users (nickname)');
    }
};
