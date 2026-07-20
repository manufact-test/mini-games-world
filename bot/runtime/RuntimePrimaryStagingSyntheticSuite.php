<?php
declare(strict_types=1);

final class RuntimePrimaryStagingSyntheticSuite
{
    public function __construct(
        private array $config,
        private RuntimePrimaryStagingStorageResolution $resolution,
        private DatabaseConnectionInterface $database
    ) {
        $environment = strtolower(trim((string)($this->config['environment'] ?? '')));
        if ($environment !== 'staging') {
            throw new RuntimeException('DB-primary synthetic suite is staging-only.');
        }
        if ($this->database->driver() !== 'mysql') {
            throw new RuntimeException('DB-primary synthetic suite requires MySQL/MariaDB.');
        }
        $safe = $this->resolution->safeReport();
        if (($safe['resolved'] ?? false) !== true
            || ($safe['storage_driver'] ?? '') !== 'database'
            || ($safe['projection_outbox_enabled'] ?? false) !== true
            || ($safe['application_entrypoint_routed'] ?? true) !== false) {
            throw new RuntimeException('DB-primary synthetic suite requires a guarded unresolved-entrypoint storage resolution.');
        }
    }

    public function run(): array
    {
        $storage = $this->resolution->storage();
        $beforeStatus = $storage->status();
        $beforeSnapshot = $storage->readOnly(static fn(array $data): array => $data);
        $beforeSha = hash('sha256', $this->canonicalJson($beforeSnapshot));
        $beforeQueue = $this->queueSummary();
        $scenario = null;

        try {
            $storage->transaction(function (array &$db): void {
                $db = $this->normalizedSnapshot($db);
                $nonce = bin2hex(random_bytes(12));
                $firstId = 'synthetic_a_' . $nonce;
                $secondId = 'synthetic_b_' . $nonce;
                if (isset($db['users'][$firstId]) || isset($db['users'][$secondId])) {
                    throw new RuntimeException('Synthetic staging user collision detected.');
                }

                $notifications = new NotificationService();
                $users = new UserService($this->config);
                $weekly = new WeeklyMatchEconomyService($this->config, $notifications);

                $first = $users->ensureUser($db, [
                    'id' => $firstId,
                    'first_name' => 'Synthetic A',
                    'username' => 'synthetic_a',
                    'is_dev_user' => false,
                ]);
                $second = $users->ensureUser($db, [
                    'id' => $secondId,
                    'first_name' => 'Synthetic B',
                    'username' => 'synthetic_b',
                    'is_dev_user' => true,
                ]);
                if ((string)($first['id'] ?? '') !== $firstId
                    || (string)($second['id'] ?? '') !== $secondId) {
                    throw new RuntimeException('Synthetic user creation returned an unexpected identity.');
                }

                $db['users'][$firstId]['last_seen_at'] = '2000-01-01T00:00:00+00:00';
                $updated = $users->ensureUser($db, [
                    'id' => $firstId,
                    'first_name' => 'Synthetic Updated',
                    'username' => 'synthetic_updated',
                ]);
                if ((string)($updated['username'] ?? '') !== 'synthetic_updated') {
                    throw new RuntimeException('Synthetic user idempotent profile update failed.');
                }

                $firstUser =& $db['users'][$firstId];
                $balanceBefore = (int)($firstUser['balance_match'] ?? 0);
                $welcome = $weekly->ensureWelcomeGrant($db, $firstUser);
                if (($welcome['awarded'] ?? false) !== true
                    || (int)($welcome['amount'] ?? 0) <= 0
                    || (int)($firstUser['balance_match'] ?? 0) <= $balanceBefore) {
                    throw new RuntimeException('Synthetic welcome economy grant failed.');
                }
                $welcomeAgain = $weekly->ensureWelcomeGrant($db, $firstUser);
                if (($welcomeAgain['awarded'] ?? true) !== false
                    || ($welcomeAgain['reason'] ?? '') !== 'already_awarded') {
                    throw new RuntimeException('Synthetic welcome economy idempotency failed.');
                }

                $payment = [
                    'id' => 'pay_synthetic_' . $nonce,
                    'user_id' => $firstId,
                    'room' => 'gold',
                    'coins' => 25,
                    'price' => 100,
                    'currency' => 'RUB',
                ];
                $paymentNotice = $notifications->addPaymentDecision($db, $payment, 'applied');
                $paymentNoticeAgain = $notifications->addPaymentDecision($db, $payment, 'applied');
                if (!is_array($paymentNotice)
                    || (string)($paymentNotice['id'] ?? '') === ''
                    || (string)($paymentNoticeAgain['id'] ?? '') !== (string)($paymentNotice['id'] ?? '')) {
                    throw new RuntimeException('Synthetic payment notification idempotency failed.');
                }

                $order = [
                    'id' => 'shop_synthetic_' . $nonce,
                    'user_id' => $firstId,
                    'prize_title' => 'Synthetic prize',
                    'denomination_label' => '100',
                ];
                $orderNotice = $notifications->addShopOrderDecision($db, $order, 'done');
                $orderNoticeAgain = $notifications->addShopOrderDecision($db, $order, 'done');
                if (!is_array($orderNotice)
                    || (string)($orderNoticeAgain['id'] ?? '') !== (string)($orderNotice['id'] ?? '')) {
                    throw new RuntimeException('Synthetic shop notification idempotency failed.');
                }

                $unreadBefore = $notifications->unreadCount($db, $firstId);
                if ($unreadBefore < 3) {
                    throw new RuntimeException('Synthetic notification unread count is incomplete.');
                }
                $notifications->markAllRead($db, $firstId);
                if ($notifications->unreadCount($db, $firstId) !== 0) {
                    throw new RuntimeException('Synthetic notification mark-all-read failed.');
                }

                $db['games']['game_synthetic_' . $nonce] = [
                    'id' => 'game_synthetic_' . $nonce,
                    'status' => 'finished',
                    'room' => 'match',
                    'player_ids' => [$firstId, $secondId],
                    'winner_id' => $firstId,
                    'finished_at' => now_iso(),
                ];
                $stats = $users->profileStats($firstUser, $db);
                if ((int)($stats['games_played'] ?? 0) !== 1
                    || (int)($stats['wins'] ?? 0) !== 1
                    || (int)($stats['losses'] ?? 0) !== 0) {
                    throw new RuntimeException('Synthetic profile statistics calculation failed.');
                }

                $public = $users->publicUser($firstUser);
                if ((string)($public['id'] ?? '') !== $firstId
                    || (int)($public['balance_match'] ?? 0) !== (int)($firstUser['balance_match'] ?? 0)) {
                    throw new RuntimeException('Synthetic public user projection failed.');
                }

                $report = [
                    'user_create_ok' => true,
                    'user_update_ok' => true,
                    'welcome_grant_ok' => true,
                    'welcome_idempotent' => true,
                    'payment_notification_idempotent' => true,
                    'shop_notification_idempotent' => true,
                    'notification_read_cycle_ok' => true,
                    'profile_stats_ok' => true,
                    'public_user_ok' => true,
                    'synthetic_user_count' => 2,
                    'synthetic_notification_count' => count($notifications->userNotifications($db, $firstId, 30)),
                    'synthetic_transaction_count' => count(array_filter(
                        $db['transactions'],
                        static fn(mixed $row): bool => is_array($row)
                            && (string)($row['user_id'] ?? '') === $firstId
                    )),
                    'rollback_requested' => true,
                    'sensitive_identifiers_exposed' => false,
                ];
                throw new RuntimePrimarySyntheticRollback($report);
            });
            throw new RuntimeException('Synthetic staging transaction committed unexpectedly.');
        } catch (RuntimePrimarySyntheticRollback $rollback) {
            $scenario = $rollback->scenarioReport();
        }

        if (!is_array($scenario) || ($scenario['rollback_requested'] ?? false) !== true) {
            throw new RuntimeException('Synthetic staging rollback signal is missing.');
        }

        $afterStatus = $storage->status();
        $afterSnapshot = $storage->readOnly(static fn(array $data): array => $data);
        $afterSha = hash('sha256', $this->canonicalJson($afterSnapshot));
        $afterQueue = $this->queueSummary();
        if ($beforeStatus !== $afterStatus) {
            throw new RuntimeException('DB-primary state status changed after synthetic rollback.');
        }
        if (!hash_equals($beforeSha, $afterSha)) {
            throw new RuntimeException('DB-primary state snapshot changed after synthetic rollback.');
        }
        if ($beforeQueue !== $afterQueue) {
            throw new RuntimeException('Projection outbox changed after synthetic rollback.');
        }

        return [
            'ok' => true,
            'report_type' => 'mvp-14.8.6j-staging-synthetic-suite',
            'action' => 'synthetic_suite_passed_and_rolled_back',
            'environment' => 'staging',
            'storage_driver' => 'database',
            'rollback_driver' => 'json',
            'state_revision' => (int)($afterStatus['revision'] ?? 0),
            'state_sha256' => strtolower(trim((string)($afterStatus['state_sha256'] ?? ''))),
            'scenario' => $scenario,
            'state_unchanged' => true,
            'snapshot_unchanged' => true,
            'outbox_unchanged' => true,
            'transaction_rolled_back' => true,
            'application_entrypoint_routed' => false,
            'application_entrypoints_changed' => false,
            'cron_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => gmdate(DATE_ATOM),
        ];
    }

    private function normalizedSnapshot(array $db): array
    {
        foreach ([
            'users', 'games', 'queue', 'invites', 'notifications',
            'transactions', 'shop_orders', 'payments', 'support', 'system',
        ] as $key) {
            if (!isset($db[$key]) || !is_array($db[$key])) $db[$key] = [];
        }
        return $db;
    }

    private function queueSummary(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT status, COUNT(*) AS event_count,
                    MIN(state_revision) AS min_revision,
                    MAX(state_revision) AS max_revision
             FROM ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE . '
             GROUP BY status ORDER BY status'
        );
        $summary = [];
        foreach ($rows as $row) {
            $status = strtolower(trim((string)($row['status'] ?? '')));
            if ($status === '') continue;
            $summary[$status] = [
                'count' => max(0, (int)($row['event_count'] ?? 0)),
                'min_revision' => max(0, (int)($row['min_revision'] ?? 0)),
                'max_revision' => max(0, (int)($row['max_revision'] ?? 0)),
            ];
        }
        ksort($summary, SORT_STRING);
        return $summary;
    }

    private function canonicalJson(array $value): string
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
