<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    private const EFFECTS = [
        'game-four-effect-drop' => [
            'variant'=>'drop',
            'display_name'=>'Лазерное наведение',
            'event'=>'drop',
        ],
        'game-four-effect-four' => [
            'variant'=>'four',
            'display_name'=>'Энергетический импульс',
            'event'=>'placement_pulse',
        ],
        'game-four-effect-victory-wave' => [
            'variant'=>'victory-wave',
            'display_name'=>'Победный овердрайв',
            'event'=>'victory_wave',
        ],
    ];

    public function version(): string
    {
        return '20260918_0039_four_in_a_row_accepted_preview_copy';
    }

    public function description(): string
    {
        return 'Publish player-facing Four in a Row effect names and mark the three accepted Store/Profile previews as animated.';
    }

    public function transactional(): bool
    {
        return true;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

        foreach (self::EFFECTS as $itemId => $definition) {
            $metadata = json_encode([
                'game_type' => 'four_in_a_row',
                'layer' => 'effect',
                'variant' => (string)$definition['variant'],
                'display_name' => (string)$definition['display_name'],
                'event' => (string)$definition['event'],
                'preview_mode' => 'animated_preview',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            $database->execute(
                'UPDATE mgw_product_catalog
                 SET metadata_json = :metadata_json, updated_at_utc = :updated_at
                 WHERE item_id = :item_id',
                [
                    'metadata_json' => $metadata,
                    'updated_at' => $now,
                    'item_id' => $itemId,
                ]
            );
        }
    }
};
