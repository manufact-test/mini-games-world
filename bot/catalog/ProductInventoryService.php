<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/accounts/MgwIdentityPolicy.php';

final class ProductInventoryService
{
    public const PROFILE_AVATAR_SLOT = 'profile_avatar';
    public const ITEM_TYPES = ['profile', 'game', 'bundle', 'seasonal', 'showcase'];
    public const STORE_AVATAR_ITEM_IDS = [
        'store-avatar-01',
        'store-avatar-02',
        'store-avatar-03',
        'store-avatar-04',
        'store-avatar-05',
    ];

    private const TOKEN_PATTERN = '/^[a-z0-9][a-z0-9_.-]{0,63}$/';

    public function __construct(private DatabaseConnectionInterface $database) {}

    /**
     * Idempotent account bootstrap. The three starter avatars are permanent
     * ownership rows; re-running bootstrap never replaces acquisition evidence.
     */
    public function grantStarterItems(string $mgwId): array
    {
        $mgwId = $this->normalizeMgwId($mgwId);
        return $this->database->transaction(function (DatabaseConnectionInterface $database) use ($mgwId): array {
            $this->assertAccountExists($database, $mgwId);
            $granted = [];
            foreach (MgwIdentityPolicy::STARTER_AVATAR_ITEM_IDS as $itemId) {
                if ($this->insertOwnershipIfMissing($database, $mgwId, $itemId, 'starter_bootstrap', null)) {
                    $granted[] = $itemId;
                }
            }
            $this->ensureProfileAvatarEquipment($database, $mgwId);
            return [
                'mgw_id' => $mgwId,
                'granted_item_ids' => $granted,
                'starter_item_ids' => MgwIdentityPolicy::STARTER_AVATAR_ITEM_IDS,
            ];
        });
    }

