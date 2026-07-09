<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$dataDir = (string)($config['data_dir'] ?? (__DIR__ . '/data'));
$realDataDir = realpath($dataDir) ?: $dataDir;
$repoDataDir = realpath(__DIR__ . '/data') ?: (__DIR__ . '/data');
$expectedFiles = [
    'users.json',
    'games.json',
    'queue.json',
    'transactions.json',
    'support.json',
    'shop_orders.json',
    'payments.json',
    'system.json',
];

$files = [];
foreach ($expectedFiles as $file) {
    $path = rtrim($dataDir, '/') . '/' . $file;
    $files[$file] = is_file($path);
}

http_response_code(200);
echo json_encode([
    'ok' => true,
    'using_external_data_dir' => $realDataDir !== $repoDataDir,
    'expected_files_present' => !in_array(false, $files, true),
    'data_dir_writable' => is_writable($dataDir),
    'files' => $files,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
