<?php
declare(strict_types=1);

final class CompensationService
{
    public const ASSET_CODE = 'mgw_coin';
    public const MIN_AMOUNT = 1;
    public const LARGE_AMOUNT_THRESHOLD = 50000;
    public const MAX_AMOUNT = 250000;
    public const HISTORY_LIMIT = 50;

    public function __construct(
        private DatabaseConnectionInterface $database,
        private LedgerWriteService $ledger,
        private StorageTransactionInterface $runtimeStorage
    ) {}

    public function policy(): array
    {
        return [
            'asset_code' => self::ASSET_CODE,
            'min_amount' => self::MIN_AMOUNT,
            'large_amount_threshold' => self::LARGE_AMOUNT_THRESHOLD,
            'max_amount' => self::MAX_AMOUNT,
            'reason_min_length' => 3,
            'reason_max_length' => 500,
        ];
    }

    public function lookupOriginal(string $reference): array
    {
        $reference = $this->text($reference, 191);
        if ($reference === '') {
            throw new InvalidArgumentException('Укажите ID исходной операции или записи ledger.');
        }

        $rows = $this->database->fetchAll(
            'SELECT entry_id,idempotency_key,account_ref,mgw_id,legacy_user_id,asset_code,
                    available_delta,reserved_delta,available_before,available_after,
                    reserved_before,reserved_after,category,source_type,source_ref,
                    entry_sha256,created_at_utc
             FROM mgw_ledger_entries
             WHERE entry_id=:reference OR idempotency_key=:reference
             ORDER BY ledger_sequence DESC LIMIT 2',
            ['reference'=>$reference]
        );
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new InvalidArgumentException('Исходная операция не найдена или неоднозначна.');
        }

        $row = $rows[0];
        if ((string)($row['asset_code'] ?? '') !== self::ASSET_CODE) {
            throw new InvalidArgumentException('Компенсация доступна только для единого баланса MGW Coin.');
        }
        if ((string)($row['category'] ?? '') === 'admin_compensation') {
            throw new InvalidArgumentException('Нельзя использовать другую компенсацию как исходную операцию.');
        }
        if (trim((string)($row['legacy_user_id'] ?? '')) === '') {
            throw new InvalidArgumentException('У исходной операции нет активного runtime-пользователя для безопасной компенсации.');
        }

