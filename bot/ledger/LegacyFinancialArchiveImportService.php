<?php
declare(strict_types=1);

final class LegacyFinancialArchiveImportService
{
    private const META_KEY = 'legacy_financial_archive_import_v1';
    private const SOURCE_FILES = [
        'payments' => 'payments.json',
        'shop_orders' => 'shop_orders.json',
        'transactions' => 'transactions.json',
    ];
    private const FINANCIAL_TRANSACTION_CATEGORIES = [
        'payment_draft',
        'payment_apply',
        'payment_reject',
        'payment_cancel',
        'shop_order',
        'shop_order_done',
        'shop_order_complete',
        'shop_order_fulfill',
        'shop_order_reject',
        'shop_refund',
        'shop_order_refund',
        'shop_order_cancel',
    ];

    public function __construct(
        private StorageAdapterInterface $storage,
        private DatabaseConnectionInterface $database,
        private LegacyFinancialStatusNormalizer $normalizer
    ) {}

    public function preview(): array
    {
        return $this->inspect();
    }

    public function run(): array
    {
        $plan = $this->inspect();
        if (!$plan['ready']) {
            throw new RuntimeException('Legacy financial archive import is not ready: ' . implode('; ', $plan['blocking_reasons']));
        }

        $source = $this->loadSource();
        $fingerprint = $source['fingerprint'];
        if (!hash_equals((string)$plan['source_fingerprint'], $fingerprint)) {
            throw new RuntimeException('Legacy financial JSON changed before archive import started.');
        }

        $this->writeMeta('started', $fingerprint, [
            'source_counts' => $source['source_counts'],
            'archive_counts' => $source['archive_counts'],
            'started_at_utc' => $this->now(),
        ]);

        $created = ['payments' => 0, 'shop_orders' => 0, 'transactions' => 0];
        $unchanged = ['payments' => 0, 'shop_orders' => 0, 'transactions' => 0];
        $archivedAt = $this->now();

        $this->database->transaction(function (DatabaseConnectionInterface $database) use ($source, $archivedAt, &$created, &$unchanged): void {
            foreach ($source['items'] as $item) {
                $state = $this->itemState($item, $database);
                if ($state['state'] === 'unchanged') {
                    $unchanged[$item['section']]++;
                    continue;
                }
                if ($state['state'] !== 'create') {
                    throw new RuntimeException($item['source_file'] . '#' . $item['source_index'] . ': ' . ($state['reason'] ?? 'archive conflict'));
                }

                $this->insertItem($item, $archivedAt, $database);
                $after = $this->itemState($item, $database);
                if ($after['state'] !== 'unchanged') {
                    throw new RuntimeException($item['source_file'] . '#' . $item['source_index'] . ': inserted archive row failed verification.');
                }
                $created[$item['section']]++;
            }
        });

        $current = $this->loadSource();
        if (!hash_equals($fingerprint, $current['fingerprint'])) {
            throw new RuntimeException('Legacy financial JSON changed during archive import.');
        }

        $verification = $this->verifyImportedState($current);
        if (!$verification['ok']) {
            throw new RuntimeException('Legacy financial archive failed verification.');
        }

        $completedAt = $this->now();
        $details = [
            'source_counts' => $current['source_counts'],
            'archive_counts' => $current['archive_counts'],
            'created_counts' => $created,
            'unchanged_counts' => $unchanged,
            'skipped_transaction_count' => $current['skipped_transaction_count'],
            'unknown_status_count' => $current['unknown_status_count'],
            'synthetic_id_count' => $current['synthetic_id_count'],
            'completed_at_utc' => $completedAt,
        ];
        $this->writeMeta('completed', $fingerprint, $details);

        return [
            'ok' => true,
            'dry_run' => false,
            'status' => 'completed',
            'source_fingerprint' => $fingerprint,
            'source_counts' => $current['source_counts'],
            'archive_counts' => $current['archive_counts'],
            'created_counts' => $created,
            'unchanged_counts' => $unchanged,
            'skipped_transaction_count' => $current['skipped_transaction_count'],
            'unknown_status_count' => $current['unknown_status_count'],
            'synthetic_id_count' => $current['synthetic_id_count'],
            'verification' => $verification,
            'completed_at_utc' => $completedAt,
        ];
    }

