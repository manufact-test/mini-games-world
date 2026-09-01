<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    private const EFFECT_SLOT = 'game_tictactoe_effect';
    private const LEGACY_SLOTS = [
        'game_tictactoe_effect_sign',
        'game_tictactoe_effect_winning_line',
        'game_tictactoe_effect_strike_through',
        self::EFFECT_SLOT,
    ];
    private const EFFECTS = [
        'game-ttt-effect-sign' => [
            'display_name' => 'Импульс знака',
            'variant' => 'impact',
            'event' => 'move',
        ],
        'game-ttt-effect-winning-line' => [
            'display_name' => 'Искры хода',
            'variant' => 'sparks',
            'event' => 'move',
        ],
        'game-ttt-effect-strike' => [
            'display_name' => 'Импульс хода',
            'variant' => 'wave',
            'event' => 'move',
        ],
    ];

    public function version(): string
    {
        return '20260901_0018_tictactoe_single_effect_slot';
    }

    public function description(): string
    {
        return 'Make Tic Tac Toe effects mutually exclusive, move-time only, and preserve existing purchases/equipment.';
    }

    public function transactional(): bool
    {
        return true;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

        foreach (self::EFFECTS as $itemId => $changes) {
            $rows = $database->fetchAll(
                'SELECT metadata_json FROM mgw_product_catalog WHERE item_id = :item_id',
                ['item_id' => $itemId]
            );
            if ($rows === []) continue;

            $metadata = json_decode((string)($rows[0]['metadata_json'] ?? '{}'), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($metadata)) {
                throw new RuntimeException('Tic Tac Toe effect metadata is invalid for ' . $itemId);
            }
            foreach ($changes as $key => $value) $metadata[$key] = $value;

            $database->execute(
                'UPDATE mgw_product_catalog
                 SET equip_slot = :equip_slot, metadata_json = :metadata_json, updated_at_utc = :updated_at
                 WHERE item_id = :item_id',
                [
                    'equip_slot' => self::EFFECT_SLOT,
                    'metadata_json' => json_encode(
                        $metadata,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    ),
                    'updated_at' => $now,
                    'item_id' => $itemId,
                ]
            );
        }

        $placeholders = implode(',', array_fill(0, count(self::LEGACY_SLOTS), '?'));
        $rows = $database->fetchAll(
            "SELECT mgw_id, equip_slot, item_id, equipped_at_utc
             FROM mgw_equipped_items
             WHERE equip_slot IN ({$placeholders})
             ORDER BY mgw_id ASC, equipped_at_utc DESC, equip_slot ASC",
            self::LEGACY_SLOTS
        );

        $selectedByUser = [];
        foreach ($rows as $row) {
            $mgwId = trim((string)($row['mgw_id'] ?? ''));
            $itemId = trim((string)($row['item_id'] ?? ''));
            if ($mgwId === '' || isset($selectedByUser[$mgwId]) || !isset(self::EFFECTS[$itemId])) continue;
            $selectedByUser[$mgwId] = [
                'item_id' => $itemId,
                'equipped_at' => (string)($row['equipped_at_utc'] ?? $now),
            ];
        }

        foreach (array_keys($selectedByUser) as $mgwId) {
            foreach (self::LEGACY_SLOTS as $slot) {
                $database->execute(
                    'DELETE FROM mgw_equipped_items WHERE mgw_id = :mgw_id AND equip_slot = :equip_slot',
                    ['mgw_id' => $mgwId, 'equip_slot' => $slot]
                );
            }
            $selected = $selectedByUser[$mgwId];
            $database->execute(
                'INSERT INTO mgw_equipped_items (mgw_id, equip_slot, item_id, equipped_at_utc)
                 VALUES (:mgw_id, :equip_slot, :item_id, :equipped_at)',
                [
                    'mgw_id' => $mgwId,
                    'equip_slot' => self::EFFECT_SLOT,
                    'item_id' => $selected['item_id'],
                    'equipped_at' => $selected['equipped_at'],
                ]
            );
        }
    }
};
