<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/services/PresenceService.php';
require_once __DIR__ . '/services/InviteOpponentService.php';
require_once __DIR__ . '/social/FriendGraphService.php';
require_once __DIR__ . '/social/SocialInviteGuard.php';

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) api_error('Некорректный запрос.');

    $auth = new AuthService($config);
    $tgUser = $auth->getUserFromRequest($payload);
    $userId = (string)($tgUser['id'] ?? '');
    if ($userId === '') api_error('Пользователь не найден.');

    $includeTestUsers = !empty($tgUser['is_staging_test_user']) || !empty($tgUser['is_dev_user']);
    $identityProvider = strtolower(trim((string)($tgUser['mgw_identity_provider'] ?? '')));
    $isAndroidActor = $identityProvider === 'android_device';

    // Telegram keeps the accepted low-latency recent/online runtime picker.
    // Staging automation may still see its explicit A/B fixture accounts, while
    // real users never receive dev/tournament fixture identities in this list.
    $storage = StorageFactory::createJson((string)($config['data_dir'] ?? (__DIR__ . '/data')));
    $onlineIds = (new PresenceService())->onlineAccountIds();
    $opponents = new InviteOpponentService();
    $reader = static function (array $data) use ($opponents, $userId, $onlineIds, $includeTestUsers): array {
        $testRuntimeUserIds = [];
        foreach ($data['users'] ?? [] as $candidateId=>$candidate) {
            $candidateId = (string)$candidateId;
            if (!is_array($candidate)) continue;
            if (!empty($candidate['is_staging_test_user'])
                || !empty($candidate['is_dev_user'])
                || str_starts_with($candidateId, 'stg_test_player_')
                || str_starts_with($candidateId, 'stg_tour_')
                || str_starts_with($candidateId, 'dev_')) {
                $testRuntimeUserIds[] = $candidateId;
            }
        }

        return [
            'items'=>$opponents->list($data, $userId, $onlineIds, $includeTestUsers),
            'runtime_user_ids'=>array_values(array_map('strval', array_keys($data['users'] ?? []))),
            'test_runtime_user_ids'=>$testRuntimeUserIds,
        ];
    };

    // The picker needs only users and finished-game history. JSON storage can
    // preserve the same shared-lock snapshot while skipping unrelated ledgers,
    // payments, notifications, invites and support archives.
    $runtimeSnapshot = $storage instanceof SelectiveReadStorageInterface
        ? $storage->readOnlySections(['users', 'games'], $reader)
        : $storage->readOnly($reader);
    $items = is_array($runtimeSnapshot['items'] ?? null) ? $runtimeSnapshot['items'] : [];
    $runtimeUserIds = array_fill_keys(
        array_map('strval', is_array($runtimeSnapshot['runtime_user_ids'] ?? null) ? $runtimeSnapshot['runtime_user_ids'] : []),
        true
    );
    $testRuntimeUserIds = array_fill_keys(
        array_map('strval', is_array($runtimeSnapshot['test_runtime_user_ids'] ?? null) ? $runtimeSnapshot['test_runtime_user_ids'] : []),
        true
    );

    // Android-only parity may need canonical friends that are not recent JSON
    // opponents. Do that extra DB graph work only for the Android container;
    // making every Telegram picker pay for it regressed the accepted fast path.
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
                if ($runtimeId === ''
                    || !isset($runtimeUserIds[$runtimeId])
                    || (!$includeTestUsers && isset($testRuntimeUserIds[$runtimeId]))) {
                    continue;
                }

                $runtimeItem = $runtimeById[$runtimeId] ?? null;
                $merged[] = is_array($runtimeItem)
                    ? $runtimeItem
                    : [
                        'id'=>$runtimeId,
                        'name'=>(string)($friend['nickname'] ?? $friend['display_name'] ?? 'Игрок'),
                        'activity'=>'друг',
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