    private function inspect(): array
    {
        $source = $this->loadSource();
        $meta = $this->loadMeta();
        $blocking = [];
        $planned = ['payments' => 0, 'shop_orders' => 0, 'transactions' => 0];
        $unchanged = ['payments' => 0, 'shop_orders' => 0, 'transactions' => 0];
        $conflicts = ['payments' => 0, 'shop_orders' => 0, 'transactions' => 0];
        $samples = [];

        if ($meta !== null && !hash_equals((string)$meta['source_fingerprint'], $source['fingerprint'])) {
            $blocking[] = 'Archive metadata belongs to a different source fingerprint.';
        }

        $expectedPositions = [];
        foreach ($source['items'] as $item) {
            $expectedPositions[$item['table'] . "\0" . $item['source_file'] . "\0" . $item['source_index']] = true;
            $state = $this->itemState($item, $this->database);
            if ($state['state'] === 'create') {
                $planned[$item['section']]++;
            } elseif ($state['state'] === 'unchanged') {
                $unchanged[$item['section']]++;
            } else {
                $conflicts[$item['section']]++;
                $blocking[] = $item['source_file'] . '#' . $item['source_index'] . ': ' . ($state['reason'] ?? 'archive conflict');
            }

            if (count($samples) < 50) {
                $samples[] = [
                    'section' => $item['section'],
                    'legacy_id' => $item['legacy_id'],
                    'source_file' => $item['source_file'],
                    'source_index' => $item['source_index'],
                    'status_normalized' => $item['row']['status_normalized'],
                    'state' => $state['state'],
                    'reason' => $state['reason'],
                ];
            }
        }

        $unmanaged = ['payments' => 0, 'shop_orders' => 0, 'transactions' => 0];
        foreach ([
            'payments' => 'mgw_legacy_payments',
            'shop_orders' => 'mgw_legacy_shop_orders',
            'transactions' => 'mgw_legacy_financial_transactions',
        ] as $section => $table) {
            foreach ($this->database->fetchAll('SELECT source_file, source_index FROM ' . $table) as $row) {
                $key = $table . "\0" . (string)$row['source_file'] . "\0" . (string)$row['source_index'];
                if (!isset($expectedPositions[$key])) $unmanaged[$section]++;
            }
            if ($unmanaged[$section] > 0) {
                $blocking[] = $table . ' contains rows outside the current archive plan.';
            }
        }

        $status = $meta['status'] ?? 'not_started';
        if ($status === 'completed' && $blocking === [] && array_sum($planned) > 0) {
            $blocking[] = 'Completed archive import is missing expected rows.';
        }

        return [
            'ok' => $blocking === [],
            'dry_run' => true,
            'ready' => $blocking === [],
            'status' => $status,
            'source_fingerprint' => $source['fingerprint'],
            'source_counts' => $source['source_counts'],
            'archive_counts' => $source['archive_counts'],
            'planned_create_counts' => $planned,
            'unchanged_counts' => $unchanged,
            'conflict_counts' => $conflicts,
            'unmanaged_counts' => $unmanaged,
            'skipped_transaction_count' => $source['skipped_transaction_count'],
            'unknown_status_count' => $source['unknown_status_count'],
            'synthetic_id_count' => $source['synthetic_id_count'],
            'timestamp_warning_count' => $source['timestamp_warning_count'],
            'blocking_reasons' => array_values(array_unique($blocking)),
            'items' => $samples,
            'meta' => $meta,
        ];
    }

