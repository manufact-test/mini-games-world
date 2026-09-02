<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    private const NAMES = [
        'profile-frame-01' => 'Небо',
        'profile-frame-02' => 'Золото',
        'profile-frame-03' => 'Аврора',
        'profile-frame-animated' => 'Спектр',
    ];

    public function version(): string
    {
        return '20260902_0022_polish_profile_frame_names';
    }

    public function description(): string
    {
        return 'Replace placeholder Profile frame labels with concise product names.';
    }

    public function transactional(): bool
    {
        return true;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        $rows = $database->fetchAll(
            "SELECT item_id, metadata_json
             FROM mgw_product_catalog
             WHERE item_id IN ('profile-frame-01','profile-frame-02','profile-frame-03','profile-frame-animated')"
        );
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

        foreach ($rows as $row) {
            $itemId = (string)($row['item_id'] ?? '');
            if (!isset(self::NAMES[$itemId])) continue;

            $metadata = json_decode((string)($row['metadata_json'] ?? '{}'), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($metadata)) $metadata = [];
            $metadata['display_name'] = self::NAMES[$itemId];

            $database->execute(
                "UPDATE mgw_product_catalog
                 SET metadata_json = :metadata_json, updated_at_utc = :updated_at
                 WHERE item_id = :item_id",
                [
                    'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'updated_at' => $now,
                    'item_id' => $itemId,
                ]
            );
        }
    }
};
