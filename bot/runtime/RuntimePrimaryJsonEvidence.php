<?php
declare(strict_types=1);

final class RuntimePrimaryJsonEvidence
{
    public static function capture(StorageAdapterInterface $storage): array
    {
        if ($storage->driver() !== 'json') {
            throw new RuntimeException('Staging evidence source must be the JSON rollback driver.');
        }
        $snapshot = $storage->readOnly(static fn(array $data): array => $data);
        if (!is_array($snapshot)) {
            throw new RuntimeException('JSON rollback snapshot is unavailable.');
        }
        $snapshotSha = hash('sha256', self::canonicalJson($snapshot));
        $inventory = [];
        foreach ([
            'users', 'games', 'queue', 'invites', 'notifications',
            'transactions', 'shop_orders', 'payments',
        ] as $section) {
            $inventory[$section . '_count'] = count(
                is_array($snapshot[$section] ?? null) ? $snapshot[$section] : []
            );
        }
        ksort($inventory, SORT_STRING);

        return [
            'sha256' => $snapshotSha,
            'inventory' => $inventory,
            'inventory_fingerprint' => hash('sha256', self::canonicalJson($inventory)),
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    private static function canonicalJson(array $value): string
    {
        $canonicalize = static function (mixed $item) use (&$canonicalize): mixed {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item, SORT_STRING);
            foreach ($item as $key => $child) $item[$key] = $canonicalize($child);
            return $item;
        };
        return json_encode(
            $canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}