    private function loadSource(): array
    {
        $snapshot = $this->storage->readOnly(static function (array $data): array {
            return [
                'payments' => is_array($data['payments'] ?? null) ? array_values($data['payments']) : [],
                'shop_orders' => is_array($data['shop_orders'] ?? null) ? array_values($data['shop_orders']) : [],
                'transactions' => is_array($data['transactions'] ?? null) ? array_values($data['transactions']) : [],
            ];
        });

        $identityMap = $this->identityMap();
        $items = [];
        $fingerprintParts = [];
        $rawPaymentIds = [];
        $rawOrderIds = [];
        $archiveIds = ['payments' => [], 'shop_orders' => [], 'transactions' => []];
        $unknownStatusCount = 0;
        $syntheticIdCount = 0;
        $timestampWarningCount = 0;

        foreach ($snapshot['payments'] as $index => $payment) {
            if (!is_array($payment)) {
                throw new RuntimeException('payments.json record #' . $index . ' is not an object.');
            }
            $snapshotJson = LedgerIntegrity::canonicalJson($payment);
            $snapshotHash = hash('sha256', $snapshotJson);
            $rawId = trim((string)($payment['id'] ?? ''));
            if ($rawId !== '') $rawPaymentIds[$rawId] = true;
            [$legacyId, $synthetic] = $this->stableLegacyId($rawId, 'payment', self::SOURCE_FILES['payments'], $index, $snapshotHash);
            if ($synthetic) $syntheticIdCount++;
            if (isset($archiveIds['payments'][$legacyId])) throw new RuntimeException('payments.json contains a duplicate legacy payment ID: ' . $legacyId . '.');
            $archiveIds['payments'][$legacyId] = true;
            $statusRaw = trim((string)($payment['status'] ?? ''));
            $statusNormalized = $this->normalizer->payment($statusRaw);
            if ($statusNormalized === 'unknown') $unknownStatusCount++;
            $user = $this->accountIdentity($payment, $identityMap);
            $created = $this->timestamp($payment['created_at'] ?? null, $timestampWarningCount);
            $updated = $this->timestamp($payment['updated_at'] ?? null, $timestampWarningCount);
            $decided = $this->timestamp(
                $payment['applied_at'] ?? $payment['paid_at'] ?? $payment['rejected_at'] ?? $payment['cancelled_at'] ?? null,
                $timestampWarningCount
            );
            $room = $this->nullableText($payment['room'] ?? null);
            $row = [
                'legacy_payment_id' => $legacyId,
                'account_ref' => $user['account_ref'] ?? 'legacy:unknown:' . substr($snapshotHash, 0, 32),
                'mgw_id' => $user['mgw_id'],
                'legacy_user_id' => $user['legacy_user_id'],
                'provider' => $this->boundedText($payment['provider'] ?? null, 64),
                'status_raw' => $this->boundedText($statusRaw, 64) ?? '',
                'status_normalized' => $statusNormalized,
                'room_raw' => $this->boundedText($room, 32),
                'asset_code' => $this->assetCode($room),
                'coin_amount' => $this->nullableInteger($payment['coins'] ?? null),
                'fiat_amount_minor' => $this->fiatMinor($payment),
                'currency' => $this->boundedText($payment['currency'] ?? null, 8, true),
                'balance_applied' => !empty($payment['balance_applied']) ? 1 : 0,
                'source_created_at_utc' => $created,
                'source_updated_at_utc' => $updated,
                'source_decided_at_utc' => $decided,
                'snapshot_json' => $snapshotJson,
                'snapshot_sha256' => $snapshotHash,
                'archive_batch_id' => '',
                'source_file' => self::SOURCE_FILES['payments'],
                'source_index' => $index,
            ];
            $items[] = $this->archiveItem('payments', 'mgw_legacy_payments', 'legacy_payment_id', $legacyId, $row);
            $fingerprintParts[] = self::SOURCE_FILES['payments'] . "\0" . $index . "\0" . $snapshotHash;
        }

        foreach ($snapshot['shop_orders'] as $index => $order) {
            if (!is_array($order)) {
                throw new RuntimeException('shop_orders.json record #' . $index . ' is not an object.');
            }
            $snapshotJson = LedgerIntegrity::canonicalJson($order);
            $snapshotHash = hash('sha256', $snapshotJson);
            $rawId = trim((string)($order['id'] ?? ''));
            if ($rawId !== '') $rawOrderIds[$rawId] = true;
            [$legacyId, $synthetic] = $this->stableLegacyId($rawId, 'order', self::SOURCE_FILES['shop_orders'], $index, $snapshotHash);
            if ($synthetic) $syntheticIdCount++;
            if (isset($archiveIds['shop_orders'][$legacyId])) throw new RuntimeException('shop_orders.json contains a duplicate legacy order ID: ' . $legacyId . '.');
            $archiveIds['shop_orders'][$legacyId] = true;
            $statusRaw = trim((string)($order['status'] ?? ''));
            $statusNormalized = $this->normalizer->order($statusRaw);
            if ($statusNormalized === 'unknown') $unknownStatusCount++;
            $user = $this->accountIdentity($order, $identityMap);
            $created = $this->timestamp($order['created_at'] ?? null, $timestampWarningCount);
            $updated = $this->timestamp($order['updated_at'] ?? null, $timestampWarningCount);
            $decided = $this->timestamp(
                $order['completed_at'] ?? $order['rejected_at'] ?? $order['refunded_at'] ?? null,
                $timestampWarningCount
            );
            $row = [
                'legacy_order_id' => $legacyId,
                'account_ref' => $user['account_ref'] ?? 'legacy:unknown:' . substr($snapshotHash, 0, 32),
                'mgw_id' => $user['mgw_id'],
                'legacy_user_id' => $user['legacy_user_id'],
                'status_raw' => $this->boundedText($statusRaw, 64) ?? '',
                'status_normalized' => $statusNormalized,
                'refund_done' => !empty($order['refund_done']) ? 1 : 0,
                'gold_amount' => $this->nullableInteger($order['gold_cost'] ?? $order['amount'] ?? null),
                'item_id' => $this->boundedText($order['item_id'] ?? ($order['prize_snapshot']['item_id'] ?? null), 191),
                'denomination_id' => $this->boundedText($order['denomination_id'] ?? ($order['prize_snapshot']['denomination_id'] ?? null), 191),
                'provider' => $this->boundedText($order['provider'] ?? ($order['prize_snapshot']['provider'] ?? null), 191),
                'country_code' => $this->boundedText($order['country_code'] ?? ($order['prize_snapshot']['country_code'] ?? null), 16, true),
                'source_created_at_utc' => $created,
                'source_updated_at_utc' => $updated,
                'source_decided_at_utc' => $decided,
                'snapshot_json' => $snapshotJson,
                'snapshot_sha256' => $snapshotHash,
                'archive_batch_id' => '',
                'source_file' => self::SOURCE_FILES['shop_orders'],
                'source_index' => $index,
            ];
            $items[] = $this->archiveItem('shop_orders', 'mgw_legacy_shop_orders', 'legacy_order_id', $legacyId, $row);
            $fingerprintParts[] = self::SOURCE_FILES['shop_orders'] . "\0" . $index . "\0" . $snapshotHash;
        }

        $archivedTransactionCount = 0;
        $skippedTransactionCount = 0;
        foreach ($snapshot['transactions'] as $index => $transaction) {
            if (!is_array($transaction)) {
                throw new RuntimeException('transactions.json record #' . $index . ' is not an object.');
            }
            $category = strtolower(trim((string)($transaction['category'] ?? '')));
            $type = strtolower(trim((string)($transaction['type'] ?? '')));
            $paymentId = trim((string)($transaction['payment_id'] ?? ''));
            $orderId = trim((string)($transaction['order_id'] ?? ''));
            $related = in_array($category, self::FINANCIAL_TRANSACTION_CATEGORIES, true)
                || in_array($type, self::FINANCIAL_TRANSACTION_CATEGORIES, true)
                || ($paymentId !== '' && isset($rawPaymentIds[$paymentId]))
                || ($orderId !== '' && isset($rawOrderIds[$orderId]));
            if (!$related) {
                $skippedTransactionCount++;
                continue;
            }

            $snapshotJson = LedgerIntegrity::canonicalJson($transaction);
            $snapshotHash = hash('sha256', $snapshotJson);
            $rawId = trim((string)($transaction['id'] ?? ''));
            [$legacyId, $synthetic] = $this->stableLegacyId($rawId, 'transaction', self::SOURCE_FILES['transactions'], $index, $snapshotHash);
            if ($synthetic) $syntheticIdCount++;
            if (isset($archiveIds['transactions'][$legacyId])) throw new RuntimeException('transactions.json contains a duplicate archived transaction ID: ' . $legacyId . '.');
            $archiveIds['transactions'][$legacyId] = true;
            $statusNormalized = $this->normalizer->transaction($transaction);
            if ($statusNormalized === 'unknown') $unknownStatusCount++;
            $user = $this->accountIdentity($transaction, $identityMap);
            $room = $this->nullableText($transaction['room'] ?? null);
            $row = [
                'legacy_transaction_id' => $legacyId,
                'account_ref' => $user['account_ref'],
                'mgw_id' => $user['mgw_id'],
                'legacy_user_id' => $user['legacy_user_id'],
                'legacy_payment_id' => $this->boundedText($paymentId, 191),
                'legacy_order_id' => $this->boundedText($orderId, 191),
                'type_raw' => $this->boundedText($transaction['type'] ?? null, 64),
                'category_raw' => $this->boundedText($transaction['category'] ?? null, 64),
                'status_normalized' => $statusNormalized,
                'room_raw' => $this->boundedText($room, 32),
                'asset_code' => $this->assetCode($room),
                'amount' => $this->nullableInteger($transaction['amount'] ?? null),
                'balance_before' => $this->nullableInteger($transaction['balance_before'] ?? null),
                'balance_after' => $this->nullableInteger($transaction['balance_after'] ?? null),
                'fiat_amount_minor' => $this->fiatMinor($transaction),
                'currency' => $this->boundedText($transaction['currency'] ?? null, 8, true),
                'source_created_at_utc' => $this->timestamp($transaction['created_at'] ?? null, $timestampWarningCount),
                'snapshot_json' => $snapshotJson,
                'snapshot_sha256' => $snapshotHash,
                'archive_batch_id' => '',
                'source_file' => self::SOURCE_FILES['transactions'],
                'source_index' => $index,
            ];
            $items[] = $this->archiveItem('transactions', 'mgw_legacy_financial_transactions', 'legacy_transaction_id', $legacyId, $row);
            $fingerprintParts[] = self::SOURCE_FILES['transactions'] . "\0" . $index . "\0" . $snapshotHash;
            $archivedTransactionCount++;
        }

        sort($fingerprintParts, SORT_STRING);
        $fingerprint = hash('sha256', implode("\n", $fingerprintParts));
        foreach ($items as &$item) {
            $item['row']['archive_batch_id'] = $fingerprint;
        }
        unset($item);

        return [
            'fingerprint' => $fingerprint,
            'items' => $items,
            'source_counts' => [
                'payments' => count($snapshot['payments']),
                'shop_orders' => count($snapshot['shop_orders']),
                'transactions' => count($snapshot['transactions']),
            ],
            'archive_counts' => [
                'payments' => count($snapshot['payments']),
                'shop_orders' => count($snapshot['shop_orders']),
                'transactions' => $archivedTransactionCount,
            ],
            'skipped_transaction_count' => $skippedTransactionCount,
            'unknown_status_count' => $unknownStatusCount,
            'synthetic_id_count' => $syntheticIdCount,
            'timestamp_warning_count' => $timestampWarningCount,
        ];
    }

