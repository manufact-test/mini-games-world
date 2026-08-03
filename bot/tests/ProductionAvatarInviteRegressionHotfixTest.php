<?php
declare(strict_types=1);

$root = dirname(__DIR__);

require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/database/DatabaseConfig.php';
require $root . '/storage/RuntimeStorageRouter.php';
require $root . '/realtime/RealtimeDatabaseStore.php';
require $root . '/invites/RuntimeInviteRepository.php';
require $root . '/runtime/RuntimePrimaryModuleProjectorInterface.php';
require $root . '/runtime/ProductionRuntimeInvitesModuleProjector.php';

final class InviteRegressionFakeDatabase implements DatabaseConnectionInterface
{
    /** @var array<string, array{invite_id:string}> */
    private array $invites;

    public function __construct(
        array $inviteIds,
        private int $relatedMatchCount = 0,
        private int $inviteEventDeleteCount = 0
    ) {
        $this->invites = [];
        foreach ($inviteIds as $inviteId) {
            $this->invites[(string)$inviteId] = ['invite_id' => (string)$inviteId];
        }
    }

    public function driver(): string
    {
        return 'mysql';
    }

    public function execute(string $sql, array $parameters = []): int
    {
        if (str_contains($sql, 'DELETE FROM mgw_invite_events')) {
            return $this->inviteEventDeleteCount;
        }
        if (str_contains($sql, 'DELETE FROM mgw_invites')) {
            $inviteId = (string)($parameters['invite_id'] ?? '');
            if ($inviteId === '' || !isset($this->invites[$inviteId])) return 0;
            unset($this->invites[$inviteId]);
            return 1;
        }
        throw new RuntimeException('Unexpected execute SQL in invite regression fake: ' . $sql);
    }

    public function fetchAll(string $sql, array $parameters = []): array
    {
        if (str_contains($sql, 'SELECT invite_id FROM mgw_invites')) {
            return array_values($this->invites);
        }
        if (str_contains($sql, 'SELECT * FROM mgw_invites')) {
            return array_values($this->invites);
        }
        throw new RuntimeException('Unexpected fetchAll SQL in invite regression fake: ' . $sql);
    }

    public function fetchValue(string $sql, array $parameters = []): mixed
    {
        if (str_contains($sql, 'SELECT COUNT(*) FROM mgw_matches')) {
            return $this->relatedMatchCount;
        }
        throw new RuntimeException('Unexpected fetchValue SQL in invite regression fake: ' . $sql);
    }

    public function transaction(callable $callback): mixed
    {
        return $callback($this);
    }

    public function inviteCount(): int
    {
        return count($this->invites);
    }
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true)
        );
    }
};
$assertContains = static function (string $needle, string $haystack, string $message) use (&$assertions): void {
    $assertions++;
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message . ': missing ' . $needle);
    }
};

$config = [
    'environment' => 'production',
    'storage_driver' => 'database',
    'database' => [
        'enabled' => true,
        'driver' => 'mysql',
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'mgw_test',
        'user' => 'mgw_test',
        'password' => 'test-password',
        'charset' => 'utf8mb4',
    ],
    'feature_flags' => [
        'database_runtime' => [
            'enabled' => true,
            'production_activated' => true,
            'modules' => [],
        ],
    ],
];

$snapshot = ['invites' => []];
$stateSha = hash('sha256', json_encode(
    $snapshot,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
));

$database = new InviteRegressionFakeDatabase(['stale-invite'], 0, 2);
$projector = new ProductionRuntimeInvitesModuleProjector($config, $database);
$report = $projector->project($snapshot, 63, $stateSha);

