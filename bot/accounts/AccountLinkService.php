<?php
declare(strict_types=1);

require_once __DIR__ . '/../localization/ServerLocalization.php';

final class AccountLinkException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $httpStatus = 409
    ) {
        parent::__construct($message);
    }
}

final class AccountLinkService
{
    private const PROVIDER_ANDROID = 'android_device';
    private const PROVIDER_TELEGRAM = 'telegram';
    private const CHALLENGE_TTL_SECONDS = 10 * 60;
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{32}$/';
    private const CHALLENGE_PATTERN = '/^lnk_[a-f0-9]{20}$/';

    public function __construct(
        private array $config,
        private DatabaseConnectionInterface $database,
        private StorageAdapterInterface $storage,
        private ?PresenceService $presence = null
    ) {}

    public function enabled(): bool
    {
        if (strtolower(trim((string)($this->config['environment'] ?? ''))) !== 'staging') return false;
        $router = new RuntimeStorageRouter($this->config);
        return $router->enabled()
            && $router->routeFor('accounts') === RuntimeStorageRouter::DRIVER_DATABASE
            && $router->routeFor('economy') === RuntimeStorageRouter::DRIVER_DATABASE;
    }

    public function createChallenge(array $androidUser): array
    {
        $this->assertEnabled();
        $context = $this->androidContext($androidUser);

        $telegramIdentity = $this->identityForMgw($context['mgw_id'], self::PROVIDER_TELEGRAM);
        if ($telegramIdentity !== null) {
            return $this->linkedResult($context['mgw_id'], true);
        }

        $botUsername = trim((string)($this->config['staging_bot_username'] ?? $this->config['bot_username'] ?? ''));
        if (preg_match('/^[A-Za-z0-9_]{5,64}$/', $botUsername) !== 1) {
            throw new AccountLinkException('telegram_bot_unavailable', ServerLocalization::copy('server.account_chain.link.telegram_bot_unavailable', 'The Telegram bot for account linking is temporarily unavailable.'), 503);
        }

        $now = $this->now();
        $recentCutoff = $now->modify('-1 hour')->format('Y-m-d H:i:s.u');
        $recent = (int)$this->database->fetchValue(
            'SELECT COUNT(*) FROM mgw_account_link_challenges
             WHERE source_mgw_id=:source_mgw_id AND created_at_utc>=:recent_cutoff',
            ['source_mgw_id'=>$context['mgw_id'], 'recent_cutoff'=>$recentCutoff]
        );
        if ($recent >= 10) {
            throw new AccountLinkException('rate_limited', ServerLocalization::copy('server.account_chain.link.rate_limited', 'Too many account-link attempts. Try again later.'), 429);
        }

        $challengeId = 'lnk_' . bin2hex(random_bytes(10));
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new RuntimeException('Account-link token generation failed.');
        }
        $createdAt = $now->format('Y-m-d H:i:s.u');
        $expiresAt = $now->modify('+' . self::CHALLENGE_TTL_SECONDS . ' seconds')->format('Y-m-d H:i:s.u');