    /**
     * Generic permanent grant owner for future purchase/reward/admin flows.
     * Duplicate ownership is a no-op: no second row and no silent compensation.
     */
    public function grant(string $mgwId, string $itemId, string $source = 'system', ?string $sourceRef = null): array
    {
        $mgwId = $this->normalizeMgwId($mgwId);
        $itemId = $this->normalizeToken($itemId, 'item');
        $source = $this->normalizeSource($source);
        $sourceRef = $this->normalizeNullableRef($sourceRef);

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $mgwId, $itemId, $source, $sourceRef
        ): array {
            $this->assertAccountExists($database, $mgwId);
            $this->catalogItem($database, $itemId, true);
            $granted = $this->insertOwnershipIfMissing($database, $mgwId, $itemId, $source, $sourceRef);
            return [
                'mgw_id' => $mgwId,
                'item_id' => $itemId,
                'granted' => $granted,
                'reason' => $granted ? null : 'already_owned',
            ];
        });
    }

    public function equip(string $mgwId, string $itemId): array
    {
        $mgwId = $this->normalizeMgwId($mgwId);
        $itemId = $this->normalizeToken($itemId, 'item');

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use ($mgwId, $itemId): array {
            $this->assertAccountExists($database, $mgwId);
            $item = $this->catalogItem($database, $itemId, true);
            $slot = trim((string)($item['equip_slot'] ?? ''));
            if ($slot === '') throw new InvalidArgumentException('MGW item is not equippable.');
            if (!$this->owns($database, $mgwId, $itemId)) {
                throw new RuntimeException('MGW item is not owned by this account.');
            }
            $this->replaceEquipment($database, $mgwId, $slot, $itemId);
            return [
                'mgw_id' => $mgwId,
                'equip_slot' => $slot,
                'item_id' => $itemId,
            ];
        });
    }

    /**
     * Generic slots may become empty. The canonical visible profile avatar may
     * not: unequipping it restores the owned starter-default-01 fallback.
     */
    public function unequip(string $mgwId, string $equipSlot): array
    {
        $mgwId = $this->normalizeMgwId($mgwId);
        $equipSlot = $this->normalizeToken($equipSlot, 'equip slot');

        if ($equipSlot === self::PROFILE_AVATAR_SLOT) {
            $this->grantStarterItems($mgwId);
            return $this->equip($mgwId, MgwIdentityPolicy::DEFAULT_AVATAR_ITEM_ID) + ['fallback' => true];
        }

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use ($mgwId, $equipSlot): array {
            $this->assertAccountExists($database, $mgwId);
            $database->execute(
                'DELETE FROM mgw_equipped_items WHERE mgw_id = :mgw_id AND equip_slot = :equip_slot',
                ['mgw_id' => $mgwId, 'equip_slot' => $equipSlot]
            );
            return ['mgw_id' => $mgwId, 'equip_slot' => $equipSlot, 'item_id' => null];
        });
    }

    public function isOwned(string $mgwId, string $itemId): bool
    {
        $mgwId = $this->normalizeMgwId($mgwId);
        $itemId = $this->normalizeToken($itemId, 'item');
        return $this->owns($this->database, $mgwId, $itemId);
    }

    /**
     * Deterministic read model for future Store/Inventory/Profile clients.
     * Deliberately contains no price or purchase state in MVP-19.1.
     */
    public function snapshot(string $mgwId): array
    {
        $mgwId = $this->normalizeMgwId($mgwId);
        $this->assertAccountExists($this->database, $mgwId);

        $catalogRows = $this->database->fetchAll(
            "SELECT item_id, item_type, item_family, equip_slot, is_store_product, starter_grant,
                    catalog_status, metadata_json
             FROM mgw_product_catalog
             WHERE catalog_status IN ('active','hidden')
             ORDER BY item_type ASC, item_family ASC, item_id ASC"
        );
        $ownedRows = $this->database->fetchAll(
            'SELECT item_id, acquired_source, acquired_ref, acquired_at_utc
             FROM mgw_inventory_items WHERE mgw_id = :mgw_id ORDER BY acquired_at_utc ASC, item_id ASC',
            ['mgw_id' => $mgwId]
        );
        $equippedRows = $this->database->fetchAll(
            'SELECT equip_slot, item_id, equipped_at_utc
             FROM mgw_equipped_items WHERE mgw_id = :mgw_id ORDER BY equip_slot ASC',
            ['mgw_id' => $mgwId]
        );

        $ownedByItem = [];
        foreach ($ownedRows as $row) {
            $itemId = (string)($row['item_id'] ?? '');
            if ($itemId !== '') $ownedByItem[$itemId] = $row;
        }
        $equippedBySlot = [];
        $equippedItemIds = [];
        foreach ($equippedRows as $row) {
            $slot = (string)($row['equip_slot'] ?? '');
            $itemId = (string)($row['item_id'] ?? '');
            if ($slot === '' || $itemId === '') continue;
            $equippedBySlot[$slot] = $itemId;
            $equippedItemIds[$itemId] = true;
        }

        $catalog = [];
        foreach ($catalogRows as $row) {
            $itemId = (string)($row['item_id'] ?? '');
            $catalog[] = [
                'item_id' => $itemId,
                'item_type' => (string)($row['item_type'] ?? ''),
                'item_family' => (string)($row['item_family'] ?? ''),
                'equip_slot' => $this->nullableString($row['equip_slot'] ?? null),
                'is_store_product' => (int)($row['is_store_product'] ?? 0) === 1,
                'starter_grant' => (int)($row['starter_grant'] ?? 0) === 1,
                'catalog_status' => (string)($row['catalog_status'] ?? ''),
                'metadata' => $this->decodeMetadata($row['metadata_json'] ?? null),
                'owned' => isset($ownedByItem[$itemId]),
                'equipped' => isset($equippedItemIds[$itemId]),
            ];
        }

        $owned = [];
        foreach ($ownedRows as $row) {
            $owned[] = [
                'item_id' => (string)($row['item_id'] ?? ''),
                'acquired_source' => (string)($row['acquired_source'] ?? ''),
                'acquired_ref' => $this->nullableString($row['acquired_ref'] ?? null),
                'acquired_at' => $this->nullableString($row['acquired_at_utc'] ?? null),
            ];
        }

        return [
            'mgw_id' => $mgwId,
            'catalog' => $catalog,
            'owned' => $owned,
            'equipped' => $equippedBySlot,
        ];
    }

    private function ensureProfileAvatarEquipment(DatabaseConnectionInterface $database, string $mgwId): void
    {
        $rows = $database->fetchAll(
            'SELECT item_id FROM mgw_equipped_items WHERE mgw_id = :mgw_id AND equip_slot = :equip_slot',
            ['mgw_id' => $mgwId, 'equip_slot' => self::PROFILE_AVATAR_SLOT]
        );
        if ($rows !== []) {
            $current = trim((string)($rows[0]['item_id'] ?? ''));
            if ($current !== '' && $this->owns($database, $mgwId, $current)) {
                $this->projectProfileAvatar($database, $mgwId, $current);
                return;
            }
        }

        $legacy = trim((string)$database->fetchValue(
            'SELECT equipped_avatar_item_id FROM mgw_users WHERE mgw_id = :mgw_id',
            ['mgw_id' => $mgwId]
        ));
        $candidate = $legacy !== '' && $this->owns($database, $mgwId, $legacy)
            ? $legacy
            : MgwIdentityPolicy::DEFAULT_AVATAR_ITEM_ID;
        $item = $this->catalogItem($database, $candidate, true);
        if ((string)($item['equip_slot'] ?? '') !== self::PROFILE_AVATAR_SLOT) {
            $candidate = MgwIdentityPolicy::DEFAULT_AVATAR_ITEM_ID;
        }
        $this->replaceEquipment($database, $mgwId, self::PROFILE_AVATAR_SLOT, $candidate);
    }

    private function replaceEquipment(
        DatabaseConnectionInterface $database,
        string $mgwId,
        string $slot,
        string $itemId
    ): void {
        $database->execute(
            'DELETE FROM mgw_equipped_items WHERE mgw_id = :mgw_id AND equip_slot = :equip_slot',
            ['mgw_id' => $mgwId, 'equip_slot' => $slot]
        );
        $database->execute(
            'INSERT INTO mgw_equipped_items (mgw_id, equip_slot, item_id, equipped_at_utc)
             VALUES (:mgw_id, :equip_slot, :item_id, :equipped_at)',
            [
                'mgw_id' => $mgwId,
                'equip_slot' => $slot,
                'item_id' => $itemId,
                'equipped_at' => $this->timestamp(),
            ]
        );
        if ($slot === self::PROFILE_AVATAR_SLOT) {
            $this->projectProfileAvatar($database, $mgwId, $itemId);
        }
    }

    private function projectProfileAvatar(DatabaseConnectionInterface $database, string $mgwId, string $itemId): void
    {
        $affected = $database->execute(
            'UPDATE mgw_users
             SET equipped_avatar_item_id = :item_id, updated_at_utc = :updated_at
             WHERE mgw_id = :mgw_id',
            ['item_id' => $itemId, 'updated_at' => $this->timestamp(), 'mgw_id' => $mgwId]
        );
        if ($affected !== 1) throw new RuntimeException('Authenticated MGW profile is unavailable.');
    }

    private function insertOwnershipIfMissing(
        DatabaseConnectionInterface $database,
        string $mgwId,
        string $itemId,
        string $source,
        ?string $sourceRef
    ): bool {
        $this->catalogItem($database, $itemId, true);
        if ($this->owns($database, $mgwId, $itemId)) return false;
        try {
            $database->execute(
                'INSERT INTO mgw_inventory_items (mgw_id, item_id, acquired_source, acquired_ref, acquired_at_utc)
                 VALUES (:mgw_id, :item_id, :acquired_source, :acquired_ref, :acquired_at)',
                [
                    'mgw_id' => $mgwId,
                    'item_id' => $itemId,
                    'acquired_source' => $source,
                    'acquired_ref' => $sourceRef,
                    'acquired_at' => $this->timestamp(),
                ]
            );
            return true;
        } catch (PDOException $error) {
            if (MgwIdentityPolicy::isUniqueViolation($error) && $this->owns($database, $mgwId, $itemId)) {
                return false;
            }
            throw $error;
        }
    }

    private function catalogItem(DatabaseConnectionInterface $database, string $itemId, bool $mustBeActive): array
    {
        $rows = $database->fetchAll(
            'SELECT item_id, item_type, item_family, equip_slot, is_store_product, starter_grant, catalog_status
             FROM mgw_product_catalog WHERE item_id = :item_id',
            ['item_id' => $itemId]
        );
        if (count($rows) !== 1) throw new InvalidArgumentException('Unknown MGW catalogue item.');
        $item = $rows[0];
        if ($mustBeActive && (string)($item['catalog_status'] ?? '') !== 'active') {
            throw new RuntimeException('MGW catalogue item is not active.');
        }
        if (!in_array((string)($item['item_type'] ?? ''), self::ITEM_TYPES, true)) {
            throw new RuntimeException('MGW catalogue item type is invalid.');
        }
        return $item;
    }

    private function owns(DatabaseConnectionInterface $database, string $mgwId, string $itemId): bool
    {
        return (int)$database->fetchValue(
            'SELECT COUNT(*) FROM mgw_inventory_items WHERE mgw_id = :mgw_id AND item_id = :item_id',
            ['mgw_id' => $mgwId, 'item_id' => $itemId]
        ) === 1;
    }

    private function assertAccountExists(DatabaseConnectionInterface $database, string $mgwId): void
    {
        if ((int)$database->fetchValue('SELECT COUNT(*) FROM mgw_users WHERE mgw_id = :mgw_id', ['mgw_id' => $mgwId]) !== 1) {
            throw new RuntimeException('Authenticated MGW profile is unavailable.');
        }
    }

    private function normalizeMgwId(string $mgwId): string
    {
        $mgwId = trim($mgwId);
        if ($mgwId === '' || strlen($mgwId) > 24) throw new InvalidArgumentException('MGW inventory account id is invalid.');
        return $mgwId;
    }

    private function normalizeToken(string $value, string $label): string
    {
        $value = strtolower(trim($value));
        if (preg_match(self::TOKEN_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('MGW ' . $label . ' is invalid.');
        }
        return $value;
    }

    private function normalizeSource(string $source): string
    {
        $source = strtolower(trim($source));
        if ($source === '' || strlen($source) > 32 || preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $source) !== 1) {
            throw new InvalidArgumentException('MGW inventory source is invalid.');
        }
        return $source;
    }

    private function normalizeNullableRef(?string $value): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') return null;
        if ((function_exists('mb_strlen') ? mb_strlen($value) : strlen($value)) > 191) {
            throw new InvalidArgumentException('MGW inventory source ref is invalid.');
        }
        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function decodeMetadata(mixed $value): ?array
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') return null;
        try {
            $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (JsonException) {
            return null;
        }
    }

    private function timestamp(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
