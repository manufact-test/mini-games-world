<?php
declare(strict_types=1);

final class FreezeDrainRehearsalService
{
    private const REQUIRED_DATABASE_MODULES = [
        'accounts',
        'realtime',
        'invites',
        'notifications',
        'economy',
        'history',
        'shop',
        'payments',
        'weekly_bonus',
    ];

    public function __construct(
        private array $config,
        private StorageTransactionInterface $storage,
        private string $controlFile,
        private ?RuntimeStorageRouter $router = null
    ) {
        $this->controlFile = trim($this->controlFile);
        if ($this->controlFile === '') {
            throw new InvalidArgumentException('Cutover rehearsal control file is required.');
        }
        $this->router ??= new RuntimeStorageRouter($this->config);
    }

    public function freeze(): array
    {
        $environment = $this->assertSafeEnvironment();
        $existing = $this->readControl();
        $alreadyFrozen = ($existing['state'] ?? '') === 'frozen';

        if (!$alreadyFrozen) {
            $control = [
                'schema_version' => 1,
                'environment' => $environment,
                'rehearsal_id' => 'rehearsal_' . bin2hex(random_bytes(8)),
                'state' => 'frozen',
                'started_at_utc' => $this->nowUtc(),
                'released_at_utc' => null,
                'release_reason' => '',
                'feature_overrides' => [
                    'features' => [
                        'matchmaking' => false,
                        'invitations' => false,
                    ],
                ],
            ];
            $this->writeControl($control);
            $this->appendHistory('freeze', $control);
        }

        $queueCleanup = $this->storage->transaction(function (array &$data): array {
            $removed = count(is_array($data['queue'] ?? null) ? $data['queue'] : []);
            $resetUsers = 0;

            foreach ($data['users'] ?? [] as &$user) {
                if (!is_array($user) || (string)($user['status'] ?? '') !== 'searching') {
                    continue;
                }
                $user['status'] = 'idle';
                $user['current_game_id'] = null;
                $resetUsers++;
            }
            unset($user);

            $data['queue'] = [];

            return [
                'removed_queue_entries' => $removed,
                'reset_searching_users' => $resetUsers,
                'active_games_untouched' => true,
            ];
        });

        $report = $this->status();
        $report['action'] = $alreadyFrozen ? 'freeze_noop' : 'freeze';
        $report['queue_cleanup'] = $queueCleanup;
        $report['idempotent'] = $alreadyFrozen;
        return $this->withFingerprint($report);
    }

    public function release(string $reason = ''): array
    {
        $environment = $this->assertSafeEnvironment();
        $control = $this->readControl();
        $wasFrozen = ($control['state'] ?? '') === 'frozen';

        if ($control === []) {
            $control = [
                'schema_version' => 1,
                'environment' => $environment,
                'rehearsal_id' => 'rehearsal_' . bin2hex(random_bytes(8)),
                'started_at_utc' => null,
            ];
        }

        $control['state'] = 'released';
        $control['released_at_utc'] = $this->nowUtc();
        $control['release_reason'] = $this->cleanReason($reason);
        $control['feature_overrides'] = [];
        $this->writeControl($control);
        $this->appendHistory('release', $control);

        $report = $this->status();
        $report['action'] = $wasFrozen ? 'release' : 'release_noop';
        $report['idempotent'] = !$wasFrozen;
        return $this->withFingerprint($report);
    }

