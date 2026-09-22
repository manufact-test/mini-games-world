<?php
declare(strict_types=1);

require_once __DIR__ . '/../runtime/RuntimePrimaryProjectionAuditorInterface.php';
require_once __DIR__ . '/../runtime/RuntimePrimaryProjectionOutboxSchemaInstaller.php';
require_once __DIR__ . '/../runtime/RuntimePrimaryProjectionOutboxWriter.php';
require_once __DIR__ . '/../runtime/ProductionPrimaryApplicationEntrypoints.php';

final class StorageFactory
{
    public static function create(array $config): StorageAdapterInterface
    {
        $driver = strtolower(trim((string)($config['storage_driver'] ?? 'json')));
        if ($driver === '') {
            $driver = 'json';
        }

        return match ($driver) {
            'json' => self::createJson((string)($config['data_dir'] ?? '')),
            'database' => self::createDatabasePrimary($config),
            default => throw new RuntimeException('Unsupported storage driver: ' . $driver),
        };
    }

    public static function createJson(string $dataDir): StorageAdapterInterface
    {
        self::installGuardedEntrypointContextIfEligible();
        if (class_exists('ProductionPrimaryEntrypointStorageContext', false)
            && ProductionPrimaryEntrypointStorageContext::installed()) {
            return ProductionPrimaryEntrypointStorageContext::storage();
        }
        if (class_exists('RuntimePrimaryEntrypointStorageContext', false)
            && RuntimePrimaryEntrypointStorageContext::installed()) {
            return RuntimePrimaryEntrypointStorageContext::storage();
        }
        return new JsonStorageAdapter($dataDir);
    }

    public static function createDatabasePrimary(array $config): DatabasePrimaryStateStorageAdapter
    {
        $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
        if (!$databaseConfig->enabled()) {
            throw new RuntimeException('DB-primary storage requires an enabled database configuration.');
        }

        if (array_key_exists('runtime_primary_projection_outbox', $config)
            && !is_array($config['runtime_primary_projection_outbox'])) {
            throw new RuntimeException('runtime_primary_projection_outbox must be a configuration array.');
        }
        $outbox = is_array($config['runtime_primary_projection_outbox'] ?? null)
            ? $config['runtime_primary_projection_outbox']
            : [];
        $outboxEnabled = self::strictBool(
            $outbox['enabled'] ?? false,
            'runtime_primary_projection_outbox.enabled'
        );

        return new DatabasePrimaryStateStorageAdapter(
            PdoConnectionFactory::create($databaseConfig),
            $outboxEnabled ? new RuntimePrimaryProjectionOutboxWriter() : null
        );
    }

    private static function installGuardedEntrypointContextIfEligible(): void
    {
        static $attempted = [];
        static $failures = [];

        $projectRoot = dirname(__DIR__, 2);
        $productionEntrypoint = ProductionPrimaryApplicationEntrypoints::resolve(
            $projectRoot,
            $_SERVER
        );
        $script = basename(trim((string)(
            $_SERVER['SCRIPT_FILENAME']
            ?? $_SERVER['PHP_SELF']
            ?? ''
        )));
        $stagingEntrypoint = match ($script) {
            'api.php', 'admin-read.php' => 'api',
            'webhook.php' => 'webhook',
            default => '',
        };
        $entrypoint = $productionEntrypoint !== ''
            ? $productionEntrypoint
            : $stagingEntrypoint;

        if ($entrypoint === '') return;

        if (isset($failures[$entrypoint])) {
            throw new RuntimeException(
                'Guarded entrypoint storage selection previously failed in this request.',
                0,
                $failures[$entrypoint]
            );
        }
        if ((class_exists('ProductionPrimaryEntrypointStorageContext', false)
                && ProductionPrimaryEntrypointStorageContext::installed())
            || (class_exists('RuntimePrimaryEntrypointStorageContext', false)
                && RuntimePrimaryEntrypointStorageContext::installed())) {
            return;
        }

        $config = $GLOBALS['config'] ?? null;
        $configFile = $GLOBALS['configFile'] ?? null;
        if (!is_array($config) || !is_string($configFile) || trim($configFile) === '') {
            $error = new RuntimeException(
                'Entrypoint storage selector requires the active application config context.'
            );
            $failures[$entrypoint] = $error;
            throw $error;
        }

        $environment = strtolower(trim((string)($config['environment'] ?? 'production')));
        $entrypoint = $environment === 'production'
            ? $productionEntrypoint
            : $stagingEntrypoint;
        if ($entrypoint === '') return;

        if (isset($attempted[$entrypoint])) return;
        $attempted[$entrypoint] = true;

        // The DB-primary rehearsal is explicitly bounded by a short request
        // session. Once that session has expired, normal staging must return to
        // JSON immediately. Do this BEFORE the stale-primary comparison: the old
        // retained rehearsal snapshot can be very large and must not remain on
        // every future cold-start/API critical path merely because private
        // selector config has not yet been toggled off.
        if ($environment === 'staging' && $script === 'api.php') {
            require_once __DIR__ . '/../runtime/RuntimePrimaryStagingRequestSessionConfig.php';
            $requestSession = RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig($config);
            if ($requestSession->enabled() && !$requestSession->activeAt(time())) {
                return;
            }
        }

        // The DB-primary API selector is a bounded staging rehearsal, while
        // JSON remains the rollback/live source outside that rehearsal. If the
        // retained DB-primary snapshot is missing notification events OR still
        // carries an older read/hidden state than rollback JSON, routing a new
        // API request into that stale snapshot makes the projection finalizer
        // compare yesterday's source state with today's canonical DB projection.
        // Fail open only for this proven staging API drift; production and every
        // other selector/readiness failure remain strict.
        if ($environment === 'staging'
            && $script === 'api.php'
            && self::stagingApiPrimaryNotificationSnapshotIsBehind($config)) {
            error_log(
                '[MiniGamesWorld staging DB-primary] notification snapshot is behind rollback JSON; '
                . 'using JSON storage for this API request.'
            );
            return;
        }

        try {
            if ($environment === 'production') {
                require_once __DIR__ . '/../runtime/ProductionPrimaryEntrypointBootstrap.php';
                ProductionPrimaryEntrypointBootstrap::installIfEnabled(
                    $projectRoot,
                    $config,
                    $configFile,
                    $entrypoint
                );
                return;
            }

            require_once __DIR__ . '/../runtime/RuntimePrimaryStagingEntrypointBootstrap.php';
            (new RuntimePrimaryStagingEntrypointStorageSelector(
                $projectRoot,
                $config,
                $configFile,
                $entrypoint
            ))->installIfEnabled();
        } catch (Throwable $error) {
            $failures[$entrypoint] = $error;
            throw $error;
        }
    }

