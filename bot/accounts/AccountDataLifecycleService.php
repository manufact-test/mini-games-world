<?php
declare(strict_types=1);

final class AccountDataLifecycleException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}

final class AccountDataLifecycleService
{
    public const DELETE_GRACE_DAYS = 7;
    public const DEFAULT_EXPORT_RATE_LIMIT_SEC = 86400;
    public const DEFAULT_EXPORT_RETENTION_SEC = 7 * 86400;

    private ?array $schema = null;

    public function __construct(
        private DatabaseConnectionInterface $database,
        private StorageAdapterInterface $runtimeStorage,
        private array $config
    ) {}

    public function snapshot(string $mgwId): array
    {
        $mgwId = $this->mgwId($mgwId);
        $rows = $this->database->fetchAll(
            'SELECT request_id, request_type, request_status, source_type, requested_at_utc,
                    execute_after_utc, completed_at_utc, cancelled_at_utc,
                    artifact_name, artifact_sha256, artifact_size, artifact_expires_at_utc,
                    last_error, updated_at_utc
             FROM mgw_account_data_requests
             WHERE mgw_id=:mgw_id
             ORDER BY requested_at_utc DESC, request_id DESC',
            ['mgw_id'=>$mgwId]
        );

        $deletion = null;
        $export = null;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $type = (string)($row['request_type'] ?? '');
            if ($type === 'deletion' && $deletion === null) $deletion = $this->publicRequest($row);
            if ($type === 'export' && $export === null) $export = $this->publicRequest($row);
            if ($deletion !== null && $export !== null) break;
        }

