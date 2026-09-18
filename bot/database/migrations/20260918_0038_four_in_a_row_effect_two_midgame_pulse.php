<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260918_0038_four_in_a_row_effect_two_midgame_pulse';
    }

    public function description(): string
    {
        return 'Rename Four in a Row effect 2 and move its catalog event from victory-line semantics to a normal-placement energy pulse.';
    }

    public function transactional(): bool
    {
        return true;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        $metadata = json_encode([
            'game_type' => 'four_in_a_row',
            'layer' => 'effect',
            'variant' => 'four',
            'display_name' => 'Энергетический импульс',
            'event' => 'placement_pulse',
            'preview_mode' => 'static_concept',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $database->execute(
            'UPDATE mgw_product_catalog
             SET metadata_json = :metadata_json, updated_at_utc = :updated_at
             WHERE item_id = :item_id',
            [
                'metadata_json' => $metadata,
                'updated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
                'item_id' => 'game-four-effect-four',
            ]
        );
    }
};
