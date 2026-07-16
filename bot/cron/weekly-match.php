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

    if (!$isCli) {
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode(
        ['ok' => true, 'weekly_match' => $result],
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