        return $this->publicOriginal($row);
    }

    public function request(
        string $originalReference,
        int $amount,
        string $reason,
        string $actorRef,
        string $requestToken
    ): array {
        $original = $this->lookupOriginal($originalReference);
        $amount = $this->amount($amount);
        $reason = $this->reason($reason);
        $actorRef = $this->actor($actorRef);
        $requestToken = $this->requestToken($requestToken);

        $existing = $this->findByRequestToken($requestToken);
        if ($existing !== null) {
            $this->assertReplayInput($existing, $original, $amount, $reason);
            if ((string)$existing['status_code'] === 'pending_confirmation') {
                $result = $this->publicCompensation($existing);
                $result['replayed'] = true;
                return $result;
            }
            return $this->apply($existing, $actorRef, true);
        }

        $now = $this->now();
        $requiresSecond = $amount >= self::LARGE_AMOUNT_THRESHOLD;
        $compensationId = 'cmp-' . substr(hash('sha256', $requestToken), 0, 32);
        $ledgerOperationKey = 'admin:compensation:' . substr(hash('sha256', $requestToken), 0, 48);
        $status = $requiresSecond ? 'pending_confirmation' : 'applying';

        $this->database->execute(
            'INSERT INTO mgw_compensations (
                compensation_id,request_token,original_entry_id,original_operation_key,original_entry_sha256,
                original_category,original_source_type,original_source_ref,
                account_ref,mgw_id,legacy_user_id,asset_code,amount,reason,
                requires_second_confirmation,status_code,requested_by_ref,confirmed_by_ref,
                ledger_operation_key,ledger_entry_id,available_before,available_after,
                requested_at_utc,confirmed_at_utc,applied_at_utc,updated_at_utc
             ) VALUES (
                :compensation_id,:request_token,:original_entry_id,:original_operation_key,:original_entry_sha256,
                :original_category,:original_source_type,:original_source_ref,
                :account_ref,:mgw_id,:legacy_user_id,:asset_code,:amount,:reason,
                :requires_second_confirmation,:status_code,:requested_by_ref,NULL,
                :ledger_operation_key,NULL,NULL,NULL,
                :requested_at_utc,NULL,NULL,:updated_at_utc
             )',
            [
                'compensation_id'=>$compensationId,
                'request_token'=>$requestToken,
                'original_entry_id'=>$original['entry_id'],
                'original_operation_key'=>$original['operation_key'],
                'original_entry_sha256'=>$original['entry_sha256'],
                'original_category'=>$original['category'],
                'original_source_type'=>$original['source_type'],
                'original_source_ref'=>$original['source_ref'],
                'account_ref'=>$original['account_ref'],
                'mgw_id'=>$original['mgw_id'],
                'legacy_user_id'=>$original['legacy_user_id'],
                'asset_code'=>self::ASSET_CODE,
                'amount'=>$amount,
                'reason'=>$reason,
                'requires_second_confirmation'=>$requiresSecond ? 1 : 0,
                'status_code'=>$status,
                'requested_by_ref'=>$actorRef,
                'ledger_operation_key'=>$ledgerOperationKey,
                'requested_at_utc'=>$now,
                'updated_at_utc'=>$now,
            ]
        );

        $row = $this->findById($compensationId);
        if ($row === null) throw new RuntimeException('Compensation audit row could not be created.');
        if ($requiresSecond) return $this->publicCompensation($row);
        return $this->apply($row, $actorRef, false);
    }

    public function confirm(string $compensationId, string $actorRef): array
    {
        $compensationId = $this->text($compensationId, 48);
        if ($compensationId === '') throw new InvalidArgumentException('Компенсация не указана.');
        $actorRef = $this->actor($actorRef);

        $row = $this->findById($compensationId);
        if ($row === null) throw new InvalidArgumentException('Компенсация не найдена.');
        if ((int)$row['requires_second_confirmation'] !== 1) {
            return $this->apply($row, $actorRef, true);
        }
        if ((string)$row['status_code'] === 'applied') {
            $result = $this->publicCompensation($row);
            $result['replayed'] = true;
            return $result;
        }

        if ((string)$row['status_code'] === 'pending_confirmation') {
            $now = $this->now();
            $this->database->execute(
                'UPDATE mgw_compensations
                 SET status_code=:status,confirmed_by_ref=:actor,confirmed_at_utc=:confirmed,updated_at_utc=:updated
                 WHERE compensation_id=:id AND status_code=:expected',
                [
                    'status'=>'applying','actor'=>$actorRef,'confirmed'=>$now,'updated'=>$now,
                    'id'=>$compensationId,'expected'=>'pending_confirmation',
                ]
            );
            $row = $this->findById($compensationId) ?? $row;
        }

        return $this->apply($row, $actorRef, false);
    }

    public function history(int $limit = self::HISTORY_LIMIT): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->database->fetchAll(
            'SELECT * FROM mgw_compensations
             ORDER BY requested_at_utc DESC, compensation_id DESC
             LIMIT ' . $limit
        );
        return array_values(array_map(fn(array $row): array => $this->publicCompensation($row), $rows));
    }

    private function apply(array $row, string $actorRef, bool $replayedRequest): array
    {
        if ((string)$row['status_code'] === 'applied') {
            $result = $this->publicCompensation($row);
            $result['replayed'] = true;
            return $result;
        }
        if ((int)$row['requires_second_confirmation'] === 1
            && trim((string)($row['confirmed_at_utc'] ?? '')) === '') {
            return $this->publicCompensation($row);
        }

        $amount = (int)$row['amount'];
        $databaseBalance = $this->ledger->getBalance((string)$row['account_ref'], self::ASSET_CODE);
        if (!is_array($databaseBalance)) {
            throw new RuntimeException('Canonical balance is unavailable for compensation.');
        }
        $runtime = $this->applyRuntimeBalance(
            (string)$row['legacy_user_id'],
            $amount,
            (int)$databaseBalance['available_amount'],
            (string)$row['request_token'],
            (string)$row['compensation_id'],
            (string)$row['original_entry_id'],
            (string)$row['reason'],
            (string)$row['requested_by_ref']
        );

        $ledgerResult = $this->ledger->postAvailableDelta([
            'operation_key'=>(string)$row['ledger_operation_key'],
            'account_ref'=>(string)$row['account_ref'],
            'mgw_id'=>$this->nullable((string)($row['mgw_id'] ?? '')),
            'legacy_user_id'=>(string)$row['legacy_user_id'],
            'asset_code'=>self::ASSET_CODE,
            'available_delta'=>$amount,
            'category'=>'admin_compensation',
            'source_type'=>'ledger_entry',
            'source_ref'=>(string)$row['original_entry_id'],
            'metadata'=>[
                'compensation_id'=>(string)$row['compensation_id'],
                'original_entry_id'=>(string)$row['original_entry_id'],
                'original_operation_key'=>(string)$row['original_operation_key'],
                'original_entry_sha256'=>(string)$row['original_entry_sha256'],
                'reason'=>(string)$row['reason'],
                'requested_by_ref'=>(string)$row['requested_by_ref'],
                'confirmed_by_ref'=>$this->nullable((string)($row['confirmed_by_ref'] ?? '')),
            ],
        ]);

        $balance = is_array($ledgerResult['balance'] ?? null) ? $ledgerResult['balance'] : [];
        if ((int)($balance['available_amount'] ?? -1) !== (int)$runtime['balance_after']) {
            throw new RuntimeException('Compensation runtime balance and canonical ledger did not converge.');
        }

        $now = $this->now();
        $this->database->execute(
            'UPDATE mgw_compensations
             SET status_code=:status,ledger_entry_id=:ledger_entry_id,
                 available_before=:available_before,available_after=:available_after,
                 applied_at_utc=COALESCE(applied_at_utc,:applied),updated_at_utc=:updated
             WHERE compensation_id=:id',
            [
                'status'=>'applied',
                'ledger_entry_id'=>(string)$ledgerResult['entry_id'],
                'available_before'=>(int)$runtime['balance_before'],
                'available_after'=>(int)$runtime['balance_after'],
                'applied'=>$now,
                'updated'=>$now,
                'id'=>(string)$row['compensation_id'],
            ]
        );

        $applied = $this->findById((string)$row['compensation_id']);
        if ($applied === null) throw new RuntimeException('Applied compensation audit row is unavailable.');
        $result = $this->publicCompensation($applied);
        $result['replayed'] = $replayedRequest || !empty($runtime['replayed']) || !empty($ledgerResult['replayed']);
        return $result;
    }

    private function applyRuntimeBalance(
        string $legacyUserId,
        int $amount,
        int $databaseAvailableBefore,
        string $requestToken,
        string $compensationId,
        string $originalEntryId,
        string $reason,
        string $actorRef
    ): array {
        return $this->runtimeStorage->transaction(function (array &$data) use (
            $legacyUserId,$amount,$databaseAvailableBefore,$requestToken,$compensationId,$originalEntryId,$reason,$actorRef
        ): array {
            if (!isset($data['users']) || !is_array($data['users'])) $data['users'] = [];
            if (!isset($data['transactions']) || !is_array($data['transactions'])) $data['transactions'] = [];

            foreach (array_reverse($data['transactions']) as $transaction) {
                if (!is_array($transaction)
                    || (string)($transaction['category'] ?? '') !== 'admin_compensation'
                    || (string)($transaction['request_token'] ?? '') !== $requestToken) continue;
                if ((int)($transaction['amount'] ?? 0) !== $amount
                    || (string)($transaction['original_entry_id'] ?? '') !== $originalEntryId) {
                    throw new RuntimeException('Compensation request token was reused with different runtime input.');
                }
                return [
                    'balance_before'=>(int)($transaction['balance_before'] ?? 0),
                    'balance_after'=>(int)($transaction['balance_after'] ?? 0),
                    'replayed'=>true,
                ];
            }

            $storageKey = null;
            foreach ($data['users'] as $key => $user) {
                if (!is_array($user)) continue;
                $candidate = trim((string)($user['id'] ?? $key));
                if ($candidate === $legacyUserId || (string)$key === $legacyUserId) {
                    if ($storageKey !== null) throw new RuntimeException('Runtime compensation user is ambiguous.');
                    $storageKey = (string)$key;
                }
            }
            if ($storageKey === null || !isset($data['users'][$storageKey]) || !is_array($data['users'][$storageKey])) {
                throw new RuntimeException('Runtime compensation user was not found.');
            }

            $user =& $data['users'][$storageKey];
            UnifiedBalanceRuntimeState::ensureUser($user);
            $before = (int)$user[UnifiedBalanceRuntimeState::FIELD];
            if ($before !== $databaseAvailableBefore) {
                throw new RuntimeException('Runtime balance differs from canonical ledger before compensation.');
            }
            if ($before < 0 || $before > PHP_INT_MAX - $amount) {
                throw new RuntimeException('Runtime balance cannot accept compensation safely.');
            }
            $after = $before + $amount;
            $user[UnifiedBalanceRuntimeState::FIELD] = $after;
            $data['transactions'][] = [
                'id'=>'cmp-runtime-' . substr(hash('sha256', $requestToken), 0, 24),
                'type'=>'balance_change',
                'category'=>'admin_compensation',
                'user_id'=>$storageKey,
                'telegram_id'=>(string)($user['telegram_id'] ?? $user['id'] ?? $storageKey),
                'mgw_id'=>(string)($user['mgw_id'] ?? ''),
                'amount'=>$amount,
                'balance_before'=>$before,
                'balance_after'=>$after,
                'actor_ref'=>$actorRef,
                'reason'=>$reason,
                'request_token'=>$requestToken,
                'compensation_id'=>$compensationId,
                'original_entry_id'=>$originalEntryId,
                'description'=>'Административная компенсация по исходной операции',
                'created_at'=>$this->nowIso(),
            ];

            return ['balance_before'=>$before,'balance_after'=>$after,'replayed'=>false];
        });
    }

    private function findByRequestToken(string $requestToken): ?array
    {
        $rows = $this->database->fetchAll(
            'SELECT * FROM mgw_compensations WHERE request_token=:token LIMIT 2',
            ['token'=>$requestToken]
        );
        return count($rows) === 1 && is_array($rows[0]) ? $rows[0] : null;
    }

    private function findById(string $compensationId): ?array
    {
        $rows = $this->database->fetchAll(
            'SELECT * FROM mgw_compensations WHERE compensation_id=:id LIMIT 2',
            ['id'=>$compensationId]
        );
        return count($rows) === 1 && is_array($rows[0]) ? $rows[0] : null;
    }

    private function assertReplayInput(array $existing, array $original, int $amount, string $reason): void
    {
        if ((string)$existing['original_entry_id'] !== (string)$original['entry_id']
            || (int)$existing['amount'] !== $amount
            || (string)$existing['reason'] !== $reason) {
            throw new InvalidArgumentException('Этот идентификатор компенсации уже использован с другими параметрами.');
        }
    }

    private function publicOriginal(array $row): array
    {
        return [
            'entry_id'=>(string)$row['entry_id'],
            'operation_key'=>(string)$row['idempotency_key'],
            'entry_sha256'=>(string)$row['entry_sha256'],
            'account_ref'=>(string)$row['account_ref'],
            'mgw_id'=>$this->nullable((string)($row['mgw_id'] ?? '')),
            'legacy_user_id'=>$this->nullable((string)($row['legacy_user_id'] ?? '')),
            'asset_code'=>(string)$row['asset_code'],
            'available_delta'=>(int)$row['available_delta'],
            'reserved_delta'=>(int)$row['reserved_delta'],
            'available_before'=>(int)$row['available_before'],
            'available_after'=>(int)$row['available_after'],
            'reserved_before'=>(int)$row['reserved_before'],
            'reserved_after'=>(int)$row['reserved_after'],
            'category'=>(string)$row['category'],
            'source_type'=>(string)$row['source_type'],
            'source_ref'=>$this->nullable((string)($row['source_ref'] ?? '')),
            'created_at_utc'=>(string)$row['created_at_utc'],
        ];
    }

    private function publicCompensation(array $row): array
    {
        return [
            'compensation_id'=>(string)$row['compensation_id'],
            'request_token'=>(string)$row['request_token'],
            'original'=>[
                'entry_id'=>(string)$row['original_entry_id'],
                'operation_key'=>(string)$row['original_operation_key'],
                'entry_sha256'=>(string)$row['original_entry_sha256'],
                'category'=>(string)$row['original_category'],
                'source_type'=>(string)$row['original_source_type'],
                'source_ref'=>$this->nullable((string)($row['original_source_ref'] ?? '')),
            ],
            'account_ref'=>(string)$row['account_ref'],
            'mgw_id'=>$this->nullable((string)($row['mgw_id'] ?? '')),
            'legacy_user_id'=>$this->nullable((string)($row['legacy_user_id'] ?? '')),
            'asset_code'=>(string)$row['asset_code'],
            'amount'=>(int)$row['amount'],
            'reason'=>(string)$row['reason'],
            'requires_second_confirmation'=>(int)$row['requires_second_confirmation'] === 1,
            'status'=>(string)$row['status_code'],
            'requested_by_ref'=>(string)$row['requested_by_ref'],
            'confirmed_by_ref'=>$this->nullable((string)($row['confirmed_by_ref'] ?? '')),
            'ledger_operation_key'=>(string)$row['ledger_operation_key'],
            'ledger_entry_id'=>$this->nullable((string)($row['ledger_entry_id'] ?? '')),
            'balance_before'=>$row['available_before'] === null ? null : (int)$row['available_before'],
            'balance_after'=>$row['available_after'] === null ? null : (int)$row['available_after'],
            'requested_at_utc'=>(string)$row['requested_at_utc'],
            'confirmed_at_utc'=>$this->nullable((string)($row['confirmed_at_utc'] ?? '')),
            'applied_at_utc'=>$this->nullable((string)($row['applied_at_utc'] ?? '')),
        ];
    }

    private function amount(int $amount): int
    {
        if ($amount < self::MIN_AMOUNT || $amount > self::MAX_AMOUNT) {
            throw new InvalidArgumentException(
                'Сумма компенсации должна быть от ' . self::MIN_AMOUNT . ' до ' . self::MAX_AMOUNT . ' коинов.'
            );
        }
        return $amount;
    }

    private function reason(string $reason): string
    {
        $reason = $this->text($reason, 500);
        $length = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason);
        if ($length < 3) throw new InvalidArgumentException('Укажите причину компенсации длиной от 3 символов.');
        return $reason;
    }

    private function actor(string $actorRef): string
    {
        $actorRef = $this->text($actorRef, 191);
        if ($actorRef === '') throw new InvalidArgumentException('Admin actor is unavailable.');
        return $actorRef;
    }

    private function requestToken(string $requestToken): string
    {
        $requestToken = $this->text($requestToken, 191);
        if (preg_match('/^admin-compensation:[a-zA-Z0-9:._-]{12,170}$/', $requestToken) !== 1) {
            throw new InvalidArgumentException('Некорректный идентификатор компенсации.');
        }
        return $requestToken;
    }

    private function text(string $value, int $max): string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');
        return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
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

    private function nowIso(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
    }
}