    private function archiveItem(string $section, string $table, string $idColumn, string $legacyId, array $row): array
    {
        return [
            'section' => $section,
            'table' => $table,
            'id_column' => $idColumn,
            'legacy_id' => $legacyId,
            'source_file' => (string)$row['source_file'],
            'source_index' => (int)$row['source_index'],
            'row' => $row,
        ];
    }

    private function itemState(array $item, DatabaseConnectionInterface $database): array
    {
        $sourceRows = $database->fetchAll(
            'SELECT * FROM ' . $item['table'] . ' WHERE source_file = :source_file AND source_index = :source_index',
            ['source_file' => $item['source_file'], 'source_index' => $item['source_index']]
        );
        $idRows = $database->fetchAll(
            'SELECT * FROM ' . $item['table'] . ' WHERE ' . $item['id_column'] . ' = :legacy_id',
            ['legacy_id' => $item['legacy_id']]
        );

        if ($sourceRows === [] && $idRows === []) {
            return ['state' => 'create', 'reason' => null];
        }
        if (count($sourceRows) !== 1 || count($idRows) !== 1) {
            return ['state' => 'conflict', 'reason' => 'Archive source position or legacy ID is not unique.'];
        }
        if ((string)$sourceRows[0][$item['id_column']] !== (string)$idRows[0][$item['id_column']]) {
            return ['state' => 'conflict', 'reason' => 'Archive source position and legacy ID point to different rows.'];
        }
        if (!$this->rowMatches($sourceRows[0], $item['row'])) {
            return ['state' => 'conflict', 'reason' => 'Existing archive row does not match the source snapshot.'];
        }
        return ['state' => 'unchanged', 'reason' => null];
    }

