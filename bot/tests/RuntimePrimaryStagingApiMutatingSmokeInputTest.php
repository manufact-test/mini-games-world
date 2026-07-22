<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingApiMutatingSmokeInput.php';

$temporary = sys_get_temp_dir() . '/mgw-api-smoke-input-test-' . bin2hex(random_bytes(6));
if (!mkdir($temporary, 0700, true) && !is_dir($temporary)) {
    throw new RuntimeException('Unable to create API smoke input test directory.');
}
chmod($temporary, 0700);
$input = $temporary . '/input.json';
$commit = trim((string)shell_exec('git -C ' . escapeshellarg($projectRoot) . ' rev-parse HEAD'));
if (preg_match('/\A[a-f0-9]{40}\z/', $commit) !== 1) {
    throw new RuntimeException('Unable to resolve test repository commit.');
}
$payload = [
    'action' => 'bootstrap',
    'sessionId' => str_repeat('a', 64),
    'initData' => 'auth_date=1&hash=' . str_repeat('b', 64) . '&user=%7B%22id%22%3A1%7D',
];
$raw = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
file_put_contents($input, $raw);
chmod($input, 0600);
putenv(RuntimePrimaryStagingApiMutatingSmokeInput::ENV_INPUT_FILE . '=' . $input);

$now = 1_800_000_000;
$valid = [
    'environment' => 'staging',
    'staging_api_mutating_smoke_input' => [
        'contract_version' => RuntimePrimaryStagingApiMutatingSmokeInput::CONTRACT_VERSION,
        'enabled' => true,
        'expected_action' => 'bootstrap',
        'expected_payload_sha256' => hash('sha256', $raw),
        'expected_repository_commit' => $commit,
        'expires_at_utc' => gmdate(DATE_ATOM, $now + 300),
    ],
];

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $messagePart) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains($error->getMessage(), $messagePart)) return;
        throw new RuntimeException('Unexpected API smoke input error: ' . $error->getMessage(), 0, $error);
    }
    throw new RuntimeException('Expected API smoke input failure was not raised: ' . $messagePart);
};

try {
    $assertSame($raw, RuntimePrimaryStagingApiMutatingSmokeInput::read($valid, $projectRoot, $now), 'Valid private input did not load exactly.');

    $wrongHash = $valid;
    $wrongHash['staging_api_mutating_smoke_input']['expected_payload_sha256'] = str_repeat('0', 64);
    $assertThrows(
        static fn() => RuntimePrimaryStagingApiMutatingSmokeInput::read($wrongHash, $projectRoot, $now),
        'fingerprint does not match'
    );

    $wrongCommit = $valid;
    $wrongCommit['staging_api_mutating_smoke_input']['expected_repository_commit'] = str_repeat('0', 40);
    $assertThrows(
        static fn() => RuntimePrimaryStagingApiMutatingSmokeInput::read($wrongCommit, $projectRoot, $now),
        'different checkout'
    );

    $commitWithNewline = $valid;
    $commitWithNewline['staging_api_mutating_smoke_input']['expected_repository_commit'] .= "\n";
    $assertThrows(
        static fn() => RuntimePrimaryStagingApiMutatingSmokeInput::read($commitWithNewline, $projectRoot, $now),
        'fields are invalid'
    );

    $expired = $valid;
    $expired['staging_api_mutating_smoke_input']['expires_at_utc'] = gmdate(DATE_ATOM, $now - 1);
    $assertThrows(
        static fn() => RuntimePrimaryStagingApiMutatingSmokeInput::read($expired, $projectRoot, $now),
        'has expired'
    );

    $tooLong = $valid;
    $tooLong['staging_api_mutating_smoke_input']['expires_at_utc'] = gmdate(DATE_ATOM, $now + 700);
    $assertThrows(
        static fn() => RuntimePrimaryStagingApiMutatingSmokeInput::read($tooLong, $projectRoot, $now),
        'window is too long'
    );

    $wrongActionPayload = $payload;
    $wrongActionPayload['action'] = 'support';
    $wrongActionRaw = json_encode($wrongActionPayload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    file_put_contents($input, $wrongActionRaw);
    chmod($input, 0600);
    $wrongAction = $valid;
    $wrongAction['staging_api_mutating_smoke_input']['expected_payload_sha256'] = hash('sha256', $wrongActionRaw);
    $assertThrows(
        static fn() => RuntimePrimaryStagingApiMutatingSmokeInput::read($wrongAction, $projectRoot, $now),
        'action does not match approval'
    );

    file_put_contents($input, $raw);
    chmod($input, 0644);
    $assertThrows(
        static fn() => RuntimePrimaryStagingApiMutatingSmokeInput::read($valid, $projectRoot, $now),
        'permissions are unsafe'
    );
} finally {
    putenv(RuntimePrimaryStagingApiMutatingSmokeInput::ENV_INPUT_FILE);
    @unlink($input);
    @rmdir($temporary);
}

fwrite(STDOUT, "RuntimePrimaryStagingApiMutatingSmokeInputTest passed: {$assertions} assertions.\n");
