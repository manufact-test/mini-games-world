<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

require_once $root . '/bot/database/DatabaseConnectionInterface.php';
require_once $root . '/bot/runtime/RuntimePrimaryModuleProjectorInterface.php';
require_once $root . '/bot/accounts/MgwIdGenerator.php';
require_once $root . '/bot/runtime/RuntimePrimaryAccountsModuleProjector.php';
require_once $root . '/bot/runtime/ProductionRuntimeAccountsModuleProjector.php';
require_once $root . '/bot/helpers/response.php';

final class ProductionAccountHotfixTestDatabase implements DatabaseConnectionInterface
{
    public array $users = [];
    public array $identities = [];
    public array $ownerships = [];

    public function driver(): string
    {
        return 'mysql';
    }

    public function execute(string $sql, array $parameters = []): int
    {
        throw new RuntimeException('Read-only hotfix audit must not execute SQL.');
    }

    public function fetchAll(string $sql, array $parameters = []): array
    {
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($sql)) ?? '');

        if (str_contains($normalized, 'from mgw_account_ownership')
            && str_contains($normalized, 'where legacy_user_id = :legacy_user_id')) {
            $legacyUserId = (string)($parameters['legacy_user_id'] ?? '');
            return array_values(array_filter(
                $this->ownerships,
                static fn(array $row): bool => (string)$row['legacy_user_id'] === $legacyUserId
            ));
        }

        if (str_contains($normalized, 'from mgw_account_ownership')
            && str_contains($normalized, 'where legacy_user_id is not null')) {
            return array_values($this->ownerships);
        }

        if (str_contains($normalized, 'from mgw_users where mgw_id = :mgw_id')) {
            $mgwId = (string)($parameters['mgw_id'] ?? '');
            return isset($this->users[$mgwId]) ? [$this->users[$mgwId]] : [];
        }

        if (str_contains($normalized, 'from mgw_identities')
            && str_contains($normalized, 'provider in')) {
            $mgwId = (string)($parameters['mgw_id'] ?? '');
            return array_values(array_filter(
                $this->identities,
                static fn(array $row): bool => (string)$row['mgw_id'] === $mgwId
                    && in_array((string)$row['provider'], ['telegram', 'development'], true)
            ));
        }

        if (str_contains($normalized, 'from mgw_identities')
            && str_contains($normalized, 'where provider = :provider')) {
            $provider = (string)($parameters['provider'] ?? '');
            $subject = (string)($parameters['provider_subject'] ?? '');
            return array_values(array_filter(
                $this->identities,
                static fn(array $row): bool => (string)$row['provider'] === $provider
                    && (string)$row['provider_subject'] === $subject
            ));
        }

        throw new RuntimeException('Unexpected hotfix test query: ' . $normalized);
    }

    public function fetchValue(string $sql, array $parameters = []): mixed
    {
        return null;
    }

    public function transaction(callable $callback): mixed
    {
        return $callback($this);
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$mgwId = 'MGW-0000000000000001';
$database = new ProductionAccountHotfixTestDatabase();
$database->users[$mgwId] = [
    'mgw_id' => $mgwId,
    'status' => 'active',
    // These fields intentionally differ from compatibility state. They are
    // refreshed by Telegram authentication before the atomic callback begins.
    'display_name' => 'Новое имя из Telegram',
    'username' => 'new_username',
    'avatar_external_ref' => 'https://example.test/new-avatar.jpg',
    'updated_at_utc' => gmdate('Y-m-d H:i:s'),
    'last_seen_at_utc' => gmdate('Y-m-d H:i:s'),
];
$database->ownerships['legacy:100'] = [
    'account_ref' => 'legacy:100',
    'mgw_id' => $mgwId,
    'legacy_user_id' => '100',
    'ownership_status' => 'active',
];
$database->identities['telegram|100'] = [
    'mgw_id' => $mgwId,
    'provider' => 'telegram',
    'provider_subject' => '100',
    'last_authenticated_at_utc' => gmdate('Y-m-d H:i:s'),
];
$database->identities['legacy_import|100'] = [
    'mgw_id' => $mgwId,
    'provider' => 'legacy_import',
    'provider_subject' => '100',
    'last_authenticated_at_utc' => gmdate('Y-m-d H:i:s'),
];

$snapshot = [
    'users' => [
        '100' => [
            'id' => '100',
            'telegram_id' => '100',
            'first_name' => 'Старое compatibility-имя',
            'username' => 'old_username',
            'registered_at' => '2026-07-01T10:00:00+00:00',
            'last_seen_at' => '2026-07-24T20:00:00+00:00',
        ],
    ],
];
$canonicalize = static function (mixed $value) use (&$canonicalize): mixed {
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    foreach ($value as $key => $child) $value[$key] = $canonicalize($child);
    return $value;
};
$stateSha = hash('sha256', json_encode(
    $canonicalize($snapshot),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
));

$subject = new ProductionRuntimeAccountsModuleProjector($database);
$audit = $subject->audit($snapshot, 1, $stateSha);
$assert(($audit['ok'] ?? false) === true, 'Auth-refreshed profile metadata must not block production account parity.');
$assert(($audit['parity'] ?? false) === true, 'Stable identity and ownership parity must pass.');
$assert(($audit['summary']['profile_fields_owned_by_auth'] ?? false) === true, 'Audit must explicitly report auth-owned profile fields.');
$assert(($audit['blockers'] ?? null) === [], 'Stable production account audit must contain no blockers.');

$pendingMgwId = 'MGW-0000000000000002';
$database->users[$pendingMgwId] = ['mgw_id' => $pendingMgwId, 'status' => 'active'];
$database->ownerships['legacy:200'] = [
    'account_ref' => 'legacy:200',
    'mgw_id' => $pendingMgwId,
    'legacy_user_id' => '200',
    'ownership_status' => 'active',
];
$database->identities['telegram|200'] = [
    'mgw_id' => $pendingMgwId,
    'provider' => 'telegram',
    'provider_subject' => '200',
    'last_authenticated_at_utc' => gmdate('Y-m-d H:i:s'),
];

$pendingAudit = $subject->audit($snapshot, 1, $stateSha);
$assert(($pendingAudit['ok'] ?? false) === true, 'A recently authenticated user may exist briefly before compatibility insertion.');
$assert(($pendingAudit['summary']['auth_pending_count'] ?? 0) === 1, 'Audit must count one bounded auth-pending account.');

$technical = mgw_public_api_error('Runtime module projection did not pass parity: accounts.');
$assert($technical === 'Не удалось загрузить данные. Закройте и снова откройте приложение.', 'Internal projection errors must be localized and sanitized.');
$assert(!str_contains($technical, 'Runtime'), 'Internal English exception text must not reach the player.');

$index = file_get_contents($root . '/app/index.html');
$main = file_get_contents($root . '/app/assets/js/main.js');
$webhook = file_get_contents($root . '/bot/webhook.php');
$inviteGuard = file_get_contents($root . '/bot/helpers/InviteStartGuard.php');
$factory = file_get_contents($root . '/bot/runtime/ProductionPrimaryProjectorFactory.php');

$assert(is_string($index) && !str_contains($index, '@player_demo'), 'Production markup must not contain the demo player fallback.');
$assert(is_string($index) && str_contains($index, 'id="activityTitle" hidden'), 'Activity block must stay hidden until bootstrap succeeds.');
$assert(is_string($main) && str_contains($main, 'dispatchAppReady();'), 'Successful bootstrap must start notification and invite synchronization.');
$assert(is_string($webhook) && str_contains($webhook, 'InviteStartGuard'), 'Webhook must route Telegram invite start links.');
$assert(is_string($inviteGuard) && str_contains($inviteGuard, "'/app/?v=87&invite='"), 'Invite start button must preserve the exact token.');
$assert(is_string($factory) && str_contains($factory, 'ProductionRuntimeAccountsModuleProjector'), 'Production must install the account-safe projector.');

echo "Production account/invite hotfix tests passed: {$assertions} assertions\n";
