<?php
declare(strict_types=1);

final class ProductionPrimaryInviteResidualRecoveryService
{
    public const CONTRACT_VERSION = 'v1-db-only-expired-invite-residual-recovery';

    public function __construct(
        private DatabaseConnectionInterface $database,
        private RuntimePrimaryProjectionAuditorInterface $auditor
    ) {}

    public function preview(): array
    {
        $plan = $this->inspect($this->database, false);
        return $plan + [
            'dry_run' => true,
            'database_write_executed' => false,
        ];
    }

    public function run(string $expectedPlanFingerprint): array
    {
        $expectedPlanFingerprint = strtolower(trim($expectedPlanFingerprint));
        if (preg_match('/\A[a-f0-9]{64}\z/', $expectedPlanFingerprint) !== 1) {
            throw new RuntimeException('Invite residual recovery plan fingerprint is invalid.');
        }

        $preview = $this->inspect($this->database, false);
        $this->assertExecutable($preview, $expectedPlanFingerprint, 'current');

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $expectedPlanFingerprint
        ): array {
            $locked = $this->inspect($database, true);
            $this->assertExecutable($locked, $expectedPlanFingerprint, 'locked');

            $private = $locked['private_preimage'] ?? null;
            if (!is_array($private)) {
                throw new RuntimeException('Invite residual recovery preimage is unavailable.');
            }
            $invite = $private['invite'] ?? null;
            $notification = $private['notification'] ?? null;
            $events = is_array($private['invite_events'] ?? null) ? $private['invite_events'] : [];
            if (!is_array($invite) || !is_array($notification)) {
                throw new RuntimeException('Invite residual recovery target rows are unavailable.');
            }

            $notificationId = (string)$notification['notification_id'];
            $eventKey = (string)$notification['event_key'];
            $inviteToken = (string)$notification['invite_token'];
            $inviteId = (string)$invite['invite_id'];

            $deletedNotification = $database->execute(
                'DELETE FROM mgw_notifications
                 WHERE notification_id = :notification_id
                   AND event_key = :event_key
                   AND invite_token = :invite_token
                   AND type = :type',
                [
                    'notification_id' => $notificationId,
                    'event_key' => $eventKey,
                    'invite_token' => $inviteToken,
                    'type' => 'invite_expired',
                ]
            );
            if ($deletedNotification !== 1) {
                throw new RuntimeException('Invite residual recovery notification delete count is unexpected.');
            }

            $deletedEvents = $database->execute(
                'DELETE FROM mgw_invite_events WHERE invite_id = :invite_id',
                ['invite_id' => $inviteId]
            );
            if ($deletedEvents !== count($events)) {
                throw new RuntimeException('Invite residual recovery event delete count is unexpected.');
            }

            $deletedInvite = $database->execute(
                'DELETE FROM mgw_invites
                 WHERE invite_id = :invite_id
                   AND token = :token
                   AND status = :status',
                [
                    'invite_id' => $inviteId,
                    'token' => (string)$invite['token'],
                    'status' => 'expired',
                ]
            );
            if ($deletedInvite !== 1) {
                throw new RuntimeException('Invite residual recovery invite delete count is unexpected.');
            }

            foreach ([
                'invite' => [
                    'SELECT COUNT(*) FROM mgw_invites WHERE invite_id = :value',
                    ['value' => $inviteId],
                ],
                'notification' => [
                    'SELECT COUNT(*) FROM mgw_notifications WHERE notification_id = :value',
                    ['value' => $notificationId],
                ],
                'events' => [
                    'SELECT COUNT(*) FROM mgw_invite_events WHERE invite_id = :value',
                    ['value' => $inviteId],
                ],
            ] as $label => [$sql, $parameters]) {
                if ((int)$database->fetchValue($sql, $parameters) !== 0) {
                    throw new RuntimeException('Invite residual recovery target ' . $label . ' remains after deletion.');
                }
            }

            $primaryAfter = $this->primary($database, true);
            if ((int)$primaryAfter['revision'] !== (int)$locked['state_revision']
                || !hash_equals((string)$locked['state_sha256'], (string)$primaryAfter['state_sha256'])) {
                throw new RuntimeException('Invite residual recovery changed the DB-primary state identity.');
            }

            $audit = $this->auditor->auditOnly(
                $primaryAfter['snapshot'],
                (int)$primaryAfter['revision'],
                (string)$primaryAfter['state_sha256']
            );
            if (($audit['ok'] ?? false) !== true
                || ($audit['parity_ok'] ?? false) !== true
                || ($audit['read_only'] ?? false) !== true
                || (int)($audit['state_revision'] ?? 0) !== (int)$primaryAfter['revision']
                || !hash_equals(
                    (string)$primaryAfter['state_sha256'],
                    strtolower(trim((string)($audit['state_sha256'] ?? '')))
                )) {
                throw new RuntimeException('Invite residual recovery post-delete all-module audit failed.');
            }

            return [
                'ok' => true,
                'dry_run' => false,
                'status' => 'completed',
                'contract_version' => self::CONTRACT_VERSION,
                'plan_fingerprint' => $expectedPlanFingerprint,
                'state_revision' => (int)$primaryAfter['revision'],
                'state_sha256' => (string)$primaryAfter['state_sha256'],
                'deleted' => [
                    'invite_rows' => $deletedInvite,
                    'notification_rows' => $deletedNotification,
                    'invite_event_rows' => $deletedEvents,
                ],
                'post_delete_all_module_parity' => true,
                'database_write_executed' => true,
                'primary_state_changed' => false,
                'cutover_executed' => false,
                'release_executed' => false,
                'rollback_executed' => false,
            ];
        });
    }

    private function inspect(DatabaseConnectionInterface $database, bool $forUpdate): array
    {
        $primary = $this->primary($database, $forUpdate);
        $snapshot = $primary['snapshot'];
        $lock = $forUpdate && $database->driver() === 'mysql' ? ' FOR UPDATE' : '';
        $blockers = [];

        [$stateInviteIds, $stateInviteTokens] = $this->stateInviteIdentity($snapshot, $blockers);
        [$stateNotificationIds, $stateNotificationEvents] = $this->stateNotificationIdentity($snapshot, $blockers);

        $databaseInvites = $database->fetchAll(
            'SELECT * FROM mgw_invites ORDER BY invite_id' . $lock
        );
        $databaseNotifications = $database->fetchAll(
            'SELECT * FROM mgw_notifications ORDER BY notification_id' . $lock
        );

        $dbInviteIds = [];
        $dbInviteTokens = [];
        $extraInvites = [];
        foreach ($databaseInvites as $row) {
            $id = trim((string)($row['invite_id'] ?? ''));
            $token = trim((string)($row['token'] ?? ''));
            if ($id === '' || $token === '' || isset($dbInviteIds[$id]) || isset($dbInviteTokens[$token])) {
                $blockers[] = 'Normalized invite rows contain invalid or duplicate identity.';
                continue;
            }
            $dbInviteIds[$id] = true;
            $dbInviteTokens[$token] = true;
            $idPresent = isset($stateInviteIds[$id]);
            $tokenPresent = isset($stateInviteTokens[$token]);
            if ($idPresent xor $tokenPresent) {
                $blockers[] = 'Normalized invite identity partially conflicts with DB-primary state.';
            } elseif (!$idPresent && !$tokenPresent) {
                $extraInvites[] = $row;
            }
        }
        foreach ($stateInviteIds as $id => $_) {
            if (!isset($dbInviteIds[$id])) $blockers[] = 'A DB-primary invite is missing from normalized storage.';
        }

        $dbNotificationIds = [];
        $dbNotificationEvents = [];
        $extraNotifications = [];
        foreach ($databaseNotifications as $row) {
            $id = trim((string)($row['notification_id'] ?? ''));
            $event = trim((string)($row['event_key'] ?? ''));
            if ($id === '' || $event === '' || isset($dbNotificationIds[$id])) {
                $blockers[] = 'Normalized notification rows contain invalid or duplicate identity.';
                continue;
            }
            $dbNotificationIds[$id] = true;
            $eventScope = trim((string)($row['recipient_ref'] ?? '')) . '|' . $event;
            if (isset($dbNotificationEvents[$eventScope])) {
                $blockers[] = 'Normalized notifications contain duplicate recipient event identity.';
            }
            $dbNotificationEvents[$eventScope] = true;

            $idPresent = isset($stateNotificationIds[$id]);
            $eventPresent = isset($stateNotificationEvents[$event]);
            if ($idPresent xor $eventPresent) {
                $blockers[] = 'Normalized notification identity partially conflicts with DB-primary state.';
            } elseif (!$idPresent && !$eventPresent) {
                $extraNotifications[] = $row;
            }
        }
        foreach ($stateNotificationIds as $id => $_) {
            if (!isset($dbNotificationIds[$id])) $blockers[] = 'A DB-primary notification is missing from normalized storage.';
        }

        if (count($extraInvites) !== 1) {
            $blockers[] = 'Recovery requires exactly one DB-only invite.';
        }
        if (count($extraNotifications) !== 1) {
            $blockers[] = 'Recovery requires exactly one DB-only notification.';
        }

        $invite = count($extraInvites) === 1 ? $extraInvites[0] : null;
        $notification = count($extraNotifications) === 1 ? $extraNotifications[0] : null;
        $events = [];
        $matchCount = 0;

        if (is_array($invite) && is_array($notification)) {
            $inviteId = trim((string)$invite['invite_id']);
            $token = trim((string)$invite['token']);
            $status = trim((string)($invite['status'] ?? ''));
            $matchId = trim((string)($invite['match_id'] ?? ''));
            $legacyUserId = trim((string)($notification['legacy_user_id'] ?? ''));
            $eventKey = trim((string)($notification['event_key'] ?? ''));
            $expectedEvent = 'invite:' . $inviteId . ':expired:' . $legacyUserId;
            $gameTitle = (string)($invite['game_title'] ?? 'Игра');
            $expectedMessage = 'Матч «' . $gameTitle . '» не начался.';

            if ($status !== 'expired') $blockers[] = 'DB-only invite is not expired.';
            if ($matchId !== '') $blockers[] = 'DB-only invite is already attached to a match.';
            if ((string)($notification['type'] ?? '') !== 'invite_expired') {
                $blockers[] = 'DB-only notification is not an invite expiry.';
            }
            if (!hash_equals($token, trim((string)($notification['invite_token'] ?? '')))) {
                $blockers[] = 'DB-only notification token does not match the invite.';
            }
            if ($legacyUserId === '' || !hash_equals($expectedEvent, $eventKey)) {
                $blockers[] = 'DB-only expiry event key does not match the invite recipient.';
            }
            if (!in_array($legacyUserId, [
                trim((string)($invite['inviter_legacy_user_id'] ?? '')),
                trim((string)($invite['invitee_legacy_user_id'] ?? '')),
            ], true)) {
                $blockers[] = 'DB-only expiry recipient is not an invite participant.';
            }
            if ((string)($notification['title'] ?? '') !== 'Срок приглашения истёк'
                || (string)($notification['message'] ?? '') !== $expectedMessage
                || (string)($notification['tone'] ?? '') !== 'warning') {
                $blockers[] = 'DB-only expiry notification content is not deterministic.';
            }
            if (trim((string)($notification['read_at_utc'] ?? '')) !== ''
                || trim((string)($notification['hidden_at_utc'] ?? '')) !== '') {
                $blockers[] = 'DB-only expiry notification has mutable read or hidden state.';
            }

            $ownership = $database->fetchAll(
                'SELECT account_ref, mgw_id, ownership_status
                 FROM mgw_account_ownership WHERE legacy_user_id = :legacy_user_id' . $lock,
                ['legacy_user_id' => $legacyUserId]
            );
            if (count($ownership) !== 1
                || (string)($ownership[0]['ownership_status'] ?? '') !== 'active'
                || !hash_equals(
                    trim((string)($ownership[0]['account_ref'] ?? '')),
                    trim((string)($notification['recipient_ref'] ?? ''))
                )
                || !hash_equals(
                    trim((string)($ownership[0]['mgw_id'] ?? '')),
                    trim((string)($notification['mgw_id'] ?? ''))
                )) {
                $blockers[] = 'DB-only expiry notification ownership is invalid.';
            }

            $payload = $this->decodePayload($notification['payload_json'] ?? null, $blockers);
            foreach ([
                'id' => (string)($notification['notification_id'] ?? ''),
                'event_key' => $eventKey,
                'user_id' => $legacyUserId,
                'type' => 'invite_expired',
                'title' => 'Срок приглашения истёк',
                'message' => $expectedMessage,
                'tone' => 'warning',
                'invite_token' => $token,
            ] as $key => $expected) {
                if ((string)($payload[$key] ?? '') !== $expected) {
                    $blockers[] = 'DB-only expiry payload does not match the normalized row.';
                    break;
                }
            }

            $expires = $this->timestamp($invite['expires_at_utc'] ?? null);
            $inviteUpdated = $this->timestamp($invite['updated_at_utc'] ?? null);
            $notificationCreated = $this->timestamp($notification['created_at_utc'] ?? null);
            if ($expires === null || $inviteUpdated === null || $notificationCreated === null
                || $inviteUpdated < $expires
                || $notificationCreated < $expires
                || abs($notificationCreated - $inviteUpdated) > 5) {
                $blockers[] = 'DB-only expiry timestamps do not match one deterministic expiry transition.';
            }

            $matchCount = (int)$database->fetchValue(
                'SELECT COUNT(*) FROM mgw_matches
                 WHERE invite_id = :invite_id OR source_match_id = :invite_id',
                ['invite_id' => $inviteId]
            );
            if ($matchCount !== 0) $blockers[] = 'DB-only invite is referenced by a match.';

            $events = $database->fetchAll(
                'SELECT * FROM mgw_invite_events WHERE invite_id = :invite_id ORDER BY event_id' . $lock,
                ['invite_id' => $inviteId]
            );
        }

        $blockers = array_values(array_unique($blockers));
        sort($blockers, SORT_STRING);
        $preimage = is_array($invite) && is_array($notification) ? [
            'invite' => $invite,
            'notification' => $notification,
            'invite_events' => $events,
        ] : null;
        $planPayload = [
            'contract_version' => self::CONTRACT_VERSION,
            'state_revision' => (int)$primary['revision'],
            'state_sha256' => (string)$primary['state_sha256'],
            'source_invite_count' => count($stateInviteIds),
            'database_invite_count' => count($databaseInvites),
            'source_notification_count' => count($stateNotificationIds),
            'database_notification_count' => count($databaseNotifications),
            'preimage' => $preimage,
        ];
        $fingerprint = hash('sha256', $this->canonicalJson($planPayload));

        return [
            'ok' => $blockers === [],
            'ready' => $blockers === [],
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'contract_version' => self::CONTRACT_VERSION,
            'state_revision' => (int)$primary['revision'],
            'state_sha256' => (string)$primary['state_sha256'],
            'source_invite_count' => count($stateInviteIds),
            'database_invite_count' => count($databaseInvites),
            'db_only_invite_count' => count($extraInvites),
            'source_notification_count' => count($stateNotificationIds),
            'database_notification_count' => count($databaseNotifications),
            'db_only_notification_count' => count($extraNotifications),
            'related_match_count' => $matchCount,
            'invite_event_count' => count($events),
            'invite_id_hash' => is_array($invite) ? $this->mask($invite['invite_id'] ?? '') : '',
            'invite_token_hash' => is_array($invite) ? $this->mask($invite['token'] ?? '') : '',
            'notification_id_hash' => is_array($notification) ? $this->mask($notification['notification_id'] ?? '') : '',
            'row_pair_fingerprint' => $preimage === null ? '' : hash('sha256', $this->canonicalJson($preimage)),
            'plan_fingerprint' => $fingerprint,
            'blocking_reasons' => $blockers,
            'private_preimage' => $preimage,
            'sensitive_identifiers_exposed' => false,
            'primary_state_changed' => false,
            'cutover_executed' => false,
            'release_executed' => false,
            'rollback_executed' => false,
        ];
    }

    private function primary(DatabaseConnectionInterface $database, bool $forUpdate): array
    {
        $sql = 'SELECT singleton_id, revision, state_json, state_sha256, created_at_utc, updated_at_utc
                FROM ' . RuntimePrimaryStateSchemaInstaller::TABLE . ' WHERE singleton_id = 1';
        if ($forUpdate && $database->driver() === 'mysql') $sql .= ' FOR UPDATE';
        $rows = $database->fetchAll($sql);
        if (count($rows) !== 1) throw new RuntimeException('Invite residual recovery requires one DB-primary state row.');
        $row = $rows[0];
        try {
            $snapshot = json_decode((string)($row['state_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Invite residual recovery primary state JSON is invalid.', 0, $error);
        }
        if (!is_array($snapshot)) throw new RuntimeException('Invite residual recovery primary state is not an object.');
        $sha = strtolower(trim((string)($row['state_sha256'] ?? '')));
        if ((int)($row['revision'] ?? 0) < 1
            || preg_match('/\A[a-f0-9]{64}\z/', $sha) !== 1
            || !hash_equals($sha, hash('sha256', $this->canonicalJson($snapshot)))) {
            throw new RuntimeException('Invite residual recovery primary state identity is invalid.');
        }
        return [
            'revision' => (int)$row['revision'],
            'state_sha256' => $sha,
            'snapshot' => $snapshot,
        ];
    }

    private function stateInviteIdentity(array $snapshot, array &$blockers): array
    {
        $ids = [];
        $tokens = [];
        foreach (($snapshot['invites'] ?? []) as $row) {
            if (!is_array($row)) {
                $blockers[] = 'DB-primary invite is not an object.';
                continue;
            }
            $id = trim((string)($row['id'] ?? ''));
            $token = trim((string)($row['token'] ?? ''));
            if ($id === '' || $token === '' || isset($ids[$id]) || isset($tokens[$token])) {
                $blockers[] = 'DB-primary invites contain invalid or duplicate identity.';
                continue;
            }
            $ids[$id] = true;
            $tokens[$token] = true;
        }
        return [$ids, $tokens];
    }

    private function stateNotificationIdentity(array $snapshot, array &$blockers): array
    {
        $ids = [];
        $events = [];
        foreach (($snapshot['notifications'] ?? []) as $row) {
            if (!is_array($row)) {
                $blockers[] = 'DB-primary notification is not an object.';
                continue;
            }
            $id = trim((string)($row['id'] ?? ''));
            $event = trim((string)($row['event_key'] ?? ''));
            if ($id === '' || $event === '' || isset($ids[$id]) || isset($events[$event])) {
                $blockers[] = 'DB-primary notifications contain invalid or duplicate identity.';
                continue;
            }
            $ids[$id] = true;
            $events[$event] = true;
        }
        return [$ids, $events];
    }

    private function decodePayload(mixed $value, array &$blockers): array
    {
        if (!is_string($value) || trim($value) === '') {
            $blockers[] = 'DB-only expiry notification payload is missing.';
            return [];
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $blockers[] = 'DB-only expiry notification payload is invalid JSON.';
            return [];
        }
        if (!is_array($decoded)) {
            $blockers[] = 'DB-only expiry notification payload is not an object.';
            return [];
        }
        return $decoded;
    }

    private function assertExecutable(array $plan, string $expectedFingerprint, string $stage): void
    {
        if (($plan['ready'] ?? false) !== true || ($plan['status'] ?? '') !== 'ready') {
            throw new RuntimeException(
                'Invite residual recovery ' . $stage . ' plan is blocked: '
                . implode('; ', array_map('strval', (array)($plan['blocking_reasons'] ?? [])))
            );
        }
        if (!hash_equals($expectedFingerprint, (string)($plan['plan_fingerprint'] ?? ''))) {
            throw new RuntimeException('Invite residual recovery ' . $stage . ' plan fingerprint changed.');
        }
    }

    private function timestamp(mixed $value): ?int
    {
        $value = trim((string)$value);
        if ($value === '') return null;
        $timestamp = strtotime($value);
        return $timestamp === false ? null : $timestamp;
    }

    private function mask(mixed $value): string
    {
        return substr(hash('sha256', (string)$value), 0, 16);
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
}
