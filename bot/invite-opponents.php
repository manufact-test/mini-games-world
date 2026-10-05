<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/localization/ServerLocalization.php';
require_once __DIR__ . '/services/PresenceService.php';
require_once __DIR__ . '/services/InviteOpponentService.php';
require_once __DIR__ . '/social/FriendGraphService.php';
require_once __DIR__ . '/social/SocialInviteGuard.php';

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) api_error(ServerLocalization::copy('server.invites.invalid_request', 'Invalid request.'));

    $auth = new AuthService($config);
    $tgUser = $auth->getUserFromRequest($payload);
    $userId = (string)($tgUser['id'] ?? '');
    if ($userId === '') api_error(ServerLocalization::copy('server.invites.user_not_found', 'User not found.'));

    $identityProvider = strtolower(trim((string)($tgUser['mgw_identity_provider'] ?? '')));
    $isAndroidActor = $identityProvider === 'android_device';

    // The picker and create_direct must read the same active runtime state.
    // A staging-only DB snapshot can lag behind JSON and omit newly active users,
    // producing asymmetric lists and an empty frame before a later refresh.
    $storage = StorageFactory::createJson((string)($config['data_dir'] ?? (__DIR__ . '/data')));
    $onlineIds = (new PresenceService())->onlineAccountIds();
    $opponents = new InviteOpponentService();
    $reader = static function (array $data) use ($opponents, $userId, $onlineIds): array {
        return [
            'items'=>$opponents->list($data, $userId, $onlineIds),
            'runtime_user_ids'=>array_values(array_map('strval', array_keys($data['users'] ?? []))),
        ];
    };

    // The picker needs only users and finished-game history. JSON storage can
    // preserve the same shared-lock snapshot while skipping unrelated ledgers,
    // payments, notifications, invites and support archives. Future storage
    // drivers remain correct through the ordinary full-snapshot fallback.
    $runtimeSnapshot = $storage instanceof SelectiveReadStorageInterface
        ? $storage->readOnlySections(['users', 'games'], $reader)
        : $storage->readOnly($reader);
    $items = is_array($runtimeSnapshot['items'] ?? null) ? $runtimeSnapshot['items'] : [];
    $runtimeUserIds = array_fill_keys(
        array_map('strval', is_array($runtimeSnapshot['runtime_user_ids'] ?? null) ? $runtimeSnapshot['runtime_user_ids'] : []),
        true
    );

    // Android-only parity may need canonical friends that are not recent JSON
    // opponents. Telegram must stay on the accepted low-latency users/games
    // snapshot and must not pay for the canonical DB friend-graph merge.
    $actorMgwId = strtoupper(trim((string)($tgUser['mgw_id'] ?? '')));
    if ($isAndroidActor && MgwIdGenerator::isValid($actorMgwId)) {
        $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
        $router = new RuntimeStorageRouter($config);
        if ($databaseConfig->enabled()
            && (!$router->enabled() || $router->routeFor('accounts') === RuntimeStorageRouter::DRIVER_DATABASE)) {
            $database = PdoConnectionFactory::create($databaseConfig);
            $friendSnapshot = (new FriendGraphService($database))->snapshot($actorMgwId);
            $inviteGuard = new SocialInviteGuard($database);
            $provider = SocialInviteGuard::providerForAuthenticatedUser($tgUser);

            $runtimeById = [];
            foreach ($items as $item) {
                if (!is_array($item)) continue;
                $runtimeId = trim((string)($item['id'] ?? ''));
                if ($runtimeId !== '') $runtimeById[$runtimeId] = $item;
            }

            $merged = [];
            $seenRuntimeIds = [];
            foreach ($friendSnapshot['friends'] ?? [] as $friend) {
                if (!is_array($friend)) continue;
                $friendMgwId = strtoupper(trim((string)($friend['mgw_id'] ?? '')));
                if (!MgwIdGenerator::isValid($friendMgwId)) continue;
                try {
                    $runtimeId = $inviteGuard->runtimeSubjectForMgwId($actorMgwId, $friendMgwId, $provider);
                } catch (Throwable) {
                    continue;
                }
                if ($runtimeId === '' || !isset($runtimeUserIds[$runtimeId])) continue;

                $runtimeItem = $runtimeById[$runtimeId] ?? null;
                $merged[] = is_array($runtimeItem)
                    ? $runtimeItem
                    : [
                        'id'=>$runtimeId,
                        'name'=>(string)($friend['nickname'] ?? $friend['display_name'] ?? ServerLocalization::copy('server.invites.player_fallback', 'Player')),
                        'activity'=>ServerLocalization::copy('server.invite_chain.opponents.friend', 'friend'),
                        'online'=>false,
                        'busy'=>false,
                        'last_game_at'=>'',
                        'last_seen_at'=>'',
                    ];
                $seenRuntimeIds[$runtimeId] = true;
            }

            foreach ($items as $item) {
                if (!is_array($item)) continue;
                $runtimeId = trim((string)($item['id'] ?? ''));
                if ($runtimeId === '' || isset($seenRuntimeIds[$runtimeId])) continue;
                $merged[] = $item;
                $seenRuntimeIds[$runtimeId] = true;
            }

            $items = array_slice($merged, 0, 10);
        }
    }

    api_ok([
        'items' => $items,
        'authoritative' => true,
        'storage_driver' => $storage->driver(),
    ]);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