    public function status(): array
    {
        $environment = strtolower(trim((string)($this->config['environment'] ?? 'production')));
        $control = $this->readControl();
        $freezeActive = ($control['state'] ?? '') === 'frozen'
            && strtolower(trim((string)($control['environment'] ?? ''))) === $environment;

        $snapshot = $this->storage->readOnly(function (array $data): array {
            $activeByGame = [];
            $activeByRoom = [];
            $activeGames = 0;
            foreach ($data['games'] ?? [] as $game) {
                if (!is_array($game) || (string)($game['status'] ?? '') !== 'active') continue;
                $activeGames++;
                $gameType = (string)($game['game_type'] ?? 'tictactoe');
                $room = (string)($game['room'] ?? 'match') === 'gold' ? 'gold' : 'match';
                $activeByGame[$gameType] = (int)($activeByGame[$gameType] ?? 0) + 1;
                $activeByRoom[$room] = (int)($activeByRoom[$room] ?? 0) + 1;
            }
            ksort($activeByGame, SORT_STRING);
            ksort($activeByRoom, SORT_STRING);

            $openInvites = 0;
            foreach ($data['invites'] ?? [] as $invite) {
                if (!is_array($invite)) continue;
                if (in_array((string)($invite['status'] ?? ''), ['draft', 'pending', 'awaiting_start', 'starting'], true)) {
                    $openInvites++;
                }
            }

            $searchingUsers = 0;
            $playingUsers = 0;
            foreach ($data['users'] ?? [] as $user) {
                if (!is_array($user)) continue;
                $status = (string)($user['status'] ?? 'idle');
                if ($status === 'searching') $searchingUsers++;
                if ($status === 'playing') $playingUsers++;
            }

            return [
                'active_games' => $activeGames,
                'active_games_by_type' => $activeByGame,
                'active_games_by_room' => $activeByRoom,
                'queue_entries' => count(is_array($data['queue'] ?? null) ? $data['queue'] : []),
                'open_invites' => $openInvites,
                'searching_users' => $searchingUsers,
                'playing_users' => $playingUsers,
            ];
        });

        $enabledModules = $this->router->enabledModules();
        sort($enabledModules, SORT_STRING);
        $missingModules = array_values(array_diff(self::REQUIRED_DATABASE_MODULES, $enabledModules));

        $drainBlockers = [];
        if (!$freezeActive) $drainBlockers[] = 'freeze is not active';
        if ((int)$snapshot['active_games'] > 0) $drainBlockers[] = 'active games must drain to zero';
        if ((int)$snapshot['queue_entries'] > 0) $drainBlockers[] = 'matchmaking queue must be empty';
        if ((int)$snapshot['searching_users'] > 0) $drainBlockers[] = 'searching users must be reset to idle';

        $switchBlockers = $drainBlockers;
        if (!$this->router->enabled()) $switchBlockers[] = 'database runtime router is disabled';
        if ($missingModules !== []) {
            $switchBlockers[] = 'required database runtime modules are not enabled';
        }

        $report = [
            'ok' => true,
            'report_type' => 'mvp-14.8.4-freeze-drain-rehearsal',
            'environment' => $environment,
            'execution_mode' => 'status',
            'freeze' => [
                'active' => $freezeActive,
                'state' => (string)($control['state'] ?? 'absent'),
                'rehearsal_id' => (string)($control['rehearsal_id'] ?? ''),
                'started_at_utc' => (string)($control['started_at_utc'] ?? ''),
                'released_at_utc' => (string)($control['released_at_utc'] ?? ''),
                'new_matchmaking_blocked' => $freezeActive,
                'new_invitations_blocked' => $freezeActive,
                'active_game_actions_allowed' => true,
            ],
            'drain' => $snapshot + [
                'ready' => $drainBlockers === [],
                'blockers' => $drainBlockers,
            ],
            'database_runtime' => [
                'enabled' => $this->router->enabled(),
                'enabled_modules' => $enabledModules,
                'required_modules' => self::REQUIRED_DATABASE_MODULES,
                'missing_modules' => $missingModules,
                'global_driver' => strtolower(trim((string)($this->config['storage_driver'] ?? 'json'))) ?: 'json',
                'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
            ],
            'switch_rehearsal' => [
                'ready' => $switchBlockers === [],
                'blockers' => array_values(array_unique($switchBlockers)),
                'production_switch_performed' => false,
                'production_allowed' => false,
            ],
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => $this->nowUtc(),
        ];

        return $this->withFingerprint($report);
    }

    private function assertSafeEnvironment(): string
    {
        $environment = strtolower(trim((string)($this->config['environment'] ?? 'production')));
        if (!in_array($environment, ['staging', 'local'], true)) {
            throw new RuntimeException('Freeze/drain rehearsal is allowed only in staging or local.');
        }
        return $environment;
    }

    private function readControl(): array
    {
        if (!is_file($this->controlFile)) return [];
        $raw = file_get_contents($this->controlFile);
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException('Cutover rehearsal control file is empty.');
        }
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Cutover rehearsal control file must contain an object.');
        }
        return $decoded;
    }

    private function writeControl(array $control): void
    {
        $directory = dirname($this->controlFile);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create private cutover control directory.');
        }

        $temporary = $this->controlFile . '.tmp-' . bin2hex(random_bytes(6));
        $encoded = json_encode(
            $control,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        if (file_put_contents($temporary, $encoded, LOCK_EX) === false) {
            throw new RuntimeException('Could not write cutover rehearsal control file.');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $this->controlFile)) {
            @unlink($temporary);
            throw new RuntimeException('Could not activate cutover rehearsal control file.');
        }
        @chmod($this->controlFile, 0600);
    }

    private function appendHistory(string $action, array $control): void
    {
        $record = [
            'action' => $action,
            'environment' => (string)($control['environment'] ?? ''),
            'rehearsal_id' => (string)($control['rehearsal_id'] ?? ''),
            'state' => (string)($control['state'] ?? ''),
            'recorded_at_utc' => $this->nowUtc(),
        ];
        $historyFile = dirname($this->controlFile) . '/cutover-rehearsal-history.jsonl';
        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_put_contents($historyFile, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Could not append cutover rehearsal history.');
        }
        @chmod($historyFile, 0600);
    }

    private function cleanReason(string $reason): string
    {
        $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
        return mb_substr($reason, 0, 200);
    }

    private function withFingerprint(array $report): array
    {
        unset($report['report_fingerprint']);
        $report['report_fingerprint'] = hash('sha256', $this->canonicalJson($report));
        return $report;
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

    private function nowUtc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
    }
}
