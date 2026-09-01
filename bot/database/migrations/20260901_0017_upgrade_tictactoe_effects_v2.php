<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260901_0017_upgrade_tictactoe_effects_v2';
    }

    public function description(): string
    {
        return 'Upgrade Tic Tac Toe effect metadata to effects v2 while preserving stable purchased item identities.';
    }

    public function transactional(): bool
    {
        return true;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        $this->updateEffect($database, 'game-ttt-effect-sign', [
            'display_name' => 'Импульс знака',
            'variant' => 'sign',
            'event' => 'sign',
        ]);

        $this->updateEffect($database, 'game-ttt-effect-winning-line', [
            'display_name' => 'Победный импульс',
            'variant' => 'winning-line',
            'event' => 'winning_line',
        ]);

        // Keep the historical stable item id/equip slot so existing purchases remain owned and equipped.
        $this->updateEffect($database, 'game-ttt-effect-strike', [
            'display_name' => 'Импульс хода',
            'variant' => 'move-pulse',
            'event' => 'move_pulse',
        ]);
    }

    private function updateEffect(DatabaseConnectionInterface $database, string $itemId, array $changes): void
    {
        $rows = $database->fetchAll(
            'SELECT metadata_json FROM mgw_product_catalog WHERE item_id = :item_id',
            ['item_id' => $itemId]
        );
        if ($rows === []) return;

        $metadata = json_decode((string)($rows[0]['metadata_json'] ?? '{}'), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($metadata)) {
            throw new RuntimeException('Tic Tac Toe effect metadata is invalid for ' . $itemId);
        }

        foreach ($changes as $key => $value) {
            $metadata[$key] = $value;
        }

        $database->execute(
            'UPDATE mgw_product_catalog
             SET metadata_json = :metadata_json, updated_at_utc = :updated_at
             WHERE item_id = :item_id',
            [
                'metadata_json' => json_encode(
                    $metadata,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
                'updated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
                'item_id' => $itemId,
            ]
        );
    }
};