$assertSame(true, $report['ok'], 'Invite projector must restore parity after pruning DB-only invite');
$assertSame(true, $report['parity'], 'Invite projector must report parity after pruning');
$assertSame(false, $report['read_only'], 'Invite project pass must report mutation mode');
$assertSame(1, $report['summary']['pruned_invite_rows'], 'Exactly one DB-only invite must be pruned');
$assertSame(2, $report['summary']['pruned_invite_event_rows'], 'Related invite events must be pruned first');
$assertSame(0, $database->inviteCount(), 'DB-only invite must be absent after projection');
$assertSame(true, $projector->audit($snapshot, 63, $stateSha)['ok'], 'Read-only invite audit must pass after pruning');

$historicalDatabase = new InviteRegressionFakeDatabase(['referenced-invite'], 1, 0);
$historicalProjector = new ProductionRuntimeInvitesModuleProjector($config, $historicalDatabase);
$historicalReport = $historicalProjector->project($snapshot, 63, $stateSha);
$historicalAudit = $historicalProjector->audit($snapshot, 63, $stateSha);

$assertSame(true, $historicalReport['ok'], 'Match-referenced historical invite must not block active projection');
$assertSame(1, $historicalReport['summary']['preserved_historical_invite_rows'], 'One historical invite must be preserved');
$assertSame(0, $historicalReport['summary']['pruned_invite_rows'], 'Historical invite must not be deleted');
$assertSame(1, $historicalDatabase->inviteCount(), 'Historical invite must remain in normalized storage');
$assertSame(true, $historicalAudit['ok'], 'Historical invite must not block read-only active parity');
$assertSame(1, $historicalAudit['summary']['preserved_historical_invite_rows'], 'Audit must report preserved historical invite');

$ui = file_get_contents($root . '/../app/assets/js/ui.js') ?: '';
$profile = file_get_contents($root . '/../app/assets/js/screens/profile-screen.js') ?: '';
$main = file_get_contents($root . '/../app/assets/js/main.js') ?: '';
$index = file_get_contents($root . '/../app/index.html') ?: '';
$css = file_get_contents($root . '/../app/assets/css/main.css') ?: '';
$factory = file_get_contents($root . '/runtime/ProductionPrimaryProjectorFactory.php') ?: '';
$inviteProjector = file_get_contents($root . '/runtime/ProductionRuntimeInvitesModuleProjector.php') ?: '';

$assertContains('currentTelegramPhotoUrl(ownerId)', $ui, 'Avatar renderer must use current Telegram photo');
$assertContains('explicitPhotoUrl || telegramPhotoUrl || existingPhotoUrl', $ui, 'Avatar renderer must preserve a known photo');
$assertContains('mergeUserState(state.user, result.user)', $profile, 'Profile refresh must merge partial user payloads');
$assertContains('../ui.js?v=89', $profile, 'Profile must import the hotfix avatar renderer');
$assertContains('hasProfileStats(state.profileStats)', $profile, 'Profile must keep the warmed real statistics visible');
$assertContains('v96-mvp14-root-cause-stabilization', $main, 'Main module must publish v96 without losing prior avatar fixes');
$assertContains('./ui.js?v=89', $main, 'Main module must preserve avatar renderer cache busting');
$assertContains('./screens/profile-screen.js?v=92', $main, 'Main module must load the first-open-safe profile screen');
$assertContains('main.css?v=92', $index, 'Entrypoint must publish current CSS');
$assertContains('main.js?v=97', $index, 'Entrypoint must publish the notification-ownership JavaScript cache identity');
$assertContains('transition:none !important', $css, 'Current sheet fix must be at least as immediate as the prior transition');
$assertContains('animation:none !important', $css, 'Sheet must not show an intermediate animation frame');
$assertContains('ProductionRuntimeInvitesModuleProjector', $factory, 'Production factory must install exact invite lifecycle projector');
$assertContains('ProductionInviteProjectionDatabaseView', $inviteProjector, 'Invite projector must isolate historical rows from active parity');
$assertContains('preserved_historical_invite_rows', $inviteProjector, 'Invite projector must report historical rows');

fwrite(STDOUT, "ProductionAvatarInviteRegressionHotfixTest: {$assertions} assertions passed\n");
