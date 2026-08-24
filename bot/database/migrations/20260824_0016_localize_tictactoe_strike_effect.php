<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260824_0016_localize_tictactoe_strike_effect';
    }

    public function description(): string
    {
        return 'Localize the already-seeded Tic Tac Toe strike effect title without changing its stable item identity.';
    }

    public function transactional(): bool
    {
        return true;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        $rows = $database->fetchAll(
            "SELECT metadata_json FROM mgw_product_catalog WHERE item_id = 'game-ttt-effect-strike'"
        );
        if ($rows === []) return;

        $metadata = json_decode((string)($rows[0]['metadata_json'] ?? '{}'), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($metadata)) {
            throw new RuntimeException('Tic Tac Toe strike effect metadata is invalid.');
        }
        $metadata['display_name'] = 'Перечёркивание';

        $database->execute(
            "UPDATE mgw_product_catalog
             SET metadata_json = :metadata_json, updated_at_utc = :updated_at
             WHERE item_id = 'game-ttt-effect-strike'",
            [
                'metadata_json' => json_encode(
                    $metadata,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
                'updated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
            ]
        );
    }
};
