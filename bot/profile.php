<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/accounts/MgwProfileService.php';

function mgw_profile_live_runtime_balance(array $config, array $authenticatedUser): ?int
{
    $legacyUserId = trim((string)($authenticatedUser['id'] ?? ''));
    $dataDir = trim((string)($config['data_dir'] ?? ''));
    if ($legacyUserId === '' || $dataDir === '') {
        return null;
    }

    // profile.php intentionally bypasses the bounded staging API DB-primary
    // rehearsal. Identity/inventory remain canonical DB-owned below; this narrow
    // read exists only so the already-awaited first-profile response can carry the
    // live mutable runtime balance used by Home before the preloader is released.
    $storage = new JsonStorageAdapter($dataDir);
    return $storage->readOnlySections(['users'], static function (array $data) use ($legacyUserId): ?int {
        $users = is_array($data['users'] ?? null) ? $data['users'] : [];
        $matches = [];

        $direct = $users[$legacyUserId] ?? null;
        if (is_array($direct)
            && trim((string)($direct['id'] ?? $legacyUserId)) === $legacyUserId) {
            $matches[] = $direct;
        } else {
            foreach ($users as $candidate) {
                if (!is_array($candidate)) continue;
                if (trim((string)($candidate['id'] ?? '')) !== $legacyUserId) continue;
                $matches[] = $candidate;
            }
        }

        if ($matches === []) return null;
        if (count($matches) !== 1) {
            throw new RuntimeException('Live runtime balance owner is ambiguous.');
        }

        $raw = $matches[0][UnifiedBalanceRuntimeState::FIELD] ?? null;
        if (is_int($raw)) {
            if ($raw < 0) throw new RuntimeException('Live runtime balance is negative.');
            return $raw;
        }
        if (is_string($raw) && preg_match('/^\\d+$/', trim($raw)) === 1) {
            $normalized = trim($raw);
            if (strlen($normalized) > strlen((string)PHP_INT_MAX)
                || (strlen($normalized) === strlen((string)PHP_INT_MAX)
                    && strcmp($normalized, (string)PHP_INT_MAX) > 0)) {
                throw new RuntimeException('Live runtime balance exceeds integer range.');
            }
            return (int)$normalized;
        }

        if ($raw === null) return null;
        throw new RuntimeException('Live runtime balance is invalid.');
    });
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
    $liveRuntimeBalance = mgw_profile_live_runtime_balance($config, $authenticatedUser);

    $provider = strtolower(trim((string)($authenticatedUser['mgw_identity_provider'] ?? '')));
    json_response([
        'ok' => true,
        'profile' => $profile,
        'inventory' => $inventory,
        'runtime' => [
            'balance' => $liveRuntimeBalance,
            'source' => 'live_json_runtime',
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