        $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $context,
            $challengeId,
            $token,
            $createdAt,
            $expiresAt
        ): void {
            $database->execute(
                "UPDATE mgw_account_link_challenges
                 SET link_status='cancelled', cancelled_at_utc=:cancelled_at
                 WHERE source_mgw_id=:source_mgw_id
                   AND link_status IN ('pending','claimed','confirmed')",
                ['cancelled_at'=>$createdAt, 'source_mgw_id'=>$context['mgw_id']]
            );
            $database->execute(
                'INSERT INTO mgw_account_link_challenges (
                    challenge_id, token_sha256,
                    source_mgw_id, source_android_subject, source_legacy_user_id,
                    target_mgw_id, target_telegram_subject, target_legacy_user_id,
                    link_status, created_at_utc, expires_at_utc,
                    claimed_at_utc, confirmed_at_utc, db_linked_at_utc,
                    finalized_at_utc, cancelled_at_utc,
                    retired_balance_amount, last_error_code
                 ) VALUES (
                    :challenge_id, :token_sha256,
                    :source_mgw_id, :source_android_subject, :source_legacy_user_id,
                    NULL, NULL, NULL,
                    :link_status, :created_at_utc, :expires_at_utc,
                    NULL, NULL, NULL,
                    NULL, NULL,
                    0, NULL
                 )',
                [
                    'challenge_id'=>$challengeId,
                    'token_sha256'=>hash('sha256', $token),
                    'source_mgw_id'=>$context['mgw_id'],
                    'source_android_subject'=>$context['android_subject'],
                    'source_legacy_user_id'=>$context['legacy_user_id'],
                    'link_status'=>'pending',
                    'created_at_utc'=>$createdAt,
                    'expires_at_utc'=>$expiresAt,
                ]
            );
        });

        return [
            'status'=>'pending',
            'challenge_id'=>$challengeId,
            'expires_at'=>$expiresAt,
            'telegram_url'=>'https://t.me/' . $botUsername . '?start=link_' . rawurlencode($token),
        ];
    }

    public function claimTelegramToken(string $token, string $telegramSubject): array
    {
        $this->assertEnabled();
        $token = trim($token);
        $telegramSubject = $this->normalizeSubject($telegramSubject);
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new AccountLinkException('invalid_token', ServerLocalization::copy('server.account_chain.link.invalid_token', 'The account-link URL is invalid.'), 400);
        }

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use ($token, $telegramSubject): array {
            $row = $this->challengeByToken($database, hash('sha256', $token), true);
            $this->assertChallengeUsable($database, $row, ['pending','claimed']);

            $targetIdentity = $this->identityForProviderSubjectDb(
                $database,
                self::PROVIDER_TELEGRAM,
                $telegramSubject,
                true
            );
            if ($targetIdentity === null || (string)($targetIdentity['status'] ?? '') !== 'active') {
                throw new AccountLinkException(
                    'telegram_account_missing',
                    ServerLocalization::copy('server.account_chain.link.telegram_first_open_required', 'Open MINI GAMES WORLD in Telegram at least once, then try linking again.'),
                    409
                );
            }

            $targetMgwId = (string)$targetIdentity['mgw_id'];
            if ($targetMgwId === (string)$row['source_mgw_id']) {
                throw new AccountLinkException('already_linked', ServerLocalization::copy('server.account_chain.link.already_linked', 'This Telegram account is already linked to the current MGW profile.'));
            }

            $ownership = $this->ownershipForMgwDb($database, $targetMgwId, true);
            if ($ownership === null) {
                throw new AccountLinkException('target_ownership_missing', ServerLocalization::copy('server.account_chain.link.target_ownership_missing', 'The Telegram profile owner could not be verified.'));
            }

            $targetAndroid = $this->identityForMgwDb($database, $targetMgwId, self::PROVIDER_ANDROID, true);
            if ($targetAndroid !== null
                && !hash_equals((string)$row['source_android_subject'], (string)$targetAndroid['provider_subject'])) {
                throw new AccountLinkException(
                    'target_android_conflict',
                    ServerLocalization::copy('server.account_chain.link.target_android_conflict', 'This MGW profile is already linked to another Android device.')
                );
            }

            $status = (string)$row['link_status'];
            $existingTelegram = trim((string)($row['target_telegram_subject'] ?? ''));
            if ($status === 'claimed' && $existingTelegram !== '' && !hash_equals($existingTelegram, $telegramSubject)) {
                throw new AccountLinkException('challenge_claimed', ServerLocalization::copy('server.account_chain.link.challenge_claimed', 'This account-link attempt is already being confirmed by another Telegram profile.'));
            }

            $claimedAt = $this->timestamp();
            $database->execute(
                "UPDATE mgw_account_link_challenges
                 SET target_mgw_id=:target_mgw_id,
                     target_telegram_subject=:target_telegram_subject,
                     target_legacy_user_id=:target_legacy_user_id,
                     link_status='claimed',
                     claimed_at_utc=:claimed_at,
                     last_error_code=NULL
                 WHERE challenge_id=:challenge_id",
                [
                    'target_mgw_id'=>$targetMgwId,
                    'target_telegram_subject'=>$telegramSubject,
                    'target_legacy_user_id'=>(string)$ownership['legacy_user_id'],
                    'claimed_at'=>$claimedAt,
                    'challenge_id'=>(string)$row['challenge_id'],
                ]
            );

            return [
                'status'=>'claimed',
                'challenge_id'=>(string)$row['challenge_id'],
                'target_mgw_id'=>$targetMgwId,
                'target_nickname'=>(string)($targetIdentity['nickname'] ?? ServerLocalization::copy('server.account_chain.common.player_fallback', 'Player')),
            ];
        });
    }

    public function confirmTelegramChallenge(string $challengeId, string $telegramSubject): array
    {
        $this->assertEnabled();
        $challengeId = $this->normalizeChallengeId($challengeId);
        $telegramSubject = $this->normalizeSubject($telegramSubject);

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $challengeId,
            $telegramSubject
        ): array {
            $row = $this->challengeById($database, $challengeId, true);
            $this->assertChallengeUsable($database, $row, ['claimed','confirmed']);

            if (!hash_equals((string)($row['target_telegram_subject'] ?? ''), $telegramSubject)) {
                throw new AccountLinkException('confirmation_owner_mismatch', ServerLocalization::copy('server.account_chain.link.confirmation_owner_mismatch', 'The same Telegram profile must confirm this account link.'));
            }

            if ((string)$row['link_status'] !== 'confirmed') {
                $database->execute(
                    "UPDATE mgw_account_link_challenges
                     SET link_status='confirmed', confirmed_at_utc=:confirmed_at, last_error_code=NULL
                     WHERE challenge_id=:challenge_id AND link_status='claimed'",
                    ['confirmed_at'=>$this->timestamp(), 'challenge_id'=>$challengeId]
                );
            }

            return [
                'status'=>'confirmed',
                'challenge_id'=>$challengeId,
                'target_mgw_id'=>(string)$row['target_mgw_id'],
            ];
        });
    }

    public function cancelTelegramChallenge(string $challengeId, string $telegramSubject): array
    {
        $this->assertEnabled();
        $challengeId = $this->normalizeChallengeId($challengeId);
        $telegramSubject = $this->normalizeSubject($telegramSubject);

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $challengeId,
            $telegramSubject
        ): array {
            $row = $this->challengeById($database, $challengeId, true);
            if (!in_array((string)$row['link_status'], ['claimed','confirmed'], true)) {
                return ['status'=>(string)$row['link_status'], 'challenge_id'=>$challengeId];
            }
            if (!hash_equals((string)($row['target_telegram_subject'] ?? ''), $telegramSubject)) {
                throw new AccountLinkException('confirmation_owner_mismatch', ServerLocalization::copy('server.account_chain.link.cancel_owner_mismatch', 'The same Telegram profile must cancel this account link.'));
            }
            $database->execute(
                "UPDATE mgw_account_link_challenges
                 SET link_status='cancelled', cancelled_at_utc=:cancelled_at
                 WHERE challenge_id=:challenge_id",
                ['cancelled_at'=>$this->timestamp(), 'challenge_id'=>$challengeId]
            );
            return ['status'=>'cancelled', 'challenge_id'=>$challengeId];
        });
    }

    public function statusForAndroid(string $challengeId, array $androidUser): array
    {
        $this->assertEnabled();
        $challengeId = $this->normalizeChallengeId($challengeId);
        $context = $this->androidContext($androidUser);
        $row = $this->challengeById($this->database, $challengeId, false);
        $row = $this->expireIfNeeded($row);

        $this->assertAndroidChallengeOwner($row, $context);

        $result = [
            'status'=>(string)$row['link_status'],
            'challenge_id'=>$challengeId,
            'expires_at'=>(string)$row['expires_at_utc'],
        ];
        if (!empty($row['target_mgw_id'])) {
            $result['target'] = $this->targetSummary((string)$row['target_mgw_id']);
        }
        return $result;
    }

    public function finalizeForAndroid(string $challengeId, array $androidUser): array
    {
        $this->assertEnabled();
        $challengeId = $this->normalizeChallengeId($challengeId);
        $context = $this->androidContext($androidUser);
        $row = $this->challengeById($this->database, $challengeId, false);
        $row = $this->expireIfNeeded($row);
        $this->assertAndroidChallengeOwner($row, $context);

        if ((string)$row['link_status'] === 'linked') {
            return $this->linkedResult((string)$row['target_mgw_id'], false);
        }

        if ((string)$row['link_status'] === 'confirmed') {
            $this->assertSourcePristine($row);
            $this->moveAndroidIdentityToTarget($challengeId);
            $row = $this->challengeById($this->database, $challengeId, false);
        }

        if ((string)$row['link_status'] !== 'db_linked') {
            throw new AccountLinkException(
                'confirmation_required',
                ServerLocalization::copy('server.account_chain.link.telegram_confirmation_required', 'Confirm the account link in Telegram first.')
            );
        }

        try {
            $this->cleanupSourceRuntime($row);
            $retired = $this->retireSourceBalance($row);
            $this->completeLink($row, $retired);
        } catch (Throwable $error) {
            $this->recordCleanupError($challengeId, $error);
            throw $error;
        }

        return $this->linkedResult((string)$row['target_mgw_id'], false);
    }

    private function moveAndroidIdentityToTarget(string $challengeId): void
    {
        $this->database->transaction(function (DatabaseConnectionInterface $database) use ($challengeId): void {
            $row = $this->challengeById($database, $challengeId, true);
            if ((string)$row['link_status'] === 'db_linked' || (string)$row['link_status'] === 'linked') return;
            $this->assertChallengeUsable($database, $row, ['confirmed']);

            $sourceMgw = (string)$row['source_mgw_id'];
            $targetMgw = (string)$row['target_mgw_id'];
            $subject = (string)$row['source_android_subject'];
            if ($targetMgw === '' || $sourceMgw === $targetMgw) {
                throw new AccountLinkException('target_invalid', ServerLocalization::copy('server.account_chain.link.target_invalid', 'The target MGW profile is invalid.'));
            }

            $sourceIdentity = $this->identityForProviderSubjectDb(
                $database,
                self::PROVIDER_ANDROID,
                $subject,
                true
            );
            if ($sourceIdentity === null) {
                throw new AccountLinkException('android_identity_missing', ServerLocalization::copy('server.account_chain.link.android_identity_missing', 'Android identity was not found.'));
            }
            if ((string)$sourceIdentity['mgw_id'] === $targetMgw) {
                $database->execute(
                    "UPDATE mgw_account_link_challenges
                     SET link_status='db_linked', db_linked_at_utc=:linked_at
                     WHERE challenge_id=:challenge_id",
                    ['linked_at'=>$this->timestamp(), 'challenge_id'=>$challengeId]
                );
                return;
            }
            if ((string)$sourceIdentity['mgw_id'] !== $sourceMgw) {
                throw new AccountLinkException('android_identity_conflict', ServerLocalization::copy('server.account_chain.link.android_identity_conflict', 'Android identity belongs to another MGW profile.'));
            }

            $targetTelegram = $this->identityForProviderSubjectDb(
                $database,
                self::PROVIDER_TELEGRAM,
                (string)$row['target_telegram_subject'],
                true
            );
            if ($targetTelegram === null || (string)$targetTelegram['mgw_id'] !== $targetMgw) {
                throw new AccountLinkException('telegram_identity_changed', ServerLocalization::copy('server.account_chain.link.telegram_identity_changed', 'Telegram identity changed before linking completed.'));
            }
            $targetAndroid = $this->identityForMgwDb($database, $targetMgw, self::PROVIDER_ANDROID, true);
            if ($targetAndroid !== null
                && !hash_equals($subject, (string)$targetAndroid['provider_subject'])) {
                throw new AccountLinkException('target_android_conflict', ServerLocalization::copy('server.account_chain.link.target_android_linked', 'The target MGW profile is already linked to another Android device.'));
            }

            $foreignSessions = (int)$database->fetchValue(
                "SELECT COUNT(*) FROM mgw_sessions
                 WHERE mgw_id=:mgw_id AND provider<>:provider",
                ['mgw_id'=>$sourceMgw, 'provider'=>self::PROVIDER_ANDROID]
            );
            $foreignDevices = (int)$database->fetchValue(
                "SELECT COUNT(*) FROM mgw_devices
                 WHERE mgw_id=:mgw_id AND platform<>'android'",
                ['mgw_id'=>$sourceMgw]
            );
            if ($foreignSessions > 0 || $foreignDevices > 0) {
                throw new AccountLinkException('source_not_pristine', ServerLocalization::copy('server.account_chain.link.source_other_device_or_session', 'The temporary Android profile already contains another device or session.'));
            }

            $sourceDevices = $database->fetchAll(
                'SELECT device_id, device_key_hash FROM mgw_devices WHERE mgw_id=:mgw_id' . $this->forUpdate($database),
                ['mgw_id'=>$sourceMgw]
            );
            foreach ($sourceDevices as $device) {
                $collision = (int)$database->fetchValue(
                    'SELECT COUNT(*) FROM mgw_devices
                     WHERE mgw_id=:target_mgw_id AND device_key_hash=:device_key_hash',
                    [
                        'target_mgw_id'=>$targetMgw,
                        'device_key_hash'=>(string)$device['device_key_hash'],
                    ]
                );
                if ($collision > 0) {
                    throw new AccountLinkException('target_device_conflict', ServerLocalization::copy('server.account_chain.link.target_device_conflict', 'The Android device is already registered in the target profile.'));
                }
            }

            $database->execute(
                'UPDATE mgw_identities SET mgw_id=:target_mgw_id
                 WHERE mgw_id=:source_mgw_id AND provider=:provider AND provider_subject=:provider_subject',
                [
                    'target_mgw_id'=>$targetMgw,
                    'source_mgw_id'=>$sourceMgw,
                    'provider'=>self::PROVIDER_ANDROID,
                    'provider_subject'=>$subject,
                ]
            );
            $database->execute(
                'UPDATE mgw_devices SET mgw_id=:target_mgw_id WHERE mgw_id=:source_mgw_id',
                ['target_mgw_id'=>$targetMgw, 'source_mgw_id'=>$sourceMgw]
            );
            $database->execute(
                'UPDATE mgw_sessions SET mgw_id=:target_mgw_id
                 WHERE mgw_id=:source_mgw_id AND provider=:provider',
                [
                    'target_mgw_id'=>$targetMgw,
                    'source_mgw_id'=>$sourceMgw,
                    'provider'=>self::PROVIDER_ANDROID,
                ]
            );
            $database->execute(
                "UPDATE mgw_users
                 SET status='linked_retired', updated_at_utc=:updated_at
                 WHERE mgw_id=:source_mgw_id AND status='active'",
                ['updated_at'=>$this->timestamp(), 'source_mgw_id'=>$sourceMgw]
            );
            $database->execute(
                "UPDATE mgw_account_link_challenges
                 SET link_status='db_linked', db_linked_at_utc=:db_linked_at, last_error_code=NULL
                 WHERE challenge_id=:challenge_id AND link_status='confirmed'",
                ['db_linked_at'=>$this->timestamp(), 'challenge_id'=>$challengeId]
            );
        });
    }

    private function assertSourcePristine(array $row): void
    {
        $sourceMgw = (string)$row['source_mgw_id'];
        $sourceSubject = (string)$row['source_android_subject'];
        $legacyUserId = (string)$row['source_legacy_user_id'];

        $userRows = $this->database->fetchAll(
            'SELECT status FROM mgw_users WHERE mgw_id=:mgw_id',
            ['mgw_id'=>$sourceMgw]
        );
        if (count($userRows) !== 1 || (string)$userRows[0]['status'] !== 'active') {
            throw new AccountLinkException('source_not_pristine', ServerLocalization::copy('server.account_chain.link.source_not_pristine', 'The temporary Android profile is no longer in its original state.'));
        }

        $identities = $this->database->fetchAll(
            'SELECT provider, provider_subject FROM mgw_identities WHERE mgw_id=:mgw_id',
            ['mgw_id'=>$sourceMgw]
        );
        if (count($identities) !== 1
            || (string)$identities[0]['provider'] !== self::PROVIDER_ANDROID
            || !hash_equals($sourceSubject, (string)$identities[0]['provider_subject'])) {
            throw new AccountLinkException('source_not_pristine', ServerLocalization::copy('server.account_chain.link.source_extra_identity', 'The temporary Android profile already contains an additional identity.'));
        }

        $nonStarterInventory = (int)$this->database->fetchValue(
            'SELECT COUNT(*)
             FROM mgw_inventory_items i
             INNER JOIN mgw_product_catalog c ON c.item_id=i.item_id
             WHERE i.mgw_id=:mgw_id AND c.starter_grant<>1',
            ['mgw_id'=>$sourceMgw]
        );
        $ratingRows = (int)$this->database->fetchValue(
            'SELECT COUNT(*) FROM mgw_game_rating_scores WHERE mgw_id=:mgw_id',
            ['mgw_id'=>$sourceMgw]
        );
        $accountRequests = (int)$this->database->fetchValue(
            'SELECT COUNT(*) FROM mgw_account_data_requests WHERE mgw_id=:mgw_id',
            ['mgw_id'=>$sourceMgw]
        );
        $activeReservations = (int)$this->database->fetchValue(
            "SELECT COUNT(*) FROM mgw_reservations
             WHERE mgw_id=:mgw_id AND status='active'",
            ['mgw_id'=>$sourceMgw]
        );
        if ($nonStarterInventory > 0 || $ratingRows > 0 || $accountRequests > 0 || $activeReservations > 0) {
            throw new AccountLinkException(
                'source_not_pristine',
                ServerLocalization::copy('server.account_chain.link.source_activity_or_purchases', 'The temporary Android profile already has activity or purchases. Automatic linking was stopped.')
            );
        }

        $activityChecks = [
            ['mgw_match_players', ['mgw_id']],
            ['mgw_match_queue', ['mgw_id']],
            ['mgw_invites', ['inviter_mgw_id', 'invitee_mgw_id']],
            ['mgw_social_relations', ['user_low_mgw_id', 'user_high_mgw_id', 'requested_by_mgw_id']],
            ['mgw_player_reports', ['reporter_mgw_id', 'target_mgw_id']],
            ['mgw_hidden_skill_scores', ['mgw_id']],
            ['mgw_hidden_skill_outcomes', ['player_a_mgw_id', 'player_b_mgw_id']],
            ['mgw_tournament_registrations', ['mgw_id']],
            ['mgw_tournament_results', ['mgw_id']],
            ['mgw_tournament_reward_entitlements', ['mgw_id']],
            ['mgw_tournament_golden_tickets', ['mgw_id']],
            ['mgw_support_tickets', ['requester_mgw_id']],
            ['mgw_moderation_actions', ['target_mgw_id']],
            ['mgw_moderation_appeals', ['target_mgw_id']],
            ['mgw_compensations', ['mgw_id']],
        ];
        foreach ($activityChecks as [$table, $columns]) {
            $conditions = [];
            $parameters = [];
            foreach ($columns as $index => $column) {
                $parameter = 'mgw_id_' . $index;
                $conditions[] = $column . '=:' . $parameter;
                $parameters[$parameter] = $sourceMgw;
            }
            $count = (int)$this->database->fetchValue(
                'SELECT COUNT(*) FROM ' . $table . ' WHERE (' . implode(' OR ', $conditions) . ')',
                $parameters
            );
            if ($count > 0) {
                throw new AccountLinkException(
                    'source_not_pristine',
                    ServerLocalization::copy('server.account_chain.link.source_user_activity', 'The temporary Android profile already contains user activity. Automatic linking was stopped.')
                );
            }
        }

        $starterAmount = $this->starterAmount();
        $ownership = $this->ownershipForMgwDb($this->database, $sourceMgw, false);
        if ($ownership === null || (string)$ownership['legacy_user_id'] !== $legacyUserId) {
            throw new AccountLinkException('source_not_pristine', ServerLocalization::copy('server.account_chain.link.source_owner_changed', 'The owner of the temporary Android profile changed.'));
        }
        $balanceRows = $this->database->fetchAll(
            "SELECT available_amount, reserved_amount
             FROM mgw_balances
             WHERE account_ref=:account_ref AND asset_code='mgw_coin'",
            ['account_ref'=>(string)$ownership['account_ref']]
        );
        if (count($balanceRows) > 1) {
            throw new AccountLinkException('source_not_pristine', ServerLocalization::copy('server.account_chain.link.source_balance_ambiguous', 'The temporary Android profile balance is ambiguous.'));
        }
        if ($balanceRows !== []) {
            $available = (int)$balanceRows[0]['available_amount'];
            $reserved = (int)$balanceRows[0]['reserved_amount'];
            if ($reserved !== 0 || !in_array($available, [0, $starterAmount], true)) {
                throw new AccountLinkException(
                    'source_not_pristine',
                    ServerLocalization::copy('server.account_chain.link.source_balance_changed', 'The temporary Android profile balance already changed. Automatic linking was stopped.')
                );
            }
        }

        $this->storage->readOnly(function (array $data) use ($sourceMgw, $legacyUserId, $ownership, $starterAmount): void {
            $this->assertPristineJsonData(
                $data,
                $sourceMgw,
                $legacyUserId,
                (string)$ownership['account_ref'],
                $starterAmount
            );
        });
    }

    private function assertPristineJsonData(
        array $data,
        string $sourceMgw,
        string $legacyUserId,
        string $accountRef,
        int $starterAmount
    ): void {
        $user = $data['users'][$legacyUserId] ?? null;
        if (!is_array($user)
            || (string)($user['mgw_id'] ?? '') !== $sourceMgw
            || (string)($user['id'] ?? '') !== $legacyUserId) {
            throw new AccountLinkException('source_not_pristine', ServerLocalization::copy('server.account_chain.link.source_runtime_missing', 'The temporary Android runtime profile was not found.'));
        }
        if ((string)($user['status'] ?? 'idle') !== 'idle'
            || !empty($user['current_game_id'])
            || (int)($user['balance'] ?? -1) !== $starterAmount) {
            throw new AccountLinkException(
                'source_not_pristine',
                ServerLocalization::copy('server.account_chain.link.source_already_used', 'The temporary Android profile was already used. Automatic linking was stopped.')
            );
        }

        foreach ([
            'games_played','wins','losses','draws',
            'match_games_this_week','match_games_prev_week',
            'bot_games_played','bot_wins','bot_losses','bot_draws','bot_win_streak'
        ] as $key) {
            if ((int)($user['stats'][$key] ?? 0) !== 0) {
                throw new AccountLinkException('source_not_pristine', ServerLocalization::copy('server.account_chain.link.source_game_stats', 'The temporary Android profile already contains game statistics.'));
            }
        }
        if (!empty($user['weekly_match_first_game_grants'])
            || (int)($user['gold_deposited_total'] ?? 0) !== 0
            || (int)($user['gold_wagered_total'] ?? 0) !== 0
            || (int)($user['gold_shop_spent_total'] ?? 0) !== 0) {
            throw new AccountLinkException('source_not_pristine', ServerLocalization::copy('server.account_chain.link.source_economy_activity', 'The temporary Android profile already contains economy activity.'));
        }

        $welcomeTotal = 0;
        $welcomeCount = 0;
        foreach ((array)($data['transactions'] ?? []) as $transaction) {
            if (!is_array($transaction)
                || !$this->recordReferences($transaction, [$legacyUserId, $sourceMgw, $accountRef])) {
                continue;
            }
            if ((string)($transaction['category'] ?? '') !== 'welcome_bonus') {
                throw new AccountLinkException('source_not_pristine', ServerLocalization::copy('server.account_chain.link.source_non_start_transaction', 'The temporary Android profile already contains a non-start transaction.'));
            }
            $welcomeCount++;
            $welcomeTotal += max(0, (int)($transaction['amount'] ?? 0));
        }
        if ($welcomeCount !== 1 || $welcomeTotal !== $starterAmount) {
            throw new AccountLinkException('source_not_pristine', ServerLocalization::copy('server.account_chain.link.source_start_balance_ambiguous', 'The temporary Android profile starting balance is ambiguous.'));
        }

        foreach ((array)($data['users'] ?? []) as $otherUserId => $otherUser) {
            if ((string)$otherUserId === $legacyUserId) continue;
            if ($this->recordReferences($otherUser, [$legacyUserId, $sourceMgw, $accountRef])) {
                throw new AccountLinkException(
                    'source_not_pristine',
                    ServerLocalization::copy('server.account_chain.link.source_other_user_state', 'The temporary Android profile is already linked to other user state.')
                );
            }
        }

        foreach ($data as $section => $value) {
            if (in_array((string)$section, ['users','transactions','notifications'], true)) continue;
            if ($this->recordReferences($value, [$legacyUserId, $sourceMgw, $accountRef])) {
                throw new AccountLinkException(
                    'source_not_pristine',
                    ServerLocalization::copy('server.account_chain.link.source_game_or_social_data', 'The temporary Android profile is already linked to game or social data.')
                );
            }
        }
    }

    private function cleanupSourceRuntime(array $row): void
    {
        $sourceMgw = (string)$row['source_mgw_id'];
        $legacyUserId = (string)$row['source_legacy_user_id'];

        // Presence is keyed by the active legacy runtime owner. During account
        // link the Android identity moves from its temporary legacy owner onto
        // the existing Telegram owner. Retire the old owner immediately so its
        // still-live document lease cannot keep one canonical person counted as
        // two online accounts until the ordinary 75-second presence window ends.
        $this->presence?->retireAccount($legacyUserId);

        $ownership = $this->ownershipForMgwDb($this->database, $sourceMgw, false);
        if ($ownership === null) return;
        $accountRef = (string)$ownership['account_ref'];
        $starterAmount = $this->starterAmount();

        $this->storage->transaction(function (array &$data) use (
            $sourceMgw,
            $legacyUserId,
            $accountRef,
            $starterAmount
        ): void {
            if (isset($data['users'][$legacyUserId])) {
                $this->assertPristineJsonData($data, $sourceMgw, $legacyUserId, $accountRef, $starterAmount);
            }

            unset($data['users'][$legacyUserId]);
            foreach (['transactions','notifications'] as $section) {
                if (!isset($data[$section]) || !is_array($data[$section])) continue;
                $data[$section] = array_values(array_filter(
                    $data[$section],
                    fn(mixed $record): bool => !is_array($record)
                        || !$this->recordReferences($record, [$legacyUserId, $sourceMgw, $accountRef])
                ));
            }
        });
    }

    private function retireSourceBalance(array $row): int
    {
        $sourceMgw = (string)$row['source_mgw_id'];
        $ownership = $this->ownershipForMgwDb($this->database, $sourceMgw, false);
        if ($ownership === null) return max(0, (int)($row['retired_balance_amount'] ?? 0));

        $ledger = new LedgerWriteService($this->database);
        $balance = $ledger->getBalance((string)$ownership['account_ref'], 'mgw_coin');
        if ($balance === null) return 0;

        $available = (int)($balance['available_amount'] ?? 0);
        $reserved = (int)($balance['reserved_amount'] ?? 0);
        if ($reserved !== 0 || $available < 0 || $available > $this->starterAmount()) {
            throw new AccountLinkException('source_not_pristine', ServerLocalization::copy('server.account_chain.link.source_ledger_activity', 'The temporary Android profile ledger already contains activity.'));
        }
        if ($available === 0) return 0;

        $result = $ledger->postAvailableDelta([
            'operation_key'=>'account_link_retire:' . (string)$row['challenge_id'],
            'account_ref'=>(string)$ownership['account_ref'],
            'mgw_id'=>$sourceMgw,
            'legacy_user_id'=>(string)$ownership['legacy_user_id'],
            'asset_code'=>'mgw_coin',
            'available_delta'=>-$available,
            'category'=>'account_link_retirement',
            'source_type'=>'account_link',
            'source_ref'=>(string)$row['challenge_id'],
            'metadata'=>[
                'target_mgw_id'=>(string)$row['target_mgw_id'],
                'reason'=>'discard_temporary_android_starter_balance',
            ],
        ]);
        $remaining = (int)($result['balance']['available_amount'] ?? -1);
        if ($remaining !== 0) {
            throw new RuntimeException('Temporary Android balance retirement did not reach zero.');
        }
        return $available;
    }

    private function completeLink(array $row, int $retiredBalance): void
    {
        $this->database->transaction(function (DatabaseConnectionInterface $database) use ($row, $retiredBalance): void {
            $current = $this->challengeById($database, (string)$row['challenge_id'], true);
            if ((string)$current['link_status'] === 'linked') return;
            if ((string)$current['link_status'] !== 'db_linked') {
                throw new AccountLinkException('link_state_invalid', ServerLocalization::copy('server.account_chain.link.state_changed', 'The account-link state changed.'));
            }

            $targetOwnership = $this->ownershipForMgwDb($database, (string)$current['target_mgw_id'], true);
            if ($targetOwnership === null
                || (string)$targetOwnership['legacy_user_id'] !== (string)$current['target_legacy_user_id']) {
                throw new AccountLinkException('target_ownership_changed', ServerLocalization::copy('server.account_chain.link.target_owner_changed', 'The owner of the target MGW profile changed.'));
            }

            $database->execute(
                'DELETE FROM mgw_account_ownership
                 WHERE mgw_id=:source_mgw_id AND legacy_user_id=:source_legacy_user_id',
                [
                    'source_mgw_id'=>(string)$current['source_mgw_id'],
                    'source_legacy_user_id'=>(string)$current['source_legacy_user_id'],
                ]
            );
            $database->execute(
                "UPDATE mgw_account_link_challenges
                 SET link_status='linked',
                     finalized_at_utc=:finalized_at,
                     retired_balance_amount=:retired_balance_amount,
                     last_error_code=NULL
                 WHERE challenge_id=:challenge_id AND link_status='db_linked'",
                [
                    'finalized_at'=>$this->timestamp(),
                    'retired_balance_amount'=>$retiredBalance,
                    'challenge_id'=>(string)$current['challenge_id'],
                ]
            );
        });
    }

    private function linkedResult(string $targetMgwId, bool $alreadyLinked): array
    {
        $ownership = $this->ownershipForMgwDb($this->database, $targetMgwId, false);
        $target = $this->targetSummary($targetMgwId);
        return [
            'status'=>'linked',
            'already_linked'=>$alreadyLinked,
            'target'=>$target,
            'runtime_user_id'=>$ownership['legacy_user_id'] ?? null,
        ];
    }

    private function targetSummary(string $mgwId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT mgw_id, nickname, display_name, equipped_avatar_item_id, status
             FROM mgw_users WHERE mgw_id=:mgw_id LIMIT 1',
            ['mgw_id'=>$mgwId]
        );
        if ($rows === [] || (string)($rows[0]['status'] ?? '') !== 'active') {
            throw new AccountLinkException('target_unavailable', ServerLocalization::copy('server.account_chain.link.target_unavailable', 'The target MGW profile is unavailable.'));
        }
        return [
            'mgw_id'=>$mgwId,
            'nickname'=>(string)($rows[0]['nickname'] ?? $rows[0]['display_name'] ?? ServerLocalization::copy('server.account_chain.common.player_fallback', 'Player')),
            'avatar_item_id'=>trim((string)($rows[0]['equipped_avatar_item_id'] ?? '')),
        ];
    }

    private function androidContext(array $androidUser): array
    {
        if ((string)($androidUser['mgw_identity_provider'] ?? '') !== self::PROVIDER_ANDROID) {
            throw new AccountLinkException('android_auth_required', ServerLocalization::copy('server.account_chain.link.android_auth_required', 'Account linking must be started from the Android app.'), 403);
        }
        $mgwId = trim((string)($androidUser['mgw_id'] ?? ''));
        if (!MgwIdGenerator::isValid($mgwId)) {
            throw new AccountLinkException('android_identity_missing', ServerLocalization::copy('server.account_chain.link.android_profile_missing', 'The Android MGW profile was not found.'), 403);
        }
        $identity = $this->identityForMgw($mgwId, self::PROVIDER_ANDROID);
        if ($identity === null) {
            throw new AccountLinkException('android_identity_missing', ServerLocalization::copy('server.account_chain.link.android_identity_missing', 'Android identity was not found.'), 403);
        }
        $ownership = $this->ownershipForMgwDb($this->database, $mgwId, false);
        if ($ownership === null) {
            throw new AccountLinkException('ownership_missing', ServerLocalization::copy('server.account_chain.link.ownership_missing', 'The MGW profile owner was not found.'), 409);
        }
        return [
            'mgw_id'=>$mgwId,
            'android_subject'=>(string)$identity['provider_subject'],
            'legacy_user_id'=>(string)$ownership['legacy_user_id'],
            'account_ref'=>(string)$ownership['account_ref'],
        ];
    }

    private function assertAndroidChallengeOwner(array $row, array $context): void
    {
        $subject = (string)($row['source_android_subject'] ?? '');
        if ($subject === '' || !hash_equals($subject, (string)$context['android_subject'])) {
            throw new AccountLinkException('challenge_owner_mismatch', ServerLocalization::copy('server.account_chain.link.challenge_device_mismatch', 'This account link belongs to another Android device.'), 403);
        }

        $status = (string)($row['link_status'] ?? '');
        $allowedMgw = in_array($status, ['db_linked','linked'], true)
            ? (string)($row['target_mgw_id'] ?? '')
            : (string)($row['source_mgw_id'] ?? '');
        if ($allowedMgw === '' || (string)$context['mgw_id'] !== $allowedMgw) {
            throw new AccountLinkException('challenge_owner_mismatch', ServerLocalization::copy('server.account_chain.link.challenge_profile_changed', 'The MGW profile for this account link changed.'), 403);
        }
    }

    private function expireIfNeeded(array $row): array
    {
        if (!in_array((string)$row['link_status'], ['pending','claimed','confirmed'], true)) return $row;
        if (!$this->isExpired($row)) return $row;

        $this->database->execute(
            "UPDATE mgw_account_link_challenges
             SET link_status='expired'
             WHERE challenge_id=:challenge_id AND link_status IN ('pending','claimed','confirmed')",
            ['challenge_id'=>(string)$row['challenge_id']]
        );
        $row['link_status'] = 'expired';
        return $row;
    }

    private function assertChallengeUsable(
        DatabaseConnectionInterface $database,
        array $row,
        array $allowedStatuses
    ): void {
        if ($this->isExpired($row)) {
            $database->execute(
                "UPDATE mgw_account_link_challenges
                 SET link_status='expired'
                 WHERE challenge_id=:challenge_id AND link_status IN ('pending','claimed','confirmed')",
                ['challenge_id'=>(string)$row['challenge_id']]
            );
            throw new AccountLinkException('challenge_expired', ServerLocalization::copy('server.account_chain.link.challenge_expired', 'The account-link URL expired. Create a new one.'), 410);
        }
        if (!in_array((string)$row['link_status'], $allowedStatuses, true)) {
            throw new AccountLinkException('challenge_state_invalid', ServerLocalization::copy('server.account_chain.link.challenge_state_invalid', 'This account-link attempt is no longer available.'));
        }
    }

    private function challengeById(
        DatabaseConnectionInterface $database,
        string $challengeId,
        bool $lock
    ): array {
        $rows = $database->fetchAll(
            'SELECT * FROM mgw_account_link_challenges
             WHERE challenge_id=:challenge_id' . ($lock ? $this->forUpdate($database) : ''),
            ['challenge_id'=>$challengeId]
        );
        if (count($rows) !== 1) {
            throw new AccountLinkException('challenge_not_found', ServerLocalization::copy('server.account_chain.link.challenge_not_found', 'The account-link attempt was not found.'), 404);
        }
        return $rows[0];
    }

    private function challengeByToken(
        DatabaseConnectionInterface $database,
        string $tokenHash,
        bool $lock
    ): array {
        $rows = $database->fetchAll(
            'SELECT * FROM mgw_account_link_challenges
             WHERE token_sha256=:token_sha256' . ($lock ? $this->forUpdate($database) : ''),
            ['token_sha256'=>$tokenHash]
        );
        if (count($rows) !== 1) {
            throw new AccountLinkException('challenge_not_found', ServerLocalization::copy('server.account_chain.link.link_not_found', 'The account-link URL was not found.'), 404);
        }
        return $rows[0];
    }

    private function identityForMgw(string $mgwId, string $provider): ?array
    {
        return $this->identityForMgwDb($this->database, $mgwId, $provider, false);
    }

    private function identityForMgwDb(
        DatabaseConnectionInterface $database,
        string $mgwId,
        string $provider,
        bool $lock
    ): ?array {
        $rows = $database->fetchAll(
            'SELECT i.mgw_id, i.provider, i.provider_subject, i.provider_username,
                    u.status, u.nickname, u.display_name
             FROM mgw_identities i
             INNER JOIN mgw_users u ON u.mgw_id=i.mgw_id
             WHERE i.mgw_id=:mgw_id AND i.provider=:provider'
             . ($lock ? $this->forUpdate($database) : ''),
            ['mgw_id'=>$mgwId, 'provider'=>$provider]
        );
        if (count($rows) > 1) {
            throw new AccountLinkException('identity_ambiguous', ServerLocalization::copy('server.account_chain.link.identity_ambiguous', 'MGW identity is ambiguous.'));
        }
        return $rows[0] ?? null;
    }

    private function identityForProviderSubjectDb(
        DatabaseConnectionInterface $database,
        string $provider,
        string $subject,
        bool $lock
    ): ?array {
        $rows = $database->fetchAll(
            'SELECT i.mgw_id, i.provider, i.provider_subject, i.provider_username,
                    u.status, u.nickname, u.display_name
             FROM mgw_identities i
             INNER JOIN mgw_users u ON u.mgw_id=i.mgw_id
             WHERE i.provider=:provider AND i.provider_subject=:provider_subject'
             . ($lock ? $this->forUpdate($database) : ''),
            ['provider'=>$provider, 'provider_subject'=>$subject]
        );
        if (count($rows) > 1) {
            throw new AccountLinkException('identity_ambiguous', ServerLocalization::copy('server.account_chain.link.identity_ambiguous', 'MGW identity is ambiguous.'));
        }
        return $rows[0] ?? null;
    }

    private function ownershipForMgwDb(
        DatabaseConnectionInterface $database,
        string $mgwId,
        bool $lock
    ): ?array {
        $rows = $database->fetchAll(
            'SELECT account_ref, mgw_id, legacy_user_id, ownership_status
             FROM mgw_account_ownership
             WHERE mgw_id=:mgw_id' . ($lock ? $this->forUpdate($database) : ''),
            ['mgw_id'=>$mgwId]
        );
        if (count($rows) > 1) {
            throw new AccountLinkException('ownership_ambiguous', ServerLocalization::copy('server.account_chain.link.ownership_ambiguous', 'The MGW profile owner is ambiguous.'));
        }
        if ($rows === []) return null;
        if ((string)$rows[0]['ownership_status'] !== 'active') return null;
        return $rows[0];
    }

    private function starterAmount(): int
    {
        $current = (new EconomyConfigService($this->database))->current();
        $amount = (int)($current['config']['bonuses']['starter'] ?? -1);
        if ($amount < 0) {
            throw new RuntimeException('Canonical starter amount is unavailable.');
        }
        return $amount;
    }

    private function recordReferences(mixed $value, array $needles): bool
    {
        if (is_array($value)) {
            foreach ($value as $child) {
                if ($this->recordReferences($child, $needles)) return true;
            }
            return false;
        }
        if (!is_string($value) && !is_int($value)) return false;
        $candidate = (string)$value;
        foreach ($needles as $needle) {
            if ($needle !== '' && hash_equals((string)$needle, $candidate)) return true;
        }
        return false;
    }

    private function recordCleanupError(string $challengeId, Throwable $error): void
    {
        $code = $error instanceof AccountLinkException ? $error->reason : 'cleanup_failed';
        try {
            $this->database->execute(
                'UPDATE mgw_account_link_challenges
                 SET last_error_code=:last_error_code
                 WHERE challenge_id=:challenge_id',
                ['last_error_code'=>substr($code, 0, 48), 'challenge_id'=>$challengeId]
            );
        } catch (Throwable) {
            // Preserve the original exception; retry remains safe from db_linked.
        }
    }

    private function isExpired(array $row): bool
    {
        $expiry = strtotime((string)($row['expires_at_utc'] ?? '') . ' UTC');
        return $expiry === false || $expiry < time();
    }

    private function normalizeChallengeId(string $challengeId): string
    {
        $challengeId = strtolower(trim($challengeId));
        if (preg_match(self::CHALLENGE_PATTERN, $challengeId) !== 1) {
            throw new AccountLinkException('challenge_invalid', ServerLocalization::copy('server.account_chain.link.challenge_invalid', 'The account-link identifier is invalid.'), 400);
        }
        return $challengeId;
    }

    private function normalizeSubject(string $subject): string
    {
        $subject = trim($subject);
        if ($subject === '' || strlen($subject) > 191) {
            throw new AccountLinkException('identity_invalid', ServerLocalization::copy('server.account_chain.link.telegram_identity_invalid', 'Telegram identity is invalid.'), 400);
        }
        return $subject;
    }

    private function assertEnabled(): void
    {
        if (!$this->enabled()) {
            throw new AccountLinkException('linking_unavailable', ServerLocalization::copy('server.account_chain.link.unavailable', 'Account linking is temporarily unavailable.'), 404);
        }
    }

    private function forUpdate(DatabaseConnectionInterface $database): string
    {
        return $database->driver() === 'sqlite' ? '' : ' FOR UPDATE';
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function timestamp(): string
    {
        return $this->now()->format('Y-m-d H:i:s.u');
    }
}
