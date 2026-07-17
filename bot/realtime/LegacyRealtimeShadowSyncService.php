<?php
declare(strict_types=1);

final class LegacyRealtimeShadowSyncService
{
    private const SECTIONS = ['games', 'queue', 'invites', 'notifications'];

    public function __construct(
        private StorageAdapterInterface $storage,
        private DatabaseConnectionInterface $database
    ) {}

    public function preview(): array
    {
        return $this->synchronize(true);
    }

    public function run(): array
    {
        return $this->synchronize(false);
    }

    private function synchronize(bool $dryRun): array
    {
        $source = $this->storage->readOnly(static function (array $data): array {
            $snapshot = [];
            foreach (self::SECTIONS as $section) {
                $snapshot[$section] = is_array($data[$section] ?? null) ? $data[$section] : [];
            }
            return $snapshot;
        });

        if (!is_array($source)) {
            throw new RuntimeException('Legacy realtime snapshot is invalid.');
        }

        $entities = $this->normalizeSnapshot($source);
        $existingRows = $this->database->fetchAll(
            'SELECT entity_type, entity_key, payload_sha256 FROM mgw_legacy_realtime_shadow'
        );
        $existing = [];
        foreach ($existingRows as $row) {
            $type = (string)($row['entity_type'] ?? '');
            $key = (string)($row['entity_key'] ?? '');
            if ($type === '' || $key === '') continue;
            $existing[$type][$key] = (string)($row['payload_sha256'] ?? '');
        }

        $summary = [];
        $sourceFingerprintParts = [];
        foreach (self::SECTIONS as $type) {
            $summary[$type] = [
                'source_count' => count($entities[$type]),
                'inserted_count' => 0,
                'updated_count' => 0,
                'unchanged_count' => 0,
                'deleted_count' => 0,
            ];

            foreach ($entities[$type] as $key => $entity) {
                $sourceFingerprintParts[] = $type . "\0" . $key . "\0" . $entity['payload_sha256'];
                if (!array_key_exists($key, $existing[$type] ?? [])) {
                    $summary[$type]['inserted_count']++;
                } elseif (($existing[$type][$key] ?? '') !== $entity['payload_sha256']) {
                    $summary[$type]['updated_count']++;
                } else {
                    $summary[$type]['unchanged_count']++;
                }
            }

            foreach (array_keys($existing[$type] ?? []) as $key) {
                if (!array_key_exists($key, $entities[$type])) {
                    $summary[$type]['deleted_count']++;
                }
            }
        }

        sort($sourceFingerprintParts, SORT_STRING);
        $sourceFingerprint = hash('sha256', implode("\n", $sourceFingerprintParts));

        if (!$dryRun) {
            $syncedAt = $this->timestamp(null);
            $this->database->transaction(function (DatabaseConnectionInterface $database) use (
                $entities,
                $existing,
                $syncedAt
            ): void {
                foreach (self::SECTIONS as $type) {
                    foreach ($entities[$type] as $key => $entity) {
                        if (!array_key_exists($key, $existing[$type] ?? [])) {
                            $database->execute(
                                'INSERT INTO mgw_legacy_realtime_shadow (
                                    entity_type, entity_key, payload_json, payload_sha256,
                                    source_updated_at_utc, synced_at_utc
                                 ) VALUES (
                                    :entity_type, :entity_key, :payload_json, :payload_sha256,
                                    :source_updated_at_utc, :synced_at_utc
                                 )',
                                [
                                    'entity_type' => $type,
                                    'entity_key' => $key,
                                    'payload_json' => $entity['payload_json'],
                                    'payload_sha256' => $entity['payload_sha256'],
                                    'source_updated_at_utc' => $entity['source_updated_at_utc'],
                                    'synced_at_utc' => $syncedAt,
                                ]
                            );
                            continue;
                        }

                        if (($existing[$type][$key] ?? '') === $entity['payload_sha256']) {
                            continue;
                        }

                        $database->execute(
                            'UPDATE mgw_legacy_realtime_shadow SET
                                payload_json = :payload_json,
                                payload_sha256 = :payload_sha256,
                                source_updated_at_utc = :source_updated_at_utc,
                                synced_at_utc = :synced_at_utc
                             WHERE entity_type = :entity_type AND entity_key = :entity_key',
                            [
                                'payload_json' => $entity['payload_json'],
                                'payload_sha256' => $entity['payload_sha256'],
                                'source_updated_at_utc' => $entity['source_updated_at_utc'],
                                'synced_at_utc' => $syncedAt,
                                'entity_type' => $type,
                                'entity_key' => $key,
                            ]
                        );
                    }

                    foreach (array_keys($existing[$type] ?? []) as $key) {
                        if (array_key_exists($key, $entities[$type])) continue;
                        $database->execute(
                            'DELETE FROM mgw_legacy_realtime_shadow
                             WHERE entity_type = :entity_type AND entity_key = :entity_key',
                            ['entity_type' => $type, 'entity_key' => $key]
                        );
                    }
                }
            });
        }

        return [
            'ok' => true,
            'dry_run' => $dryRun,
            'storage_driver' => $this->storage->driver(),
            'source_fingerprint' => $sourceFingerprint,
            'sections' => $summary,
        ];
    }

    private function normalizeSnapshot(array $source): array
    {
        $normalized = [];
        foreach (self::SECTIONS as $type) {
            $normalized[$type] = [];
            foreach ($source[$type] ?? [] as $sourceKey => $record) {
                if (!is_array($record)) continue;
                $key = $this->entityKey($type, $sourceKey, $record);
                if (isset($normalized[$type][$key])) {
                    throw new RuntimeException('Duplicate legacy shadow key: ' . $type . '/' . $key);
                }
                $payload = $this->canonicalJson($record);
                $normalized[$type][$key] = [
                    'payload_json' => $payload,
                    'payload_sha256' => hash('sha256', $payload),
                    'source_updated_at_utc' => $this->recordTimestamp($record),
                ];
            }
            ksort($normalized[$type], SORT_STRING);
        }
        return $normalized;
    }

    private function entityKey(string $type, int|string $sourceKey, array $record): string
    {
        $candidates = match ($type) {
            'games' => [$record['id'] ?? null, is_string($sourceKey) ? $sourceKey : null],
            'queue' => [$record['id'] ?? null, isset($record['user_id']) ? 'user:' . $record['user_id'] : null],
            'invites' => [$record['id'] ?? null, isset($record['token']) ? 'token:' . $record['token'] : null],
            'notifications' => [
                $record['id'] ?? null,
                isset($record['event_key'], $record['user_id'])
                    ? 'event:' . $record['user_id'] . ':' . $record['event_key']
                    : null,
            ],
            default => [],
        };

        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '') return $this->safeKey($candidate);
        }

        return 'sha256:' . hash('sha256', $this->canonicalJson($record));
    }

    private function safeKey(string $key): string
    {
        if (strlen($key) <= 180) return $key;
        return 'sha256:' . hash('sha256', $key);
    }

    private function recordTimestamp(array $record): ?string
    {
        foreach (['updated_at', 'created_at', 'last_move_at', 'finished_at'] as $field) {
            if (!empty($record[$field])) return $this->timestamp((string)$record[$field]);
        }
        return null;
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }

    private function timestamp(?string $value): string
    {
        try {
            $date = $value === null || trim($value) === ''
                ? new DateTimeImmutable('now', new DateTimeZone('UTC'))
                : new DateTimeImmutable($value);
        } catch (Throwable) {
            $date = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