    private static function stagingApiPrimaryNotificationSnapshotIsBehind(array $config): bool
    {
        $dataDir = trim((string)($config['data_dir'] ?? ''));
        if ($dataDir === '') return false;

        try {
            $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
            if (!$databaseConfig->enabled()) return false;

            $rollback = (new JsonStorageAdapter($dataDir))->readOnlySections(
                ['notifications'],
                static fn(array $data): array => $data
            );
            $primary = (new DatabasePrimaryStateStorageAdapter(
                PdoConnectionFactory::create($databaseConfig)
            ))->readOnly(static fn(array $data): array => $data);
        } catch (Throwable) {
            // Do not weaken the selector on an unclassified readiness failure.
            // Only a positively proven stale notification inventory may fall
            // back to JSON; all other failures remain owned by the selector.
            return false;
        }

        $mutableTimestamp = static function (mixed $value): ?string {
            $raw = trim((string)$value);
            if ($raw === '') return null;
            $moment = new DateTimeImmutable($raw);
            return $moment
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s.u');
        };

        $inventory = static function (array $snapshot) use ($mutableTimestamp): array {
            $events = [];
            foreach (is_array($snapshot['notifications'] ?? null)
                ? $snapshot['notifications']
                : [] as $notification) {
                if (!is_array($notification)) continue;
                $userId = trim((string)($notification['user_id'] ?? ''));
                $eventKey = trim((string)($notification['event_key'] ?? ''));
                $notificationId = trim((string)($notification['id'] ?? ''));
                $identity = $eventKey !== '' ? $eventKey : $notificationId;
                if ($userId === '' || $identity === '') continue;
                $events[$userId][$identity] = [
                    'read_at'=>$mutableTimestamp($notification['read_at'] ?? null),
                    'hidden_at'=>$mutableTimestamp($notification['hidden_at'] ?? null),
                ];
            }
            return $events;
        };

        try {
            $rollbackEvents = $inventory($rollback);
            $primaryEvents = $inventory($primary);
        } catch (Throwable) {
            // A malformed timestamp is not a classified stale-snapshot signal.
            // Leave that failure to the guarded selector instead of silently
            // routing around it.
            return false;
        }

        foreach ($rollbackEvents as $userId=>$events) {
            $primaryUserEvents = is_array($primaryEvents[$userId] ?? null)
                ? $primaryEvents[$userId]
                : [];
            foreach ($events as $notificationIdentity=>$rollbackState) {
                if (!array_key_exists($notificationIdentity, $primaryUserEvents)) return true;
                $primaryState = $primaryUserEvents[$notificationIdentity];
                if (($rollbackState['read_at'] ?? null) !== ($primaryState['read_at'] ?? null)
                    || ($rollbackState['hidden_at'] ?? null) !== ($primaryState['hidden_at'] ?? null)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function strictBool(mixed $value, string $label): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value)) {
            if ($value === 0) return false;
            if ($value === 1) return true;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '1', 'true', 'yes', 'on', 'enabled' => true,
                '0', 'false', 'no', 'off', 'disabled' => false,
                default => throw new RuntimeException($label . ' must be a strict boolean value.'),
            };
        }
        throw new RuntimeException($label . ' must be a strict boolean value.');
    }
}
