<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/accounts/MgwProfileService.php';

function mgw_profile_ensure_runtime_balance(
    array $config,
    array $authenticatedUser,
    DatabaseConnectionInterface $database
): int {
    // Use the same canonical runtime initializer that the Store already uses.
    // This is intentionally a transaction rather than a read: after account-link
    // the target runtime user may exist without the unified balance field (or be
    // absent from rollback JSON entirely). UserService::ensureUser() owns the
    // safe post-cutover rehydration rule:
    //   - missing balance => restore the verified canonical amount;
    //   - explicit zero => preserve zero;
    //   - ownership mismatch => fail closed.
    //
    // profile.php is outside the bounded staging api.php DB-primary rehearsal,
    // so this reaches the real primary runtime before Home is first painted.
    $storage = StorageFactory::create($config);
    $users = new UserService($config, $database);

    return $storage->transaction(
        static function (array &$data) use ($users, $authenticatedUser): int {
            $runtimeUser = $users->ensureUser($data, $authenticatedUser);
            $balance = $runtimeUser[UnifiedBalanceRuntimeState::FIELD] ?? null;
            if (!is_int($balance) || $balance < 0) {
                throw new RuntimeException('Ensured runtime balance is invalid.');
            }
            return $balance;
        }
    );
}

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        json_response(['ok' => false, 'error' => 'Некорректный запрос.'], 400);
    }

    // AuthService remains the only provider authentication owner. It resolves
    // the provider identity to an internal MGW id before the profile layer runs.
    $authenticatedUser = (new AuthService($config))->getUserFromRequest($payload);
    $mgwId = trim((string)($authenticatedUser['mgw_id'] ?? ''));
    if (!MgwIdGenerator::isValid($mgwId)) {
        json_response(['ok' => false, 'error' => 'Профиль MGW недоступен для этой сессии.'], 401);
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    $router = new RuntimeStorageRouter($config);
    if (!$databaseConfig->enabled()
        || ($router->enabled() && $router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE)) {
        json_response(['ok' => false, 'error' => 'Профиль MGW временно недоступен.'], 503);
    }

    // First-visible identity and collection must come from the same canonical DB
    // snapshot before the Mini App preloader is released. Reuse one connection;
    // ProductInventoryService remains the sole ownership/equip owner.
    $database = PdoConnectionFactory::create($databaseConfig);
    $profile = (new MgwProfileService($database))->publicProfile($mgwId);
    $inventory = (new ProductInventoryService($database))->snapshot($mgwId);
    $liveRuntimeBalance = mgw_profile_ensure_runtime_balance($config, $authenticatedUser, $database);

    $provider = strtolower(trim((string)($authenticatedUser['mgw_identity_provider'] ?? '')));
    json_response([
        'ok' => true,
        'profile' => $profile,
        'inventory' => $inventory,
        'runtime' => [
            'balance' => $liveRuntimeBalance,
            'source' => 'primary_runtime_ensured',
        ],
        'auth' => [
            'provider' => $provider !== '' ? $provider : null,
            'provider_neutral' => true,
        ],
    ]);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld MGW profile] ' . $error->getMessage());
    json_response(['ok' => false, 'error' => 'Не удалось загрузить профиль MGW.'], 500);
}
