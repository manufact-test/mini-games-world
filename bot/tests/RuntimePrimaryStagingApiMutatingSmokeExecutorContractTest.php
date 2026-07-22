<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$path = $projectRoot . '/ops/runtime/execute-staging-api-mutating-smoke-request.php';
$source = file_get_contents($path);
if (!is_string($source)) {
    throw new RuntimeException('Staging API mutating smoke executor source is unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assertTrue(
    str_contains($source, "if (PHP_SAPI !== 'cli')")
        && str_contains($source, "PHP_VERSION_ID < 80300")
        && str_contains($source, "PHP_VERSION_ID >= 80400"),
    'Executor must remain CLI-only on exact PHP 8.3.'
);
$assertTrue(
    str_contains($source, "\$path !== 'php://input'")
        && str_contains($source, "!str_starts_with(\$mode, 'r')")
        && str_contains($source, 'RuntimePrimaryStagingApiMutatingSmokeInput::read('),
    'Executor stream must expose only approved read access to php://input.'
);
$assertTrue(
    str_contains($source, "stream_wrapper_unregister('php')")
        && str_contains($source, "stream_wrapper_register('php'")
        && str_contains($source, "stream_wrapper_restore('php')"),
    'Executor must isolate and restore the PHP stream wrapper inside the child process.'
);
$assertTrue(
    str_contains($source, "unset(\$_SERVER['HTTP_HOST'], \$_SERVER['SERVER_NAME']);")
        && !str_contains($source, "\$_SERVER['HTTP_HOST'] =")
        && !str_contains($source, "\$_SERVER['SERVER_NAME'] ="),
    'Executor must not forge a host that conflicts with the staging allowlist.'
);
$assertTrue(
    str_contains($source, "\$_SERVER['SCRIPT_FILENAME'] = \$projectRoot . '/bot/api.php';")
        && str_contains($source, "require \$projectRoot . '/bot/api.php';")
        && !str_contains($source, 'webhook.php')
        && !str_contains($source, 'curl_'),
    'Executor must invoke only the real API entrypoint without HTTP or webhook routing.'
);

fwrite(STDOUT, "RuntimePrimaryStagingApiMutatingSmokeExecutorContractTest passed: {$assertions} assertions.\n");
