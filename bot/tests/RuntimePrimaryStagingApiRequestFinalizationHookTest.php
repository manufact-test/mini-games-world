<?php
declare(strict_types=1);

final class DatabasePrimaryStateStorageAdapter
{
    public const DRIVER = 'database';
    public function driver(): string { return self::DRIVER; }
}
final class RuntimePrimaryStagingRequestFinalizer
{
    public int $calls = 0;
    public bool $valid = true;
    public function finalize(
        DatabasePrimaryStateStorageAdapter $storage,
        array $resolutionReport
    ): array {
        $this->calls++;
        if (!$this->valid) {
            return ['ok' => false];
        }
        return [
            'ok' => true,
            'action' => 'request_state_projected_and_audited',
            'api_only' => true,
            'baseline_state_revision' => 3,
            'final_state_revision' => 4,
            'final_state_sha256' => str_repeat('a', 64),
            'worker_tick_count' => 1,
            'projection_event_status' => 'completed',
            'remaining_session_revisions' => 2,
            'read_only_audit' => true,
            'state_unchanged_during_audit' => true,
            'legacy_json_bridges_suppressed' => true,
            'webhook_allowed' => false,
            'production_changed' => false,
        ];
    }
}
final class RuntimePrimaryEntrypointStorageContext
{
    public static bool $installed = true;
    public static ?DatabasePrimaryStateStorageAdapter $storage = null;
    public static array $report = [];
    public static function installed(): bool { return self::$installed; }
    public static function storage(): DatabasePrimaryStateStorageAdapter
    {
        if (self::$storage === null) throw new RuntimeException('missing storage');
        return self::$storage;
    }
    public static function safeReport(): array { return self::$report; }
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingApiRequestFinalizationHook.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $messagePart) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($messagePart))) return;
        throw new RuntimeException('Unexpected exception: ' . $error->getMessage());
    }
    throw new RuntimeException('Expected exception was not thrown.');
};

$storage = new DatabasePrimaryStateStorageAdapter();
$finalizer = new RuntimePrimaryStagingRequestFinalizer();
$resolution = ['resolved' => true];
RuntimePrimaryEntrypointStorageContext::$storage = $storage;
RuntimePrimaryEntrypointStorageContext::$report = [
    'entrypoint' => 'api',
    'storage_driver' => 'database',
    'request_finalizer_registered' => true,
    'dynamic_session_readiness' => true,
    'legacy_json_bridges_suppressed' => true,
];
$hook = new RuntimePrimaryStagingApiRequestFinalizationHook(
    $storage,
    $finalizer,
    $resolution
);
$before = $hook->safeReport();
$assertTrue(($before['attempted'] ?? true) === false, 'Fresh hook must not be attempted');
$hook();
$assertTrue($finalizer->calls === 1, 'Hook must call finalizer exactly once');
$report = $hook->safeReport();
$assertTrue(($report['completed'] ?? false) === true, 'Successful hook must report completion');
$assertTrue(($report['final_state_revision'] ?? 0) === 4, 'Hook must preserve safe final revision');
$assertTrue(($report['worker_tick_count'] ?? 0) === 1, 'Hook must preserve safe worker tick count');
$assertTrue(($report['webhook_allowed'] ?? true) === false, 'Hook must forbid webhook');
$assertTrue(($GLOBALS['mgw_api_db_primary_finalization_report']['completed'] ?? false) === true, 'Hook must publish safe request-local report');
$assertThrows(static fn() => $hook(), 'invoked more than once');
$assertTrue($finalizer->calls === 1, 'Second invocation must not call finalizer again');

RuntimePrimaryEntrypointStorageContext::$installed = false;
$lost = new RuntimePrimaryStagingApiRequestFinalizationHook(
    $storage,
    new RuntimePrimaryStagingRequestFinalizer(),
    $resolution
);
$assertThrows(static fn() => $lost(), 'lost its guarded storage context');
RuntimePrimaryEntrypointStorageContext::$installed = true;

$invalidFinalizer = new RuntimePrimaryStagingRequestFinalizer();
$invalidFinalizer->valid = false;
$invalid = new RuntimePrimaryStagingApiRequestFinalizationHook(
    $storage,
    $invalidFinalizer,
    $resolution
);
$assertThrows(static fn() => $invalid(), 'incomplete success contract');

fwrite(STDOUT, "RuntimePrimaryStagingApiRequestFinalizationHookTest passed: {$assertions} assertions.\n");
