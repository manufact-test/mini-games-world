<?php
declare(strict_types=1);

final class CompensationService
{
    public const ASSET_CODE = 'mgw_coin';
    public const LARGE_AMOUNT_THRESHOLD = 50000;
    public const MAX_AMOUNT = 250000;

    private const STATUS_PENDING_CONFIRMATION = 'pending_confirmation';
    private const STATUS_PENDING_APPLY = 'pending_apply';
    private const STATUS_APPLIED = 'applied';

    public function __construct(
        private DatabaseConnectionInterface $database,
        private LedgerWriteService $ledger,
        private StorageTransactionInterface $runtimeStorage
    ) {}

    public function limits(): array
    {
        return [
            'asset_code' => self::ASSET_CODE,
            'large_amount_threshold' => self::LARGE_AMOUNT_THRESHOLD,
            'max_amount' => self::MAX_AMOUNT,
        ];
    }

    public function lookupOperation(string $operationRef): array
    {
        $entry = $this->resolveOriginalEntry($operationRef);
        return $this->publicOperation($entry);
    }

    public function requestCompensation(
        string $operationRef,
        mixed $amountValue,
        string $reason,
        string $actorRef,
        string $requestToken
    ): array {
        $actorRef = $this->requiredText($actorRef, 191, 'Администратор не определён.');
        $requestToken = $this->requiredText($requestToken, 191, 'Не удалось определить запрос компенсации.');
        $reason = $this->requiredText($reason, 500, 'Укажите причину компенсации.');
        $amount = $this->amount($amountValue);
        $original = $this->resolveOriginalEntry($operationRef);

        if ((string)$original['asset_code'] !== self::ASSET_CODE) {
            throw new InvalidArgumentException('Компенсация доступна только для MGW Coins.');
        }
        if (trim((string)($original['legacy_user_id'] ?? '')) === '') {
            throw new InvalidArgumentException('У исходной операции нет runtime-пользователя для безопасной компенсации.');
        }
        if ((string)$original['category'] === 'admin_compensation'
            || (string)$original['source_type'] === 'admin_compensation') {
            throw new InvalidArgumentException('Нельзя создавать компенсацию поверх другой компенсации.');
        }

        $existing = $this->byRequestToken($requestToken);
        if ($existing !== null) {
            $this->assertSameRequest($existing, $original, $amount, $reason, $actorRef);
            if ((string)$existing['status_code'] === self::STATUS_PENDING_APPLY) {
                return $this->apply($existing);
            }
            return $this->publicCompensation($existing);
        }

        $requiresConfirmation = $amount >= self::LARGE_AMOUNT_THRESHOLD;
        $now = $this->now();
        $compensationId = 'cmp_' . substr(hash('sha256', $requestToken), 0, 32);
        $ledgerOperationKey = 'admin:compensation:' . substr(hash('sha256', $compensationId), 0, 40);
        $status = $requiresConfirmation ? self::STATUS_PENDING_CONFIRMATION : self::STATUS_PENDING_APPLY;

        $params = [
            'compensation_id' => $compensationId,
            'request_token' => $requestToken,
            'original_entry_id' => (string)$original['entry_id'],
            'original_operation_key' => (string)$original['idempotency_key'],
            'original_entry_sha256' => (string)$original['entry_sha256'],
            'original_category' => (string)$original['category'],
            'original_source_type' => (string)$original['source_type'],
            'original_source_ref' => $this->nullable((string)($original['source_ref'] ?? '')),
            'account_ref' => (string)$original['account_ref'],
            'mgw_id' => $this->nullable((string)($original['mgw_id'] ?? '')),
            'legacy_user_id' => $this->nullable((string)($original['legacy_user_id'] ?? '')),
            'asset_code' => (string)$original['asset_code'],
            'amount' => $amount,
            'reason' => $reason,
            'requires_second_confirmation' => $requiresConfirmation ? 1 : 0,
            'status_code' => $status,
            'requested_by_ref' => $actorRef,
            'ledger_operation_key' => $ledgerOperationKey,
            'requested_at_utc' => $now,
            'updated_at_utc' => $now,
        ];

        if ($this->database->driver() === 'sqlite') {
            $this->database->execute(
                'INSERT OR IGNORE INTO mgw_compensations (
                    compensation_id,request_token,original_entry_id,original_operation_key,
                    original_entry_sha256,original_category,original_source_type,original_source_ref,
                    account_ref,mgw_id,legacy_user_id,asset_code,amount,reason,
                    requires_second_confirmation,status_code,requested_by_ref,confirmed_by_ref,
                    ledger_operation_key,ledger_entry_id,available_before,available_after,
                    requested_at_utc,confirmed_at_utc,applied_at_utc,updated_at_utc
                 ) VALUES (
                    :compensation_id,:request_token,:original_entry_id,:original_operation_key,
                    :original_entry_sha256,:original_category,:original_source_type,:original_source_ref,
                    :account_ref,:mgw_id,:legacy_user_id,:asset_code,:amount,:reason,
                    :requires_second_confirmation,:status_code,:requested_by_ref,NULL,
                    :ledger_operation_key,NULL,NULL,NULL,
                    :requested_at_utc,NULL,NULL,:updated_at_utc
                 )',
                $params
            );
        } else {
            $this->database->execute(
                'INSERT IGNORE INTO mgw_compensations (
                    compensation_id,request_token,original_entry_id,original_operation_key,
                    original_entry_sha256,original_category,original_source_type,original_source_ref,
                    account_ref,mgw_id,legacy_user_id,asset_code,amount,reason,
                    requires_second_confirmation,status_code,requested_by_ref,confirmed_by_ref,
                    ledger_operation_key,ledger_entry_id,available_before,available_after,
                    requested_at_utc,confirmed_at_utc,applied_at_utc,updated_at_utc
                 ) VALUES (
                    :compensation_id,:request_token,:original_entry_id,:original_operation_key,
                    :original_entry_sha256,:original_category,:original_source_type,:original_source_ref,
                    :account_ref,:mgw_id,:legacy_user_id,:asset_code,:amount,:reason,
                    :requires_second_confirmation,:status_code,:requested_by_ref,NULL,
                    :ledger_operation_key,NULL,NULL,NULL,
                    :requested_at_utc,NULL,NULL,:updated_at_utc
                 )',
                $params
            );
        }

        $record = $this->byRequestToken($requestToken);
        if ($record === null) throw new RuntimeException('Не удалось сохранить запрос компенсации.');
        $this->assertSameRequest($record, $original, $amount, $reason, $actorRef);

        if ($requiresConfirmation) return $this->publicCompensation($record);
        return $this->apply($record);
    }

    public function confirm(string $compensationId, string $actorRef): array
    {
        $compensationId = $this->requiredText($compensationId, 48, 'Компенсация не определена.');
        $actorRef = $this->requiredText($actorRef, 191, 'Администратор не определён.');
        $record = $this->byId($compensationId);
        if ($record === null) throw new InvalidArgumentException('Компенсация не найдена.');

        if ((string)$record['status_code'] === self::STATUS_APPLIED) {
            return $this->publicCompensation($record);
        }

        if ((int)$record['requires_second_confirmation'] !== 1) {
            return $this->apply($record);
        }

        if ((string)$record['status_code'] === self::STATUS_PENDING_CONFIRMATION) {
            $now = $this->now();
            $this->database->execute(
                'UPDATE mgw_compensations
                 SET status_code=:next_status,
                     confirmed_by_ref=:confirmed_by_ref,
                     confirmed_at_utc=:confirmed_at_utc,
                     updated_at_utc=:updated_at_utc
                 WHERE compensation_id=:compensation_id
                   AND status_code=:expected_status',
                [
                    'next_status' => self::STATUS_PENDING_APPLY,
                    'confirmed_by_ref' => $actorRef,
                    'confirmed_at_utc' => $now,
                    'updated_at_utc' => $now,
                    'compensation_id' => $compensationId,
                    'expected_status' => self::STATUS_PENDING_CONFIRMATION,
                ]
            );
        }

        $record = $this->byId($compensationId);
        if ($record === null) throw new RuntimeException('Компенсация исчезла во время подтверждения.');
        if ((string)$record['status_code'] === self::STATUS_PENDING_CONFIRMATION) {
            throw new RuntimeException('Второе подтверждение не было сохранено.');
        }
        return $this->apply($record);
    }

    public function history(int $limit = 25): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->database->fetchAll(
            "SELECT c.*, u.nickname
             FROM mgw_compensations c
             LEFT JOIN mgw_users u ON u.mgw_id=c.mgw_id
             ORDER BY c.requested_at_utc DESC, c.compensation_id DESC
             LIMIT $limit"
        );
        return array_map(fn(array $row): array => $this->publicCompensation($row), $rows);
    }

    private function apply(array $record): array
    {
        if ((string)$record['status_code'] === self::STATUS_APPLIED) {
            return $this->publicCompensation($record);
        }
        if ((string)$record['status_code'] !== self::STATUS_PENDING_APPLY) {
            throw new RuntimeException('Компенсация ещё не получила необходимое подтверждение.');
        }

        $legacyUserId = trim((string)($record['legacy_user_id'] ?? ''));
        if ($legacyUserId === '') {
            throw new RuntimeException('Runtime-пользователь компенсации не определён.');
        }

        $metadata = [
            'compensation_id' => (string)$record['compensation_id'],
            'original_entry_id' => (string)$record['original_entry_id'],
            'original_operation_key' => (string)$record['original_operation_key'],
            'reason' => (string)$record['reason'],
            'requested_by_ref' => (string)$record['requested_by_ref'],
            'confirmed_by_ref' => $this->nullable((string)($record['confirmed_by_ref'] ?? '')),
        ];

        $runtimeResult = $this->runtimeStorage->transaction(function (array &$data) use (
            $record,
            $legacyUserId,
            $metadata
        ): array {
            if (!isset($data['users']) || !is_array($data['users'])) $data['users'] = [];
            if (!isset($data['transactions']) || !is_array($data['transactions'])) $data['transactions'] = [];

            $storageKey = $this->runtimeUserStorageKey($data['users'], $legacyUserId);
            $user =& $data['users'][$storageKey];
            UnifiedBalanceRuntimeState::ensureUser($user);
            $runtimeBefore = (int)($user[UnifiedBalanceRuntimeState::FIELD] ?? -1);
            if ($runtimeBefore < 0) throw new RuntimeException('Runtime-баланс пользователя некорректен.');

            $existingRuntime = null;
            foreach (array_reverse($data['transactions']) as $transaction) {
                if (!is_array($transaction)) continue;
                if ((string)($transaction['category'] ?? '') !== 'admin_compensation') continue;
                if ((string)($transaction['compensation_id'] ?? '') !== (string)$record['compensation_id']) continue;
                $existingRuntime = $transaction;
                break;
            }

            $existingLedger = $this->database->fetchAll(
                'SELECT entry_id,available_before,available_after
                 FROM mgw_ledger_entries
                 WHERE idempotency_key=:operation_key
                 LIMIT 2',
                ['operation_key'=>(string)$record['ledger_operation_key']]
            );
            if (count($existingLedger) > 1) {
                throw new RuntimeException('Компенсационная ledger-операция неоднозначна.');
            }

            if ($existingLedger === []) {
                $databaseBalance = $this->ledger->getBalance(
                    (string)$record['account_ref'],
                    (string)$record['asset_code']
                );
                if (!is_array($databaseBalance)) {
                    throw new RuntimeException('Канонический баланс пользователя недоступен.');
                }
                $databaseBefore = (int)($databaseBalance['available_amount'] ?? -1);
                if ($databaseBefore < 0 || $runtimeBefore !== $databaseBefore) {
                    throw new RuntimeException('Баланс изменился. Обновите данные и повторите компенсацию.');
                }
            }

            $posted = $this->ledger->postAvailableDelta([
                'operation_key' => (string)$record['ledger_operation_key'],
                'account_ref' => (string)$record['account_ref'],
                'mgw_id' => $this->nullable((string)($record['mgw_id'] ?? '')),
                'legacy_user_id' => $legacyUserId,
                'asset_code' => (string)$record['asset_code'],
                'available_delta' => (int)$record['amount'],
                'category' => 'admin_compensation',
                'source_type' => 'admin_compensation',
                'source_ref' => (string)$record['original_entry_id'],
                'metadata' => $metadata,
            ]);

            $entryId = trim((string)($posted['entry_id'] ?? ''));
            if ($entryId === '') throw new RuntimeException('Компенсационная запись журнала не создана.');
            $ledgerRows = $this->database->fetchAll(
                'SELECT available_before,available_after
                 FROM mgw_ledger_entries WHERE entry_id=:entry_id',
                ['entry_id'=>$entryId]
            );
            if (count($ledgerRows) !== 1 || !is_array($ledgerRows[0])) {
                throw new RuntimeException('Компенсационная запись журнала не подтверждена.');
            }

            $ledgerBefore = (int)$ledgerRows[0]['available_before'];
            $ledgerAfter = (int)$ledgerRows[0]['available_after'];

            if ($existingRuntime === null) {
                // Fresh path: runtime and ledger started in parity. Recovery path:
                // the ledger may already exist because a previous JSON commit failed;
                // add the compensation to the current runtime value exactly once.
                $runtimeAfter = $existingLedger === []
                    ? $ledgerAfter
                    : $runtimeBefore + (int)$record['amount'];
                if ($runtimeAfter < $runtimeBefore) {
                    throw new RuntimeException('Runtime-баланс переполнен.');
                }
                $user[UnifiedBalanceRuntimeState::FIELD] = $runtimeAfter;
                $data['transactions'][] = [
                    'id'=>'cmp-runtime-' . substr(hash('sha256', (string)$record['compensation_id']), 0, 24),
                    'type'=>'balance_change',
                    'category'=>'admin_compensation',
                    'user_id'=>$storageKey,
                    'telegram_id'=>(string)($user['telegram_id'] ?? $user['id'] ?? $storageKey),
                    'mgw_id'=>(string)($record['mgw_id'] ?? $user['mgw_id'] ?? ''),
                    'amount'=>(int)$record['amount'],
                    'balance_before'=>$runtimeBefore,
                    'balance_after'=>$runtimeAfter,
                    'actor_ref'=>(string)$record['requested_by_ref'],
                    'reason'=>(string)$record['reason'],
                    'request_token'=>(string)$record['request_token'],
                    'compensation_id'=>(string)$record['compensation_id'],
                    'original_entry_id'=>(string)$record['original_entry_id'],
                    'ledger_entry_id'=>$entryId,
                    'description'=>'Административная компенсация по исходной операции',
                    'created_at'=>$this->nowIso(),
                ];
            } else {
                if ((int)($existingRuntime['amount'] ?? 0) !== (int)$record['amount']
                    || (string)($existingRuntime['original_entry_id'] ?? '') !== (string)$record['original_entry_id']) {
                    throw new RuntimeException('Runtime-аудит компенсации не совпадает с запросом.');
                }
            }

            return [
                'ledger_entry_id'=>$entryId,
                'ledger_available_before'=>$ledgerBefore,
                'ledger_available_after'=>$ledgerAfter,
                'ledger_replayed'=>!empty($posted['replayed']),
            ];
        });

        $entryId = trim((string)($runtimeResult['ledger_entry_id'] ?? ''));
        if ($entryId === '') throw new RuntimeException('Компенсационная запись журнала не подтверждена.');

        // Mark applied only after the runtime-source transaction committed.
        // If this DB update fails, a retry sees the stable ledger/runtime audit
        // and safely completes this row without a second credit.
        $now = $this->now();
        $this->database->execute(
            'UPDATE mgw_compensations
             SET status_code=:status_code,
                 ledger_entry_id=:ledger_entry_id,
                 available_before=:available_before,
                 available_after=:available_after,
                 applied_at_utc=COALESCE(applied_at_utc,:applied_at_utc),
                 updated_at_utc=:updated_at_utc
             WHERE compensation_id=:compensation_id',
            [
                'status_code'=>self::STATUS_APPLIED,
                'ledger_entry_id'=>$entryId,
                'available_before'=>(int)$runtimeResult['ledger_available_before'],
                'available_after'=>(int)$runtimeResult['ledger_available_after'],
                'applied_at_utc'=>$now,
                'updated_at_utc'=>$now,
                'compensation_id'=>(string)$record['compensation_id'],
            ]
        );

        $updated = $this->byId((string)$record['compensation_id']);
        if ($updated === null || (string)$updated['status_code'] !== self::STATUS_APPLIED) {
            throw new RuntimeException('Компенсация проведена по журналу, но аудит не подтверждён.');
        }
        return $this->publicCompensation($updated);
    }

    private function runtimeUserStorageKey(array $users, string $legacyUserId): string
    {
        $matches = [];
        foreach ($users as $key=>$user) {
            if (!is_array($user)) continue;
            $candidate = trim((string)($user['id'] ?? $key));
            if ($candidate === $legacyUserId || (string)$key === $legacyUserId) {
                $matches[] = (string)$key;
            }
        }
        $matches = array_values(array_unique($matches));
        if (count($matches) !== 1) {
            throw new RuntimeException(
                $matches === []
                    ? 'Runtime-пользователь компенсации не найден.'
                    : 'Runtime-пользователь компенсации неоднозначен.'
            );
        }
        return $matches[0];
    }

    private function nowIso(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
    }

    private function resolveOriginalEntry(string $operationRef): array
    {
        $operationRef = $this->requiredText($operationRef, 191, 'Укажите ID исходной операции.');
        $rows = $this->database->fetchAll(
            'SELECT l.*, u.nickname
             FROM mgw_ledger_entries l
             LEFT JOIN mgw_users u ON u.mgw_id=l.mgw_id
             WHERE l.entry_id=:entry_ref OR l.idempotency_key=:operation_ref
             ORDER BY l.ledger_sequence DESC
             LIMIT 3',
            ['entry_ref' => $operationRef, 'operation_ref' => $operationRef]
        );
        if ($rows === []) throw new InvalidArgumentException('Исходная операция не найдена.');
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Ссылка на исходную операцию неоднозначна.');
        }
        return $rows[0];
    }

    private function publicOperation(array $entry): array
    {
        $balance = $this->ledger->getBalance((string)$entry['account_ref'], (string)$entry['asset_code']);
        $appliedTotal = (int)$this->database->fetchValue(
            'SELECT COALESCE(SUM(amount),0)
             FROM mgw_compensations
             WHERE original_entry_id=:entry_id AND status_code=:status_code',
            ['entry_id' => (string)$entry['entry_id'], 'status_code' => self::STATUS_APPLIED]
        );
        return [
            'entry_id' => (string)$entry['entry_id'],
            'operation_key' => (string)$entry['idempotency_key'],
            'account_ref' => (string)$entry['account_ref'],
            'mgw_id' => $this->nullable((string)($entry['mgw_id'] ?? '')),
            'nickname' => $this->nullable((string)($entry['nickname'] ?? '')),
            'asset_code' => (string)$entry['asset_code'],
            'available_delta' => (int)$entry['available_delta'],
            'category' => (string)$entry['category'],
            'source_type' => (string)$entry['source_type'],
            'source_ref' => $this->nullable((string)($entry['source_ref'] ?? '')),
            'created_at_utc' => (string)$entry['created_at_utc'],
            'current_available_amount' => (int)($balance['available_amount'] ?? 0),
            'current_reserved_amount' => (int)($balance['reserved_amount'] ?? 0),
            'applied_compensation_total' => $appliedTotal,
        ];
    }

    private function publicCompensation(array $row): array
    {
        $nickname = $this->nullable((string)($row['nickname'] ?? ''));
        if ($nickname === null && ($row['mgw_id'] ?? null) !== null) {
            $users = $this->database->fetchAll(
                'SELECT nickname FROM mgw_users WHERE mgw_id=:mgw_id',
                ['mgw_id' => (string)$row['mgw_id']]
            );
            if (count($users) === 1 && is_array($users[0])) {
                $nickname = $this->nullable((string)($users[0]['nickname'] ?? ''));
            }
        }

        return [
            'compensation_id' => (string)$row['compensation_id'],
            'original_entry_id' => (string)$row['original_entry_id'],
            'original_operation_key' => (string)$row['original_operation_key'],
            'account_ref' => (string)$row['account_ref'],
            'mgw_id' => $this->nullable((string)($row['mgw_id'] ?? '')),
            'nickname' => $nickname,
            'asset_code' => (string)$row['asset_code'],
            'amount' => (int)$row['amount'],
            'reason' => (string)$row['reason'],
            'requires_second_confirmation' => (int)$row['requires_second_confirmation'] === 1,
            'status' => (string)$row['status_code'],
            'requested_by_ref' => (string)$row['requested_by_ref'],
            'confirmed_by_ref' => $this->nullable((string)($row['confirmed_by_ref'] ?? '')),
            'ledger_operation_key' => (string)$row['ledger_operation_key'],
            'ledger_entry_id' => $this->nullable((string)($row['ledger_entry_id'] ?? '')),
            'available_before' => $row['available_before'] === null ? null : (int)$row['available_before'],
            'available_after' => $row['available_after'] === null ? null : (int)$row['available_after'],
            'requested_at_utc' => (string)$row['requested_at_utc'],
            'confirmed_at_utc' => $this->nullable((string)($row['confirmed_at_utc'] ?? '')),
            'applied_at_utc' => $this->nullable((string)($row['applied_at_utc'] ?? '')),
        ];
    }

    private function byId(string $compensationId): ?array
    {
        $rows = $this->database->fetchAll(
            'SELECT * FROM mgw_compensations WHERE compensation_id=:compensation_id',
            ['compensation_id' => $compensationId]
        );
        return count($rows) === 1 && is_array($rows[0]) ? $rows[0] : null;
    }

    private function byRequestToken(string $requestToken): ?array
    {
        $rows = $this->database->fetchAll(
            'SELECT * FROM mgw_compensations WHERE request_token=:request_token',
            ['request_token' => $requestToken]
        );
        return count($rows) === 1 && is_array($rows[0]) ? $rows[0] : null;
    }

    private function assertSameRequest(
        array $existing,
        array $original,
        int $amount,
        string $reason,
        string $actorRef
    ): void {
        if ((string)$existing['original_entry_id'] !== (string)$original['entry_id']
            || (int)$existing['amount'] !== $amount
            || (string)$existing['reason'] !== $reason
            || (string)$existing['requested_by_ref'] !== $actorRef) {
            throw new RuntimeException('Токен запроса уже использован для другой компенсации.');
        }
    }

    private function amount(mixed $value): int
    {
        if (is_int($value)) $amount = $value;
        elseif (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) $amount = (int)$value;
        else throw new InvalidArgumentException('Укажите целую сумму компенсации.');

        if ($amount < 1) throw new InvalidArgumentException('Сумма компенсации должна быть больше нуля.');
        if ($amount > self::MAX_AMOUNT) {
            throw new InvalidArgumentException('Сумма компенсации превышает лимит ' . number_format(self::MAX_AMOUNT, 0, '.', ' ') . '.');
        }
        return $amount;
    }

    private function requiredText(string $value, int $max, string $message): string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');
        if ($value === '') throw new InvalidArgumentException($message);
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
