<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    private const UPDATES = [
        'profile-background-03' => [
            'display_name' => 'Бездна',
            'variant' => 'abyss',
        ],
        'profile-background-04' => [
            'display_name' => 'Квантовый шторм',
            'variant' => 'quantum_storm',
        ],
    ];

    public function version(): string
    {
        return '20260903_0023_upgrade_profile_background_tiers';
    }

    public function description(): string
    {
        return 'Move Abyss to the epic background tier and promote the legendary tier to Quantum Storm without changing stable item ownership.';
    }

    public function transactional(): bool
    {
        return true;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

        foreach (self::UPDATES as $itemId => $update) {
            $rows = $database->fetchAll(
                'SELECT metadata_json FROM mgw_product_catalog WHERE item_id = :item_id',
                ['item_id' => $itemId]
            );
            if ($rows === []) continue;

            $metadata = json_decode((string)($rows[0]['metadata_json'] ?? '{}'), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($metadata)) {
                throw new RuntimeException('Profile background metadata is invalid for ' . $itemId . '.');
            }

            $metadata['display_name'] = $update['display_name'];
            $metadata['variant'] = $update['variant'];

            $database->execute(
                'UPDATE mgw_product_catalog
                 SET metadata_json = :metadata_json, updated_at_utc = :updated_at
                 WHERE item_id = :item_id',
                [
                    'metadata_json' => json_encode(
                        $metadata,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    ),
                    'updated_at' => $now,
                    'item_id' => $itemId,
                ]
            );
        }
    }
};
