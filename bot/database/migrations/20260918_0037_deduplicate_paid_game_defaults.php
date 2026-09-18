<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    private const DISPLAY_NAMES = [
        'game-chess-board-wood' => 'Янтарная доска',
        'game-chess-pieces-wood' => 'Янтарные фигуры',
        'game-checkers-board-wood' => 'Лазурная доска',
        'game-checkers-pieces-wood' => 'Керамические шашки',
        'game-reversi-field-green' => 'Лазурное поле',
        'game-reversi-pieces-classic' => 'Перламутровые фишки',
        'game-go-board-wood' => 'Доска сакуры',
        'game-go-stones-classic' => 'Янтарные камни',
        'game-domino-table-felt' => 'Бордовый стол',
        'game-domino-tiles-ivory' => 'Янтарные костяшки',
        'game-four-field-blue' => 'Фиолетовое поле',
        'game-four-discs-classic' => 'Аркадные фишки',
    ];

    public function version(): string
    {
        return '20260918_0037_deduplicate_paid_game_defaults';
    }

    public function description(): string
    {
        return 'Rename the first paid field/element cosmetics so no paid SKU presents as the free default; stable item ids and ownership are preserved.';
    }

    public function transactional(): bool
    {
        return true;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

        foreach (self::DISPLAY_NAMES as $itemId => $displayName) {
            $rows = $database->fetchAll(
                'SELECT metadata_json FROM mgw_product_catalog WHERE item_id = :item_id LIMIT 1',
                ['item_id' => $itemId]
            );
            if ($rows === []) continue;

            $metadata = json_decode((string)($rows[0]['metadata_json'] ?? '{}'), true);
            if (!is_array($metadata)) $metadata = [];
            $metadata['display_name'] = $displayName;
            $metadata['paid_default_dedup'] = 'v1';

            $database->execute(
                'UPDATE mgw_product_catalog
                 SET metadata_json = :metadata_json, updated_at_utc = :updated_at
                 WHERE item_id = :item_id',
                [
                    'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'updated_at' => $now,
                    'item_id' => $itemId,
                ]
            );
        }
    }
};
