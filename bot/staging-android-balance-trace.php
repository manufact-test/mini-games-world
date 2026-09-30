<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/accounts/AndroidDeviceAuthService.php';

$environment = strtolower(trim((string)($config['environment'] ?? '')));
$baseHost = strtolower((string)(parse_url((string)($config['base_url'] ?? ''), PHP_URL_HOST) ?: ''));
$requestHost = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
if (str_contains($requestHost, ':')) $requestHost = explode(':', $requestHost, 2)[0];

if ($environment !== 'staging' || $baseHost === '' || $requestHost !== $baseHost) {
    http_response_code(404);
    echo json_encode(['ok'=>false,'error'=>'not_found'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

$dataDir = rtrim(trim((string)($config['data_dir'] ?? '')), '/\\');
if ($dataDir === '') {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'trace_unavailable'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}
$directory = $dataDir . '/.runtime/staging-android-balance-trace';
if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'trace_unavailable'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}
@chmod($directory, 0700);
$path = $directory . '/events.jsonl';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'POST') {
    if (trim((string)($_SERVER['HTTP_X_MGW_DIAGNOSTIC'] ?? '')) !== 'android-balance-v1') {
        http_response_code(404);
        echo json_encode(['ok'=>false,'error'=>'not_found'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit;
    }

    // Only an authenticated Android WebView carries this HttpOnly cookie.
    // The value itself is never read, stored or exposed by this diagnostic.
    $androidCookie = trim((string)($_COOKIE[AndroidDeviceAuthService::COOKIE_NAME] ?? ''));
    if ($androidCookie === '') {
        http_response_code(204);
        exit;
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'invalid_payload'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit;
    }

    $traceId = strtolower(trim((string)($payload['trace_id'] ?? '')));
    $event = strtolower(trim((string)($payload['event'] ?? '')));
    $elapsedMs = (int)($payload['elapsed_ms'] ?? -1);
    $build = trim((string)($payload['build'] ?? ''));
    $states = is_array($payload['states'] ?? null) ? $payload['states'] : [];

    if (preg_match('/^[a-f0-9]{16}$/', $traceId) !== 1
        || preg_match('/^[a-z0-9._:-]{1,48}$/', $event) !== 1
        || $elapsedMs < 0 || $elapsedMs > 60000
        || strlen($build) > 96
        || count($states) > 8) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'invalid_payload'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit;
    }

    $allowedStates = ['missing'=>true,'zero'=>true,'nonzero'=>true,'invalid'=>true];
    $normalizedStates = [];
    foreach ($states as $key => $value) {
        $name = strtolower(trim((string)$key));
        $state = strtolower(trim((string)$value));
        if (preg_match('/^[a-z0-9._:-]{1,32}$/', $name) !== 1 || !isset($allowedStates[$state])) {
            http_response_code(400);
            echo json_encode(['ok'=>false,'error'=>'invalid_payload'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
            exit;
        }
        $normalizedStates[$name] = $state;
    }

    $record = [
        'trace_id'=>$traceId,
        'event'=>$event,
        'elapsed_ms'=>$elapsedMs,
        'states'=>$normalizedStates,
        'build'=>$build,
        'received_at_utc'=>gmdate('c'),
    ];

    $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    $handle = @fopen($path, 'a');
    if ($handle === false) {
        http_response_code(503);
        echo json_encode(['ok'=>false,'error'=>'trace_unavailable'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit;
    }
    try {
        if (!flock($handle, LOCK_EX) || fwrite($handle, $line) === false || !fflush($handle)) {
            throw new RuntimeException('trace_write_failed');
        }
    } catch (Throwable) {
        fclose($handle);
        http_response_code(503);
        echo json_encode(['ok'=>false,'error'=>'trace_unavailable'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit;
    }
    flock($handle, LOCK_UN);
    fclose($handle);
    @chmod($path, 0600);

    echo json_encode(['ok'=>true], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

if ($method !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'method_not_allowed'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

// Public read is intentionally privacy-minimal: no identity, token, exact balance,
// IP address or user-agent is ever stored. Only zero/nonzero state transitions from
// recent Android staging traces are returned.
$records = [];
if (is_file($path)) {
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines)) {
        $cutoff = time() - 1800;
        foreach (array_slice($lines, -300) as $line) {
            $item = json_decode($line, true);
            if (!is_array($item)) continue;
            $received = strtotime((string)($item['received_at_utc'] ?? '')) ?: 0;
            if ($received < $cutoff) continue;
            $records[] = $item;
        }
    }
}

$grouped = [];
foreach ($records as $record) {
    $traceId = (string)($record['trace_id'] ?? '');
    if ($traceId === '') continue;
    $grouped[$traceId] ??= [];
    $grouped[$traceId][] = $record;
}
$traceIds = array_slice(array_keys($grouped), -8);
$traces = [];
foreach ($traceIds as $traceId) {
    $traces[] = [
        'trace_id'=>$traceId,
        'events'=>$grouped[$traceId],
    ];
}

echo json_encode([
    'ok'=>true,
    'service'=>'staging-android-balance-trace-v1',
    'traces'=>$traces,
    'server_time_utc'=>gmdate('c'),
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
