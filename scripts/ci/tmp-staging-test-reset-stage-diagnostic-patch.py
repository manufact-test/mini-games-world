from pathlib import Path


def replace_exact(path: str, old: str, new: str) -> None:
    target = Path(path)
    text = target.read_text(encoding='utf-8')
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{path}: expected one replacement, found {count}')
    target.write_text(text.replace(old, new, 1), encoding='utf-8')


service = 'bot/services/StagingTestPlayerStateResetService.php'
replace_exact(
    service,
    """final class StagingTestPlayerStateResetService
{
""",
    """final class StagingTestPlayerResetStageException extends RuntimeException
{
    private const ALLOWED_STAGES = [
        'availability',
        'json_state',
        'notification_cleanup',
        'invite_cleanup',
        'economy',
    ];

    public function __construct(private string $stage, Throwable $previous)
    {
        if (!in_array($stage, self::ALLOWED_STAGES, true)) {
            $stage = 'unknown';
        }
        parent::__construct('Staging test-player reset stage failed.', 0, $previous);
    }

    public function stage(): string
    {
        return $this->stage;
    }
}

final class StagingTestPlayerStateResetService
{
""",
)
replace_exact(
    service,
    """        $this->assertAvailable($server);

        $storage = StorageFactory::createJson((string)($this->config['data_dir'] ?? (__DIR__ . '/../data')));
""",
    """        try {
            $this->assertAvailable($server);
        } catch (Throwable $error) {
            throw new StagingTestPlayerResetStageException('availability', $error);
        }

        $storage = StorageFactory::createJson((string)($this->config['data_dir'] ?? (__DIR__ . '/../data')));
""",
)
replace_exact(
    service,
    """        $snapshot = $storage->transaction(function (array &$data) use (
            &$before,
            &$queueRemoved,
            &$removedInvites,
            &$notificationsRemoved,
            &$gamesFinished
        ): array {
""",
    """        try {
            $snapshot = $storage->transaction(function (array &$data) use (
                &$before,
                &$queueRemoved,
                &$removedInvites,
                &$notificationsRemoved,
                &$gamesFinished
            ): array {
""",
)
replace_exact(
    service,
    """            return $data;
        });

        // Notification cleanup must commit before invite parity audits. The JSON
""",
    """                return $data;
            });
        } catch (Throwable $error) {
            throw new StagingTestPlayerResetStageException('json_state', $error);
        }

        // Notification cleanup must commit before invite parity audits. The JSON
""",
)
replace_exact(
    service,
    """        $notificationCleanup = $this->cleanupRuntimeTestNotificationRows($snapshot);
        $inviteCleanup = $this->cleanupRuntimeInviteRows($snapshot, $removedInvites);

        $economy = new RuntimeEconomyRepository($this->config, $this->router);
        $synchronized = $economy->synchronize($snapshot);
        $audit = $economy->auditParity($snapshot);
        if (($synchronized['ok'] ?? false) !== true || ($audit['ok'] ?? false) !== true) {
            throw new RuntimeException('Staging test-player economy reset did not reach parity.');
        }
""",
    """        try {
            $notificationCleanup = $this->cleanupRuntimeTestNotificationRows($snapshot);
        } catch (Throwable $error) {
            throw new StagingTestPlayerResetStageException('notification_cleanup', $error);
        }
        try {
            $inviteCleanup = $this->cleanupRuntimeInviteRows($snapshot, $removedInvites);
        } catch (Throwable $error) {
            throw new StagingTestPlayerResetStageException('invite_cleanup', $error);
        }

        try {
            $economy = new RuntimeEconomyRepository($this->config, $this->router);
            $synchronized = $economy->synchronize($snapshot);
            $audit = $economy->auditParity($snapshot);
            if (($synchronized['ok'] ?? false) !== true || ($audit['ok'] ?? false) !== true) {
                throw new RuntimeException('Staging test-player economy reset did not reach parity.');
            }
        } catch (Throwable $error) {
            throw new StagingTestPlayerResetStageException('economy', $error);
        }
""",
)

endpoint = 'bot/staging-test-auth.php'
replace_exact(
    endpoint,
    """        $result = $playerResetService()->reset($_SERVER);

        echo json_encode(
""",
    """        try {
            $result = $playerResetService()->reset($_SERVER);
        } catch (StagingTestPlayerResetStageException $error) {
            error_log('[MiniGamesWorld staging test reset] failed stage=' . $error->stage());
            http_response_code(403);
            echo json_encode([
                'ok' => false,
                'service' => 'mini-games-world-staging-test-auth',
                'error' => 'test_player_reset_unavailable',
                'stage' => $error->stage(),
            ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
            exit;
        }

        echo json_encode(
""",
)

Path('bot/tests/StagingTestPlayerResetStageDiagnosticContractTest.php').write_text(r'''<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = file_get_contents($root . '/bot/services/StagingTestPlayerStateResetService.php');
$endpoint = file_get_contents($root . '/bot/staging-test-auth.php');
if (!is_string($service) || !is_string($endpoint)) {
    throw new RuntimeException('Cannot read reset stage diagnostic sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$stages = ['availability','json_state','notification_cleanup','invite_cleanup','economy'];
foreach ($stages as $stage) {
    $assert(str_contains($service, "StagingTestPlayerResetStageException('{$stage}'"), "Missing safe reset stage {$stage}.");
}
$assert(str_contains($service, "parent::__construct('Staging test-player reset stage failed.', 0, $previous);"),
    'Public reset-stage exception must not copy the private previous message.');
$assert(str_contains($endpoint, "'error' => 'test_player_reset_unavailable'")
    && str_contains($endpoint, "'stage' => $error->stage()"),
    'OIDC reset endpoint must return only the fixed safe error and stage.');
$assert(!str_contains($endpoint, '$error->getMessage()'),
    'Staging auth endpoint must never return an exception message.');
$assert(str_contains($endpoint, "error_log('[MiniGamesWorld staging test reset] failed stage=' . $error->stage());"),
    'Server logging must contain only the safe reset stage.');

fwrite(STDOUT, "StagingTestPlayerResetStageDiagnosticContractTest: {$assertions} assertions passed\n");
''', encoding='utf-8')
