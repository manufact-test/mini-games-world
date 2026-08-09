<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};

$economy = $read('ledger/RuntimeEconomyRepository.php');
$realtime = $read('realtime/RuntimeRealtimeRepository.php');
$pdoFactory = $read('database/PdoConnectionFactory.php');
$fingerprint = $read('helpers/staging-e2e-runtime-files.txt');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

foreach (['economy' => $economy, 'realtime' => $realtime] as $name => $source) {
    $assert(
        str_contains($source, 'private static array $requestSynchronizeCache = [];')
            && str_contains($source, 'private static array $requestAuditCache = [];'),
        ucfirst($name) . ' repository must keep synchronize and audit memo results separate.'
    );
    $assert(
        str_contains($source, 'spl_object_id($database)')
            && str_contains($source, "hash('sha256'"),
        ucfirst($name) . ' memo must be scoped to the exact request DB object and exact source fingerprint.'
    );
    $assert(
        strpos($source, 'array_key_exists($cacheKey, self::$requestSynchronizeCache)')
            < strpos($source, $name === 'economy' ? 'new RuntimeEconomySnapshotStorage' : '$this->sourceState('),
        ucfirst($name) . ' synchronize memo lookup must happen before expensive projection work.'
    );
    $assert(
        str_contains($source, 'unset(self::$requestAuditCache[$cacheKey]);')
            && strpos($source, 'unset(self::$requestAuditCache[$cacheKey]);')
                < strpos($source, 'self::$requestSynchronizeCache[$cacheKey] = $result;'),
        ucfirst($name) . ' synchronize must invalidate any older audit memo before publishing its result.'
    );
    $assert(
        !str_contains($source, 'sleep(')
            && !str_contains($source, 'usleep(')
            && !str_contains($source, 'retry')
            && !str_contains($source, 'ttl'),
        ucfirst($name) . ' request memo must not add timers, retries, TTLs or eventual-consistency behavior.'
    );
}

$assert(
    str_contains($economy, "'users' =>")
        && str_contains($economy, "'transactions' =>")
        && !str_contains($economy, "'games' => is_array(\$jsonSnapshot"),
    'Economy memo fingerprint must be based on the existing economy JSON source only.'
);
$assert(
    str_contains($realtime, "'games' =>")
        && str_contains($realtime, "'queue' =>")
        && !str_contains($realtime, "'users' => is_array(\$jsonData"),
    'Realtime memo fingerprint must be based on the existing realtime JSON source only.'
);

$assert(
    str_contains($pdoFactory, 'private static array $requestConnections = [];')
        && str_contains($pdoFactory, "if (PHP_SAPI === 'cli')")
        && str_contains($pdoFactory, 'return self::$requestConnections[$cacheKey];'),
    'Existing PDO ownership must remain request-scoped on web runtimes and isolated for CLI workers.'
);

foreach ([
    'bot/ledger/RuntimeEconomyRepository.php',
    'bot/realtime/RuntimeRealtimeRepository.php',
] as $path) {
    $assert(
        preg_match('/^' . preg_quote($path, '/') . '$/m', $fingerprint) === 1,
        $path . ' must be covered by exact staging runtime fingerprint.'
    );
}

fwrite(STDOUT, "RuntimeBridgeRequestProjectionMemoContractTest: {$assertions} assertions passed\n");