    private function rowMatches(array $stored, array $expected): bool
    {
        foreach ($expected as $field => $value) {
            if ($field === 'archived_at_utc') continue;
            $actual = $stored[$field] ?? null;
            if (in_array($field, ['source_index', 'balance_applied', 'refund_done', 'coin_amount', 'fiat_amount_minor', 'gold_amount', 'amount', 'balance_before', 'balance_after'], true)) {
                if ($value === null) {
                    if ($actual !== null) return false;
                } elseif ((int)$actual !== (int)$value) {
                    return false;
                }
                continue;
            }
            if ($this->nullableText($actual) !== $this->nullableText($value)) return false;
        }
        return true;
    }

    private function insertItem(array $item, string $archivedAt, DatabaseConnectionInterface $database): void
    {
        $row = $item['row'];
        $row['archived_at_utc'] = $archivedAt;
        $columns = array_keys($row);
        $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);
        $database->execute(
            'INSERT INTO ' . $item['table'] . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')',
            $row
        );
    }

    private function verifyImportedState(array $source): array
    {
        $errors = [];
        $verified = ['payments' => 0, 'shop_orders' => 0, 'transactions' => 0];
        foreach ($source['items'] as $item) {
            $state = $this->itemState($item, $this->database);
            if ($state['state'] !== 'unchanged') {
                $errors[] = $item['source_file'] . '#' . $item['source_index'] . ': ' . ($state['reason'] ?? 'missing archive row');
                continue;
            }
            $verified[$item['section']]++;
        }

        $databaseCounts = [
            'payments' => (int)$this->database->fetchValue('SELECT COUNT(*) FROM mgw_legacy_payments'),
            'shop_orders' => (int)$this->database->fetchValue('SELECT COUNT(*) FROM mgw_legacy_shop_orders'),
            'transactions' => (int)$this->database->fetchValue('SELECT COUNT(*) FROM mgw_legacy_financial_transactions'),
        ];
        if ($databaseCounts !== $source['archive_counts']) {
            $errors[] = 'Archive table counts do not match the source plan.';
        }

        return [
            'ok' => $errors === [],
            'verified_counts' => $verified,
            'database_counts' => $databaseCounts,
            'expected_counts' => $source['archive_counts'],
            'error_count' => count($errors),
            'errors' => array_slice($errors, 0, 50),
        ];
    }

    private function identityMap(): array
    {
        $map = [];
        foreach ($this->database->fetchAll(
            "SELECT mgw_id, provider_subject FROM mgw_identities WHERE provider IN ('telegram', 'development')"
        ) as $row) {
            $subject = trim((string)($row['provider_subject'] ?? ''));
            $mgwId = trim((string)($row['mgw_id'] ?? ''));
            if ($subject === '' || $mgwId === '') continue;
            if (isset($map[$subject]) && $map[$subject] !== $mgwId) {
                throw new RuntimeException('Legacy user identity maps to multiple MGW IDs: ' . $subject . '.');
            }
            $map[$subject] = $mgwId;
        }
        return $map;
    }

    private function accountIdentity(array $record, array $identityMap): array
    {
        $legacyUserId = trim((string)($record['user_id'] ?? $record['telegram_id'] ?? ''));
        if ($legacyUserId === '') {
            return ['legacy_user_id' => null, 'mgw_id' => null, 'account_ref' => null];
        }
        $mgwId = $identityMap[$legacyUserId] ?? null;
        return [
            'legacy_user_id' => $legacyUserId,
            'mgw_id' => $mgwId,
            'account_ref' => $mgwId === null ? 'legacy:' . $legacyUserId : 'mgw:' . $mgwId,
        ];
    }

    private function stableLegacyId(string $rawId, string $kind, string $sourceFile, int $index, string $snapshotHash): array
    {
        if ($rawId !== '' && strlen($rawId) <= 191 && preg_match('/[\x00-\x1F\x7F]/', $rawId) !== 1) {
            return [$rawId, false];
        }
        return ['source:' . $kind . ':' . $index . ':' . substr(hash('sha256', $sourceFile . '|' . $index . '|' . $snapshotHash), 0, 48), true];
    }

    private function assetCode(?string $room): ?string
    {
        return match (strtolower(trim((string)$room))) {
            'match' => 'match_coin',
            'gold' => 'gold_coin',
            default => null,
        };
    }

    private function fiatMinor(array $record): ?int
    {
        if (array_key_exists('fiat_amount_minor', $record)) {
            return $this->nullableInteger($record['fiat_amount_minor']);
        }
        $major = $this->nullableInteger($record['price'] ?? $record['amount_rub'] ?? null);
        if ($major === null) return null;
        if ($major > intdiv(PHP_INT_MAX, 100) || $major < intdiv(PHP_INT_MIN, 100)) {
            throw new RuntimeException('Legacy fiat amount is outside the supported integer range.');
        }
        return $major * 100;
    }

    private function nullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        if (is_int($value)) return $value;
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) return (int)$value;
        if (is_float($value) && floor($value) === $value) return (int)$value;
        return null;
    }

    private function timestamp(mixed $value, int &$warningCount): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') return null;
        try {
            return (new DateTimeImmutable($value))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s.u');
        } catch (Throwable) {
            $warningCount++;
            return null;
        }
    }

    private function boundedText(mixed $value, int $limit, bool $uppercase = false): ?string
    {
        $text = $this->nullableText($value);
        if ($text === null) return null;
        if ($uppercase) $text = strtoupper($text);
        if (strlen($text) <= $limit && preg_match('/[\x00-\x1F\x7F]/', $text) !== 1) return $text;
        return substr('sha256:' . hash('sha256', $text), 0, $limit);
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string)($value ?? ''));
        return $text === '' ? null : $text;
    }

    private function loadMeta(): ?array
    {
        $rows = $this->database->fetchAll(
            'SELECT meta_value, updated_at_utc FROM mgw_meta WHERE meta_key = :meta_key',
            ['meta_key' => self::META_KEY]
        );
        if ($rows === []) return null;
        try {
            $value = json_decode((string)$rows[0]['meta_value'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Financial archive import metadata is invalid.');
        }
        if (!is_array($value)) throw new RuntimeException('Financial archive import metadata is invalid.');
        return [
            'status' => (string)($value['status'] ?? ''),
            'source_fingerprint' => (string)($value['source_fingerprint'] ?? ''),
            'details' => is_array($value['details'] ?? null) ? $value['details'] : [],
            'updated_at_utc' => (string)$rows[0]['updated_at_utc'],
        ];
    }

    private function writeMeta(string $status, string $fingerprint, array $details): void
    {
        $updatedAt = $this->now();
        $value = LedgerIntegrity::canonicalJson([
            'status' => $status,
            'source_fingerprint' => $fingerprint,
            'details' => $details,
        ]);
        $updated = $this->database->execute(
            'UPDATE mgw_meta SET meta_value = :meta_value, updated_at_utc = :updated_at_utc WHERE meta_key = :meta_key',
            ['meta_value' => $value, 'updated_at_utc' => $updatedAt, 'meta_key' => self::META_KEY]
        );
        if ($updated !== 0) return;

        try {
            $this->database->execute(
                'INSERT INTO mgw_meta (meta_key, meta_value, updated_at_utc) VALUES (:meta_key, :meta_value, :updated_at_utc)',
                ['meta_key' => self::META_KEY, 'meta_value' => $value, 'updated_at_utc' => $updatedAt]
            );
        } catch (Throwable) {
            $this->database->execute(
                'UPDATE mgw_meta SET meta_value = :meta_value, updated_at_utc = :updated_at_utc WHERE meta_key = :meta_key',
                ['meta_value' => $value, 'updated_at_utc' => $updatedAt, 'meta_key' => self::META_KEY]
            );
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
