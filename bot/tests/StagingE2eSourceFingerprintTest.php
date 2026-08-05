<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/bot/helpers/StagingE2eSourceFingerprint.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$manifest = $root . '/bot/helpers/staging-e2e-runtime-files.txt';
$result = (new StagingE2eSourceFingerprint($root, $manifest))->calculate();
$assert(preg_match('/\A[a-f0-9]{64}\z/', (string)($result['sha256'] ?? '')) === 1,
    'PHP runtime fingerprint must be a SHA-256 digest.');
$assert((int)($result['file_count'] ?? 0) >= 20,
    'The exact deploy gate must cover the complete critical staging runtime graph.');

$files = array_keys(is_array($result['files'] ?? null) ? $result['files'] : []);
foreach ([
    'app/v110.php',
    'app/assets/js/games/game-invites-v110.js',
    'bot/staging-e2e-readiness.php',
    'bot/invites.php',
    'bot/notifications.php',
    'bot/notifications/RuntimeNotificationBridgeCoordinator.php',
    'bot/notifications/RuntimeNotificationRepository.php',
    'bot/services/invites/GameInviteActionTrait.php',
    'bot/storage/JsonDatabase.php',
] as $requiredPath) {
    $assert(in_array($requiredPath, $files, true),
        'Fingerprint manifest must include critical runtime path: ' . $requiredPath);
}

$command = 'python3 ' . escapeshellarg($root . '/scripts/ci/staging-runtime-fingerprint.py') . ' 2>&1';
$output = [];
$exitCode = 0;
exec($command, $output, $exitCode);
$assertSame(0, $exitCode, 'Python exact-checkout fingerprint calculator must run successfully');
$pythonFingerprint = trim(implode("\n", $output));
$assertSame($result['sha256'], $pythonFingerprint,
    'Hostinger PHP and GitHub runner Python fingerprints must be identical.');

fwrite(STDOUT, "StagingE2eSourceFingerprintTest: {$assertions} assertions passed\n");
