<?php
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $key = (string)($_GET['key'] ?? '');
    $secret = (string)($config['setup_secret'] ?? '');
    if ($key === '' || $secret === '' || $secret === 'CHANGE_ME_TO_LONG_RANDOM_SECRET' || !hash_equals($secret, $key)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Доступ запрещён.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

try {
    $db = StorageFactory::createJson((string)($config['data_dir'] ?? (dirname(__DIR__) . '/data')));
    $service = new WeeklyMatchEconomyService($config, new NotificationService());
    $result = $db->transaction(fn(array &$data): array => $service->runDue($data));

    $runtimeSync = null;
    if (isset($runtimeWeeklyBonusBridge)
        && $runtimeWeeklyBonusBridge instanceof WeeklyBonusRuntimeBridge
        && $runtimeWeeklyBonusBridge->enabled()) {
        $sync = $runtimeWeeklyBonusBridge->synchronizeCurrentJson();
        if (is_array($sync)) {
            $runtimeSync = [
                'ok' => !empty($sync['ok']),
                'weekly_user_count' => (int)($sync['weekly_states']['source_user_count'] ?? 0),
                'weekly_mismatch_count' => (int)($sync['audit']['mismatch_count'] ?? 0),
                'economy_planned_delta_count' => (int)($sync['economy']['planned_delta_count'] ?? 0),
                'production_changed' => false,
            ];
        }
    }

    if (!$isCli) {
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode(
        ['ok' => true, 'weekly_match' => $result, 'runtime_sync' => $runtimeSync],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    ) . PHP_EOL;
} catch (Throwable $e) {
    if (!$isCli) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode(
        ['ok' => false, 'error' => $e->getMessage()],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;

    if ($isCli) {
        exit(1);
    }
}