        return [
            'deletion'=>$deletion,
            'export'=>$export,
            'policy'=>[
                'deletion_grace_days'=>self::DELETE_GRACE_DAYS,
                'export_rate_limit_sec'=>$this->exportRateLimitSec(),
                'export_retention_sec'=>$this->exportRetentionSec(),
            ],
        ];
    }

    public function scheduleDeletion(
        string $mgwId,
        string $sourceType,
        string $sourceRef,
        ?DateTimeImmutable $now = null
    ): array {
        $mgwId = $this->mgwId($mgwId);
        $sourceType = $this->sourceType($sourceType);
        $sourceRef = $this->sourceRef($sourceRef);
        $now = $this->utc($now);
        $requestedAt = $this->format($now);
        $executeAfter = $this->format($now->modify('+' . self::DELETE_GRACE_DAYS . ' days'));

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $mgwId, $sourceType, $sourceRef, $requestedAt, $executeAfter
        ): array {
            $lock = $database->driver() === 'sqlite' ? '' : ' FOR UPDATE';
            $existing = $database->fetchAll(
                "SELECT *
                 FROM mgw_account_data_requests
                 WHERE mgw_id=:mgw_id
                   AND request_type='deletion'
                   AND request_status IN ('scheduled','processing')
                 ORDER BY requested_at_utc DESC
                 LIMIT 1" . $lock,
                ['mgw_id'=>$mgwId]
            );
            if ($existing !== []) return $this->publicRequest($existing[0]);

            $user = $database->fetchAll(
                'SELECT mgw_id, status FROM mgw_users WHERE mgw_id=:mgw_id' . $lock,
                ['mgw_id'=>$mgwId]
            );
            if ($user === []) {
                throw new AccountDataLifecycleException('account_not_found', 'Аккаунт MGW не найден.');
            }
            $status = strtolower(trim((string)($user[0]['status'] ?? 'active')));
            if (in_array($status, ['deletion_finalizing','anonymized'], true)) {
                throw new AccountDataLifecycleException('deletion_locked', 'Удаление аккаунта уже выполняется или завершено.');
            }

            $requestId = $this->requestId();
            $database->execute(
                'INSERT INTO mgw_account_data_requests (
                    request_id, mgw_id, request_type, request_status,
                    source_type, source_ref, requested_at_utc, execute_after_utc,
                    completed_at_utc, cancelled_at_utc,
                    artifact_name, artifact_sha256, artifact_size, artifact_expires_at_utc,
                    last_error, metadata_json, created_at_utc, updated_at_utc
                 ) VALUES (
                    :request_id, :mgw_id, :request_type, :request_status,
                    :source_type, :source_ref, :requested_at, :execute_after,
                    NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL,
                    :created_at, :updated_at
                 )',
                [
                    'request_id'=>$requestId,
                    'mgw_id'=>$mgwId,
                    'request_type'=>'deletion',
                    'request_status'=>'scheduled',
                    'source_type'=>$sourceType,
                    'source_ref'=>$sourceRef,
                    'requested_at'=>$requestedAt,
                    'execute_after'=>$executeAfter,
                    'created_at'=>$requestedAt,
                    'updated_at'=>$requestedAt,
                ]
            );

            return $this->requestById($requestId, $mgwId);
        });
    }

    public function cancelDeletion(string $mgwId, ?DateTimeImmutable $now = null): array
    {
        $mgwId = $this->mgwId($mgwId);
        $nowText = $this->format($this->utc($now));

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use ($mgwId, $nowText): array {
            $lock = $database->driver() === 'sqlite' ? '' : ' FOR UPDATE';
            $rows = $database->fetchAll(
                "SELECT *
                 FROM mgw_account_data_requests
                 WHERE mgw_id=:mgw_id
                   AND request_type='deletion'
                   AND request_status='scheduled'
                 ORDER BY requested_at_utc DESC
                 LIMIT 1" . $lock,
                ['mgw_id'=>$mgwId]
            );
            if ($rows === []) {
                throw new AccountDataLifecycleException('deletion_not_cancellable', 'Нет запланированного удаления, которое можно отменить.');
            }

            $requestId = (string)$rows[0]['request_id'];
            $database->execute(
                "UPDATE mgw_account_data_requests
                 SET request_status='cancelled',
                     cancelled_at_utc=:cancelled_at,
                     updated_at_utc=:updated_at
                 WHERE request_id=:request_id
                   AND mgw_id=:mgw_id
                   AND request_status='scheduled'",
                [
                    'cancelled_at'=>$nowText,
                    'updated_at'=>$nowText,
                    'request_id'=>$requestId,
                    'mgw_id'=>$mgwId,
                ]
            );

            return $this->requestById($requestId, $mgwId);
        });
    }

    public function createExport(
        string $mgwId,
        string $sourceType,
        string $sourceRef,
        ?DateTimeImmutable $now = null
    ): array {
        $mgwId = $this->mgwId($mgwId);
        $sourceType = $this->sourceType($sourceType);
        $sourceRef = $this->sourceRef($sourceRef);
        $now = $this->utc($now);
        $nowText = $this->format($now);
        $cutoff = $this->format($now->modify('-' . $this->exportRateLimitSec() . ' seconds'));

        $requestId = $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $mgwId, $sourceType, $sourceRef, $nowText, $cutoff
        ): string {
            $lock = $database->driver() === 'sqlite' ? '' : ' FOR UPDATE';
            $recent = $database->fetchAll(
                "SELECT request_id, requested_at_utc
                 FROM mgw_account_data_requests
                 WHERE mgw_id=:mgw_id
                   AND request_type='export'
                   AND request_status IN ('processing','ready')
                   AND requested_at_utc>=:cutoff
                 ORDER BY requested_at_utc DESC
                 LIMIT 1" . $lock,
                ['mgw_id'=>$mgwId,'cutoff'=>$cutoff]
            );
            if ($recent !== []) {
                throw new AccountDataLifecycleException(
                    'rate_limited',
                    'Новый экспорт можно запросить позже. Последний архив ещё действует.'
                );
            }

            $requestId = $this->requestId();
            $database->execute(
                'INSERT INTO mgw_account_data_requests (
                    request_id, mgw_id, request_type, request_status,
                    source_type, source_ref, requested_at_utc, execute_after_utc,
                    completed_at_utc, cancelled_at_utc,
                    artifact_name, artifact_sha256, artifact_size, artifact_expires_at_utc,
                    last_error, metadata_json, created_at_utc, updated_at_utc
                 ) VALUES (
                    :request_id, :mgw_id, :request_type, :request_status,
                    :source_type, :source_ref, :requested_at, NULL,
                    NULL, NULL, NULL, NULL, NULL, NULL,
                    NULL, NULL, :created_at, :updated_at
                 )',
                [
                    'request_id'=>$requestId,
                    'mgw_id'=>$mgwId,
                    'request_type'=>'export',
                    'request_status'=>'processing',
                    'source_type'=>$sourceType,
                    'source_ref'=>$sourceRef,
                    'requested_at'=>$nowText,
                    'created_at'=>$nowText,
                    'updated_at'=>$nowText,
                ]
            );
            return $requestId;
        });

        try {
            $package = $this->buildExportPackage($requestId, $mgwId, $now);
            $expires = $this->format($now->modify('+' . $this->exportRetentionSec() . ' seconds'));
            $this->database->execute(
                "UPDATE mgw_account_data_requests
                 SET request_status='ready',
                     completed_at_utc=:completed_at,
                     artifact_name=:artifact_name,
                     artifact_sha256=:artifact_sha256,
                     artifact_size=:artifact_size,
                     artifact_expires_at_utc=:artifact_expires_at,
                     last_error=NULL,
                     metadata_json=:metadata_json,
                     updated_at_utc=:updated_at
                 WHERE request_id=:request_id
                   AND mgw_id=:mgw_id
                   AND request_status='processing'",
                [
                    'completed_at'=>$nowText,
                    'artifact_name'=>basename((string)$package['path']),
                    'artifact_sha256'=>(string)$package['sha256'],
                    'artifact_size'=>(int)$package['size'],
                    'artifact_expires_at'=>$expires,
                    'metadata_json'=>json_encode(
                        ['entries'=>(int)$package['entries'],'table_count'=>(int)$package['table_count']],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    ),
                    'updated_at'=>$nowText,
                    'request_id'=>$requestId,
                    'mgw_id'=>$mgwId,
                ]
            );
        } catch (Throwable $error) {
            $this->database->execute(
                "UPDATE mgw_account_data_requests
                 SET request_status='failed', last_error=:last_error, updated_at_utc=:updated_at
                 WHERE request_id=:request_id AND mgw_id=:mgw_id",
                [
                    'last_error'=>$this->safeError($error->getMessage()),
                    'updated_at'=>$nowText,
                    'request_id'=>$requestId,
                    'mgw_id'=>$mgwId,
                ]
            );
            throw $error;
        }

        return $this->requestById($requestId, $mgwId);
    }

    public function exportPathForUser(string $requestId, string $mgwId, ?DateTimeImmutable $now = null): string
    {
        $mgwId = $this->mgwId($mgwId);
        $requestId = trim($requestId);
        $row = $this->database->fetchAll(
            "SELECT request_id, artifact_name, artifact_expires_at_utc, request_status
             FROM mgw_account_data_requests
             WHERE request_id=:request_id AND mgw_id=:mgw_id AND request_type='export'
             LIMIT 1",
            ['request_id'=>$requestId,'mgw_id'=>$mgwId]
        );
        if ($row === [] || (string)($row[0]['request_status'] ?? '') !== 'ready') {
            throw new AccountDataLifecycleException('export_not_ready', 'Экспорт не найден или ещё не готов.');
        }

        $expires = trim((string)($row[0]['artifact_expires_at_utc'] ?? ''));
        $now = $this->utc($now);
        if ($expires === '' || new DateTimeImmutable($expires, new DateTimeZone('UTC')) <= $now) {
            throw new AccountDataLifecycleException('export_expired', 'Срок хранения этого экспорта истёк.');
        }

        $name = basename((string)($row[0]['artifact_name'] ?? ''));
        if ($name === '') throw new AccountDataLifecycleException('export_missing', 'Файл экспорта недоступен.');
        $path = $this->exportDirectory() . '/' . $name;
        if (!is_file($path)) throw new AccountDataLifecycleException('export_missing', 'Файл экспорта недоступен.');
        return $path;
    }

    public function runRetention(?DateTimeImmutable $now = null, int $limit = 50): array
    {
        $now = $this->utc($now);
        $summary = [
            'deletions_checked'=>0,
            'deletions_completed'=>0,
            'deletions_failed'=>0,
            'exports_expired'=>0,
            'identity_tombstones_expired'=>0,
        ];

        $due = $this->database->fetchAll(
            "SELECT request_id
             FROM mgw_account_data_requests
             WHERE request_type='deletion'
               AND request_status IN ('scheduled','processing')
               AND execute_after_utc IS NOT NULL
               AND execute_after_utc<=:now
             ORDER BY execute_after_utc ASC
             LIMIT " . max(1, min(200, $limit)),
            ['now'=>$this->format($now)]
        );
        foreach ($due as $row) {
            if (!is_array($row)) continue;
            $requestId = trim((string)($row['request_id'] ?? ''));
            if ($requestId === '') continue;
            $summary['deletions_checked']++;
            try {
                if ($this->finalizeDeletion($requestId, $now)) $summary['deletions_completed']++;
            } catch (Throwable $error) {
                $summary['deletions_failed']++;
                $this->database->execute(
                    "UPDATE mgw_account_data_requests
                     SET last_error=:last_error, updated_at_utc=:updated_at
                     WHERE request_id=:request_id AND request_status='processing'",
                    [
                        'last_error'=>$this->safeError($error->getMessage()),
                        'updated_at'=>$this->format($now),
                        'request_id'=>$requestId,
                    ]
                );
                error_log('[MiniGamesWorld account deletion] ' . $requestId . ': ' . $error->getMessage());
            }
        }

        $expired = $this->database->fetchAll(
            "SELECT request_id, artifact_name
             FROM mgw_account_data_requests
             WHERE request_type='export'
               AND request_status='ready'
               AND artifact_expires_at_utc IS NOT NULL
               AND artifact_expires_at_utc<=:now
             ORDER BY artifact_expires_at_utc ASC
             LIMIT " . max(1, min(500, $limit * 4)),
            ['now'=>$this->format($now)]
        );
        foreach ($expired as $row) {
            if (!is_array($row)) continue;
            $requestId = trim((string)($row['request_id'] ?? ''));
            $name = basename((string)($row['artifact_name'] ?? ''));
            if ($name !== '') {
                $path = $this->exportDirectory() . '/' . $name;
                if (is_file($path) && !@unlink($path)) {
                    error_log('[MiniGamesWorld account export] failed to remove expired artifact ' . $name);
                    continue;
                }
            }
            $this->database->execute(
                "UPDATE mgw_account_data_requests
                 SET request_status='expired', updated_at_utc=:updated_at
                 WHERE request_id=:request_id AND request_status='ready'",
                ['updated_at'=>$this->format($now),'request_id'=>$requestId]
            );
            $summary['exports_expired']++;
        }

        if ($this->tableExists('mgw_deleted_identity_tombstones')) {
            $expiredTombstones = $this->database->fetchAll(
                'SELECT provider, provider_subject_hmac
                 FROM mgw_deleted_identity_tombstones
                 WHERE block_until_utc<=:now
                 ORDER BY block_until_utc ASC
                 LIMIT ' . max(1, min(500, $limit * 4)),
                ['now'=>$this->format($now)]
            );
            foreach ($expiredTombstones as $row) {
                if (!is_array($row)) continue;
                $provider = trim((string)($row['provider'] ?? ''));
                $hmac = trim((string)($row['provider_subject_hmac'] ?? ''));
                if ($provider === '' || $hmac === '') continue;
                $this->database->execute(
                    'DELETE FROM mgw_deleted_identity_tombstones
                     WHERE provider=:provider AND provider_subject_hmac=:provider_subject_hmac',
                    ['provider'=>$provider,'provider_subject_hmac'=>$hmac]
                );
                $summary['identity_tombstones_expired']++;
            }
        }

        return $summary;
    }

    private function finalizeDeletion(string $requestId, DateTimeImmutable $now): bool
    {
        $claim = $this->database->transaction(function (DatabaseConnectionInterface $database) use ($requestId, $now): ?array {
            $lock = $database->driver() === 'sqlite' ? '' : ' FOR UPDATE';
            $rows = $database->fetchAll(
                "SELECT *
                 FROM mgw_account_data_requests
                 WHERE request_id=:request_id
                   AND request_type='deletion'
                   AND request_status IN ('scheduled','processing')
                 LIMIT 1" . $lock,
                ['request_id'=>$requestId]
            );
            if ($rows === []) return null;
            $row = $rows[0];
            $mgwId = trim((string)($row['mgw_id'] ?? ''));
            if (!MgwIdGenerator::isValid($mgwId)) {
                throw new RuntimeException('Deletion request has invalid MGW owner.');
            }
            $executeAfter = trim((string)($row['execute_after_utc'] ?? ''));
            if ($executeAfter === '' || new DateTimeImmutable($executeAfter, new DateTimeZone('UTC')) > $now) return null;

            $nowText = $this->format($now);
            $database->execute(
                "UPDATE mgw_account_data_requests
                 SET request_status='processing', last_error=NULL, updated_at_utc=:updated_at
                 WHERE request_id=:request_id
                   AND request_status IN ('scheduled','processing')",
                ['updated_at'=>$nowText,'request_id'=>$requestId]
            );
            $database->execute(
                "UPDATE mgw_users
                 SET status='deletion_finalizing', updated_at_utc=:updated_at
                 WHERE mgw_id=:mgw_id AND status<>'anonymized'",
                ['updated_at'=>$nowText,'mgw_id'=>$mgwId]
            );
            if ($this->tableExists('mgw_sessions')) {
                $database->execute(
                    'UPDATE mgw_sessions
                     SET revoked_at_utc=COALESCE(revoked_at_utc,:revoked_at)
                     WHERE mgw_id=:mgw_id',
                    ['revoked_at'=>$nowText,'mgw_id'=>$mgwId]
                );
            }

            $owner = $this->ownershipRow($mgwId, true);
            return [
                'request_id'=>$requestId,
                'mgw_id'=>$mgwId,
                'legacy_user_id'=>(string)($owner['legacy_user_id'] ?? ''),
                'account_ref'=>(string)($owner['account_ref'] ?? ''),
            ];
        });
        if ($claim === null) return false;

        $tombstone = substr(hash('sha256', 'account-delete|' . $claim['request_id'] . '|' . $claim['mgw_id']), 0, 40);
        $legacyTombstone = 'deleted:' . $tombstone;
        $this->anonymizeRuntime((string)$claim['legacy_user_id'], $legacyTombstone);

        $this->database->transaction(function (DatabaseConnectionInterface $database) use ($claim, $tombstone, $legacyTombstone, $now): void {
            $mgwId = (string)$claim['mgw_id'];
            $requestId = (string)$claim['request_id'];
            $nowText = $this->format($now);
            $anonymousNickname = 'deleted-' . substr($tombstone, 0, 12);
            $playerRef = 'deleted:' . $tombstone;

            if ($this->tableExists('mgw_identities')) {
                $identities = $database->fetchAll(
                    'SELECT identity_id, provider, provider_subject
                     FROM mgw_identities WHERE mgw_id=:mgw_id',
                    ['mgw_id'=>$mgwId]
                );
                foreach ($identities as $identity) {
                    $identityId = (string)($identity['identity_id'] ?? '');
                    $provider = trim((string)($identity['provider'] ?? ''));
                    $providerSubject = trim((string)($identity['provider_subject'] ?? ''));
                    if ($provider !== '' && $providerSubject !== '') {
                        $this->storeDeletedIdentityReplayTombstone(
                            $database,
                            $provider,
                            $providerSubject,
                            $requestId,
                            $now
                        );
                    }
                    $subject = 'deleted:' . substr(hash('sha256', $tombstone . '|identity|' . $identityId), 0, 48);
                    $database->execute(
                        'UPDATE mgw_identities
                         SET provider_subject=:provider_subject,
                             provider_username=NULL
                         WHERE identity_id=:identity_id AND mgw_id=:mgw_id',
                        ['provider_subject'=>$subject,'identity_id'=>$identityId,'mgw_id'=>$mgwId]
                    );
                }
            }

            if ($this->tableExists('mgw_account_ownership')) {
                $newAccountRef = 'deleted:' . substr(hash('sha256', $tombstone . '|account'), 0, 48);
                $database->execute(
                    'UPDATE mgw_account_ownership
                     SET account_ref=:account_ref,
                         legacy_user_id=:legacy_user_id,
                         source_type=:source_type,
                         source_ref=:source_ref,
                         source_sha256=:source_sha256,
                         verified_at_utc=:verified_at
                     WHERE mgw_id=:mgw_id',
                    [
                        'account_ref'=>$newAccountRef,
                        'legacy_user_id'=>$legacyTombstone,
                        'source_type'=>'account_deletion_tombstone',
                        'source_ref'=>$requestId,
                        'source_sha256'=>hash('sha256', $mgwId . '|' . $requestId . '|' . $newAccountRef),
                        'verified_at'=>$nowText,
                        'mgw_id'=>$mgwId,
                    ]
                );
            }

            if ($this->tableExists('mgw_match_players')) {
                $database->execute(
                    "UPDATE mgw_match_players
                     SET player_ref=:player_ref,
                         legacy_user_id=NULL,
                         display_name='Удалённый игрок',
                         updated_at_utc=:updated_at
                     WHERE mgw_id=:mgw_id",
                    ['player_ref'=>$playerRef,'updated_at'=>$nowText,'mgw_id'=>$mgwId]
                );
            }
            if ($this->tableExists('mgw_invites')) {
                $database->execute(
                    "UPDATE mgw_invites
                     SET inviter_ref=CASE WHEN inviter_mgw_id=:mgw_id THEN :player_ref ELSE inviter_ref END,
                         inviter_legacy_user_id=CASE WHEN inviter_mgw_id=:mgw_id THEN NULL ELSE inviter_legacy_user_id END,
                         inviter_name=CASE WHEN inviter_mgw_id=:mgw_id THEN 'Удалённый игрок' ELSE inviter_name END,
                         invitee_ref=CASE WHEN invitee_mgw_id=:mgw_id THEN :player_ref ELSE invitee_ref END,
                         invitee_legacy_user_id=CASE WHEN invitee_mgw_id=:mgw_id THEN NULL ELSE invitee_legacy_user_id END,
                         invitee_name=CASE WHEN invitee_mgw_id=:mgw_id THEN 'Удалённый игрок' ELSE invitee_name END,
                         updated_at_utc=:updated_at
                     WHERE inviter_mgw_id=:mgw_id OR invitee_mgw_id=:mgw_id",
                    ['mgw_id'=>$mgwId,'player_ref'=>$playerRef,'updated_at'=>$nowText]
                );
            }
            if ($this->tableExists('mgw_match_queue')) {
                $database->execute('DELETE FROM mgw_match_queue WHERE mgw_id=:mgw_id', ['mgw_id'=>$mgwId]);
            }
            if ($this->tableExists('mgw_social_relations')) {
                $database->execute(
                    'DELETE FROM mgw_social_relations
                     WHERE user_low_mgw_id=:mgw_id OR user_high_mgw_id=:mgw_id',
                    ['mgw_id'=>$mgwId]
                );
            }
            if ($this->tableExists('mgw_notifications')) {
                $database->execute('DELETE FROM mgw_notifications WHERE mgw_id=:mgw_id', ['mgw_id'=>$mgwId]);
            }
            if ($this->tableExists('mgw_equipped_items')) {
                $database->execute('DELETE FROM mgw_equipped_items WHERE mgw_id=:mgw_id', ['mgw_id'=>$mgwId]);
            }
            if ($this->tableExists('mgw_sessions')) {
                $database->execute('DELETE FROM mgw_sessions WHERE mgw_id=:mgw_id', ['mgw_id'=>$mgwId]);
            }
            if ($this->tableExists('mgw_devices')) {
                $database->execute('DELETE FROM mgw_devices WHERE mgw_id=:mgw_id', ['mgw_id'=>$mgwId]);
            }

            $database->execute(
                "UPDATE mgw_users
                 SET status='anonymized',
                     nickname=:nickname,
                     display_name='Удалённый игрок',
                     username=NULL,
                     avatar_provider=NULL,
                     avatar_external_ref=NULL,
                     avatar_storage_key=NULL,
                     avatar_mime_type=NULL,
                     avatar_width=NULL,
                     avatar_height=NULL,
                     equipped_avatar_item_id=NULL,
                     preferred_locale=NULL,
                     updated_at_utc=:updated_at
                 WHERE mgw_id=:mgw_id",
                ['nickname'=>$anonymousNickname,'updated_at'=>$nowText,'mgw_id'=>$mgwId]
            );
            $database->execute(
                "UPDATE mgw_account_data_requests
                 SET request_status='completed',
                     completed_at_utc=:completed_at,
                     last_error=NULL,
                     metadata_json=:metadata_json,
                     updated_at_utc=:updated_at
                 WHERE request_id=:request_id
                   AND request_status='processing'",
                [
                    'completed_at'=>$nowText,
                    'metadata_json'=>json_encode(
                        [
                            'anonymized'=>true,
                            'financial_audit_preserved'=>true,
                            'match_audit_preserved'=>true,
                            'provider_identity_released'=>true,
                        ],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    ),
                    'updated_at'=>$nowText,
                    'request_id'=>$requestId,
                ]
            );
        });

        return true;
    }

    private function anonymizeRuntime(string $legacyUserId, string $tombstone): void
    {
        $legacyUserId = trim($legacyUserId);
        if ($legacyUserId === '') return;

        $this->runtimeStorage->transaction(function (array &$data) use ($legacyUserId, $tombstone): void {
            if (isset($data['games']) && is_array($data['games'])) {
                foreach ($data['games'] as &$game) {
                    if (!is_array($game)) continue;
                    if (isset($game['player_names']) && is_array($game['player_names'])
                        && array_key_exists($legacyUserId, $game['player_names'])) {
                        $game['player_names'][$legacyUserId] = 'Удалённый игрок';
                    }
                }
                unset($game);
            }
            if (isset($data['invites']) && is_array($data['invites'])) {
                foreach ($data['invites'] as &$invite) {
                    if (!is_array($invite)) continue;
                    $inviter = (string)($invite['inviter_id'] ?? $invite['inviter_user_id'] ?? '');
                    $invitee = (string)($invite['invitee_id'] ?? $invite['invitee_user_id'] ?? '');
                    if ($inviter === $legacyUserId && array_key_exists('inviter_name', $invite)) {
                        $invite['inviter_name'] = 'Удалённый игрок';
                    }
                    if ($invitee === $legacyUserId && array_key_exists('invitee_name', $invite)) {
                        $invite['invitee_name'] = 'Удалённый игрок';
                    }
                }
                unset($invite);
            }

            // Keep rollback financial/support/order/payment/system history byte-stable.
            // Only realtime/social identity projections are eligible for runtime
            // anonymization; broad recursive replacement would corrupt audit data.
            if (isset($data['games']) && is_array($data['games'])) {
                $data['games'] = $this->replaceRuntimeIdentity($data['games'], $legacyUserId, $tombstone);
            }
            if (isset($data['invites']) && is_array($data['invites'])) {
                $data['invites'] = $this->replaceRuntimeIdentity($data['invites'], $legacyUserId, $tombstone);
            }
            if (isset($data['notifications']) && is_array($data['notifications'])) {
                $data['notifications'] = array_values(array_filter(
                    $data['notifications'],
                    static fn(mixed $notification): bool => !is_array($notification)
                        || (string)($notification['user_id'] ?? '') !== $legacyUserId
                ));
                $data['notifications'] = $this->replaceRuntimeIdentity(
                    $data['notifications'],
                    $legacyUserId,
                    $tombstone
                );
            }
            if (isset($data['queue']) && is_array($data['queue'])) {
                $data['queue'] = array_values(array_filter(
                    $data['queue'],
                    static fn(mixed $item): bool => !is_array($item)
                        || (string)($item['user_id'] ?? '') !== $legacyUserId
                ));
            }

            if (isset($data['users']) && is_array($data['users'])) {
                unset($data['users'][$legacyUserId]);
            }
        });
    }

    private function replaceRuntimeIdentity(mixed $value, string $legacyUserId, string $tombstone): mixed
    {
        if (!is_array($value)) {
            if (!is_string($value)) return $value;
            return match ($value) {
                $legacyUserId => $tombstone,
                'legacy:' . $legacyUserId => 'legacy:' . $tombstone,
                'telegram:' . $legacyUserId => 'telegram:' . $tombstone,
                default => $value,
            };
        }

        $result = [];
        foreach ($value as $key => $item) {
            $newKey = is_string($key)
                ? match ($key) {
                    $legacyUserId => $tombstone,
                    'legacy:' . $legacyUserId => 'legacy:' . $tombstone,
                    'telegram:' . $legacyUserId => 'telegram:' . $tombstone,
                    default => $key,
                }
                : $key;
            $result[$newKey] = $this->replaceRuntimeIdentity($item, $legacyUserId, $tombstone);
        }
        return $result;
    }

    private function buildExportPackage(string $requestId, string $mgwId, DateTimeImmutable $now): array
    {
        $records = $this->collectUserRows($mgwId);
        $entries = [];
        $entries['data.json'] = json_encode(
            [
                'format'=>'MGW account data export v1',
                'generated_at_utc'=>$now->format(DATE_ATOM),
                'mgw_id'=>$mgwId,
                'tables'=>$records,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";

        $counts = [];
        foreach ($records as $table => $rows) {
            $counts[$table] = count($rows);
            if ($rows === []) continue;
            $entries['csv/' . $table . '.csv'] = $this->csv($rows);
        }
        $entries['index.html'] = $this->htmlSummary($mgwId, $now, $counts);
        $imageEntries = $this->imageEntries($records['mgw_users'][0] ?? null);
        foreach ($imageEntries as $name => $content) $entries[$name] = $content;

        $path = $this->exportDirectory() . '/' . $requestId . '.zip';
        $result = AccountDataZipWriter::write($path, $entries);
        $result['table_count'] = count($records);
        return $result;
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function collectUserRows(string $mgwId): array
    {
        $owner = $this->ownershipRow($mgwId, false);
        $accountRef = trim((string)($owner['account_ref'] ?? ''));
        $legacyUserId = trim((string)($owner['legacy_user_id'] ?? ''));
        $schema = $this->schema();
        $result = [];

        foreach ($schema as $table => $columns) {
            if (!preg_match('/^mgw_[a-z0-9_]+$/', $table)) continue;
            $predicates = [];
            $params = [];
            $parameterOrdinal = 0;
            foreach ($columns as $column) {
                if (preg_match('/(^|_)mgw_id$/', $column) === 1) {
                    $key = 'mgw_id_' . $parameterOrdinal++;
                    $predicates[] = $this->quote($column) . '=:' . $key;
                    $params[$key] = $mgwId;
                }
            }
            if ($accountRef !== '' && in_array('account_ref', $columns, true)) {
                $key = 'account_ref_' . $parameterOrdinal++;
                $predicates[] = $this->quote('account_ref') . '=:' . $key;
                $params[$key] = $accountRef;
            }
            if ($legacyUserId !== '' && in_array('legacy_user_id', $columns, true)) {
                $key = 'legacy_user_id_' . $parameterOrdinal++;
                $predicates[] = $this->quote('legacy_user_id') . '=:' . $key;
                $params[$key] = $legacyUserId;
            }
            if ($predicates === []) continue;

            $rows = $this->database->fetchAll(
                'SELECT * FROM ' . $this->quote($table) . ' WHERE ' . implode(' OR ', array_unique($predicates)),
                $params
            );
            if ($rows === []) continue;
            $result[$table] = array_map(fn(array $row): array => $this->sanitizeExportRow($row), $rows);
        }

        if (isset($result['mgw_match_players'])) {
            $matchIds = array_values(array_unique(array_filter(array_map(
                static fn(array $row): string => trim((string)($row['match_id'] ?? '')),
                $result['mgw_match_players']
            ))));
            if ($matchIds !== [] && $this->tableExists('mgw_matches')) {
                $result['mgw_matches'] = array_map(
                    fn(array $row): array => $this->sanitizeExportRow($row),
                    $this->fetchByValues('mgw_matches', 'match_id', $matchIds)
                );
            }
            if ($matchIds !== [] && $this->tableExists('mgw_match_events')) {
                $result['mgw_match_events'] = array_map(
                    fn(array $row): array => $this->sanitizeExportRow($row),
                    $this->fetchByValues('mgw_match_events', 'match_id', $matchIds)
                );
            }
        }

        ksort($result, SORT_STRING);
        return $result;
    }

    /** @param list<string> $values */
    private function fetchByValues(string $table, string $column, array $values): array
    {
        if ($values === []) return [];
        $placeholders = [];
        $params = [];
        foreach ($values as $index => $value) {
            $key = 'v' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $value;
        }
        return $this->database->fetchAll(
            'SELECT * FROM ' . $this->quote($table)
            . ' WHERE ' . $this->quote($column) . ' IN (' . implode(',', $placeholders) . ')',
            $params
        );
    }

    private function sanitizeExportRow(array $row): array
    {
        foreach (array_keys($row) as $column) {
            $normalized = strtolower((string)$column);
            if (preg_match('/(password|secret|session_key_hash|device_key_hash|request_sha256|source_sha256|entry_sha256|previous_entry_sha256|event_sha256)$/', $normalized) === 1) {
                unset($row[$column]);
            }
        }
        return $row;
    }

    private function csv(array $rows): string
    {
        if ($rows === []) return '';
        $columns = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $column) $columns[(string)$column] = true;
        }
        $columns = array_keys($columns);
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) throw new RuntimeException('Не удалось собрать CSV-экспорт.');
        fputcsv($stream, $columns);
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                $line[] = is_array($value)
                    ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : (is_bool($value) ? ($value ? 'true' : 'false') : $value);
            }
            fputcsv($stream, $line);
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        return is_string($content) ? $content : '';
    }

    private function htmlSummary(string $mgwId, DateTimeImmutable $now, array $counts): string
    {
        $rows = '';
        foreach ($counts as $table => $count) {
            $rows .= '<tr><td>' . htmlspecialchars((string)$table, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</td><td>' . (int)$count . '</td></tr>';
        }
        return '<!doctype html><html lang="ru"><meta charset="utf-8"><title>MINI GAMES WORLD — экспорт данных</title>'
            . '<body><h1>Экспорт данных MINI GAMES WORLD</h1>'
            . '<p>MGW-ID: <strong>' . htmlspecialchars($mgwId, ENT_QUOTES, 'UTF-8') . '</strong></p>'
            . '<p>Создано: ' . htmlspecialchars($now->format(DATE_ATOM), ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p>Полный машинно-читаемый экспорт находится в <code>data.json</code>. '
            . 'Табличные данные продублированы в каталоге <code>csv/</code>.</p>'
            . '<table border="1" cellspacing="0" cellpadding="6"><thead><tr><th>Раздел</th><th>Записей</th></tr></thead><tbody>'
            . $rows . '</tbody></table></body></html>';
    }

    /** @return array<string,string> */
    private function imageEntries(?array $user): array
    {
        if (!is_array($user)) {
            return ['images/README.txt'=>"У аккаунта нет доступного пользовательского изображения.\n"];
        }
        $storageKey = trim((string)($user['avatar_storage_key'] ?? ''));
        if ($storageKey === '' || str_contains($storageKey, '..') || str_starts_with($storageKey, '/')) {
            return ['images/README.txt'=>"У аккаунта нет отдельного загруженного изображения. Каталожный аватар описан в data.json.\n"];
        }

        $mediaRoot = trim((string)($this->config['account_data_media_root'] ?? ''));
        if ($mediaRoot === '') {
            return ['images/README.txt'=>"В профиле есть ссылка на изображение, но приватный media-root не настроен для экспорта. Метаданные сохранены в data.json.\n"];
        }
        $rootReal = realpath($mediaRoot);
        if (!is_string($rootReal) || !is_dir($rootReal)) {
            return ['images/README.txt'=>"Приватный media-root недоступен; метаданные изображения сохранены в data.json.\n"];
        }
        $candidate = realpath($rootReal . '/' . ltrim($storageKey, '/'));
        $rootPrefix = rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!is_string($candidate)
            || !str_starts_with($candidate, $rootPrefix)
            || !is_file($candidate)) {
            return ['images/README.txt'=>"Файл пользовательского изображения не найден или недоступен; его метаданные сохранены в data.json.\n"];
        }
        $content = file_get_contents($candidate);
        if (!is_string($content)) {
            return ['images/README.txt'=>"Не удалось прочитать пользовательское изображение; метаданные сохранены в data.json.\n"];
        }
        $extension = pathinfo($candidate, PATHINFO_EXTENSION);
        $extension = preg_match('/^[a-z0-9]{1,8}$/i', $extension) === 1 ? strtolower($extension) : 'bin';
        return ['images/profile-avatar.' . $extension=>$content];
    }

    private function requestById(string $requestId, string $mgwId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT * FROM mgw_account_data_requests WHERE request_id=:request_id AND mgw_id=:mgw_id LIMIT 1',
            ['request_id'=>$requestId,'mgw_id'=>$mgwId]
        );
        if ($rows === []) throw new RuntimeException('Account data request disappeared after write.');
        return $this->publicRequest($rows[0]);
    }

    private function publicRequest(array $row): array
    {
        return [
            'request_id'=>(string)($row['request_id'] ?? ''),
            'type'=>(string)($row['request_type'] ?? ''),
            'status'=>(string)($row['request_status'] ?? ''),
            'source'=>(string)($row['source_type'] ?? ''),
            'requested_at_utc'=>$this->nullable($row['requested_at_utc'] ?? null),
            'execute_after_utc'=>$this->nullable($row['execute_after_utc'] ?? null),
            'completed_at_utc'=>$this->nullable($row['completed_at_utc'] ?? null),
            'cancelled_at_utc'=>$this->nullable($row['cancelled_at_utc'] ?? null),
            'artifact_size'=>isset($row['artifact_size']) ? (int)$row['artifact_size'] : null,
            'artifact_sha256'=>$this->nullable($row['artifact_sha256'] ?? null),
            'artifact_expires_at_utc'=>$this->nullable($row['artifact_expires_at_utc'] ?? null),
        ];
    }

    private function ownershipRow(string $mgwId, bool $forUpdate): array
    {
        if (!$this->tableExists('mgw_account_ownership')) return [];
        $lock = $forUpdate && $this->database->driver() !== 'sqlite' ? ' FOR UPDATE' : '';
        $rows = $this->database->fetchAll(
            'SELECT account_ref, legacy_user_id, mgw_id
             FROM mgw_account_ownership WHERE mgw_id=:mgw_id LIMIT 1' . $lock,
            ['mgw_id'=>$mgwId]
        );
        return $rows[0] ?? [];
    }

    /** @return array<string,list<string>> */
    private function schema(): array
    {
        if ($this->schema !== null) return $this->schema;
        $schema = [];

        if ($this->database->driver() === 'sqlite') {
            $tables = $this->database->fetchAll(
                "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'mgw_%' ORDER BY name"
            );
            foreach ($tables as $row) {
                $table = (string)($row['name'] ?? '');
                if (preg_match('/^mgw_[a-z0-9_]+$/', $table) !== 1) continue;
                $columns = [];
                foreach ($this->database->fetchAll('PRAGMA table_info(' . $table . ')') as $column) {
                    $name = (string)($column['name'] ?? '');
                    if ($name !== '') $columns[] = $name;
                }
                $schema[$table] = $columns;
            }
        } else {
            $rows = $this->database->fetchAll(
                "SELECT TABLE_NAME, COLUMN_NAME
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'mgw\\_%'
                 ORDER BY TABLE_NAME, ORDINAL_POSITION"
            );
            foreach ($rows as $row) {
                $table = (string)($row['TABLE_NAME'] ?? $row['table_name'] ?? '');
                $column = (string)($row['COLUMN_NAME'] ?? $row['column_name'] ?? '');
                if (preg_match('/^mgw_[a-z0-9_]+$/', $table) !== 1 || $column === '') continue;
                $schema[$table] ??= [];
                $schema[$table][] = $column;
            }
        }

        return $this->schema = $schema;
    }

    private function tableExists(string $table): bool
    {
        return array_key_exists($table, $this->schema());
    }

    private function quote(string $identifier): string
    {
        if (preg_match('/^[a-zA-Z0-9_]+$/', $identifier) !== 1) {
            throw new InvalidArgumentException('Unsafe SQL identifier.');
        }
        return $this->database->driver() === 'mysql'
            ? chr(96) . $identifier . chr(96)
            : '"' . $identifier . '"';
    }

    private function storeDeletedIdentityReplayTombstone(
        DatabaseConnectionInterface $database,
        string $provider,
        string $providerSubject,
        string $requestId,
        DateTimeImmutable $now
    ): void {
        if (!$this->tableExists('mgw_deleted_identity_tombstones')) return;
        $secret = trim((string)($this->config['bot_token'] ?? ''));
        if ($secret === '') {
            throw new RuntimeException('Deleted identity replay protection secret is unavailable.');
        }

        $hmac = hash_hmac(
            'sha256',
            "mgw-deleted-identity-v1\n" . $provider . "\n" . $providerSubject,
            $secret
        );
        $blockUntil = $this->format($now->modify('+' . $this->deletedIdentityBlockSec() . ' seconds'));
        $database->execute(
            'DELETE FROM mgw_deleted_identity_tombstones
             WHERE provider=:provider AND provider_subject_hmac=:provider_subject_hmac',
            ['provider'=>$provider,'provider_subject_hmac'=>$hmac]
        );
        $database->execute(
            'INSERT INTO mgw_deleted_identity_tombstones (
                provider, provider_subject_hmac, deletion_request_id, block_until_utc, created_at_utc
             ) VALUES (
                :provider, :provider_subject_hmac, :deletion_request_id, :block_until_utc, :created_at_utc
             )',
            [
                'provider'=>$provider,
                'provider_subject_hmac'=>$hmac,
                'deletion_request_id'=>$requestId,
                'block_until_utc'=>$blockUntil,
                'created_at_utc'=>$this->format($now),
            ]
        );
    }

    private function deletedIdentityBlockSec(): int
    {
        $maxAge = max(60, (int)($this->config['telegram_init_data_max_age_sec'] ?? 86400));
        $clockSkew = max(0, (int)($this->config['telegram_init_data_clock_skew_sec'] ?? 300));
        return max(
            300,
            $maxAge + $clockSkew,
            (int)($this->config['account_deleted_identity_block_sec'] ?? 0)
        );
    }

    private function requestId(): string
    {
        return 'adr_' . bin2hex(random_bytes(16));
    }

    private function mgwId(string $mgwId): string
    {
        $mgwId = strtoupper(trim($mgwId));
        if (!MgwIdGenerator::isValid($mgwId)) {
            throw new AccountDataLifecycleException('invalid_mgw_id', 'Некорректный MGW-ID.');
        }
        return $mgwId;
    }

    private function sourceType(string $sourceType): string
    {
        $sourceType = strtolower(trim($sourceType));
        if (!in_array($sourceType, ['mini_app','website'], true)) {
            throw new AccountDataLifecycleException('invalid_source', 'Некорректный источник запроса.');
        }
        return $sourceType;
    }

    private function sourceRef(string $sourceRef): string
    {
        $sourceRef = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $sourceRef) ?? '');
        if ($sourceRef === '') $sourceRef = 'self-service';
        return function_exists('mb_substr') ? mb_substr($sourceRef, 0, 191) : substr($sourceRef, 0, 191);
    }

    private function exportDirectory(): string
    {
        $directory = trim((string)($this->config['account_data_export_dir'] ?? ''));
        if ($directory === '') {
            $dataDir = rtrim((string)($this->config['data_dir'] ?? ''), '/');
            if ($dataDir === '') throw new RuntimeException('Private data directory is unavailable.');
            $directory = $dataDir . '/account-exports';
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Не удалось подготовить приватный каталог экспортов.');
        }
        return rtrim($directory, '/');
    }

    private function exportRateLimitSec(): int
    {
        return max(300, (int)($this->config['account_data_export_rate_limit_sec'] ?? self::DEFAULT_EXPORT_RATE_LIMIT_SEC));
    }

    private function exportRetentionSec(): int
    {
        return max(3600, (int)($this->config['account_data_export_retention_sec'] ?? self::DEFAULT_EXPORT_RETENTION_SEC));
    }

    private function safeError(string $message): string
    {
        $message = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $message) ?? '');
        return function_exists('mb_substr') ? mb_substr($message, 0, 500) : substr($message, 0, 500);
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function utc(?DateTimeImmutable $now): DateTimeImmutable
    {
        return ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
    }

    private function format(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
