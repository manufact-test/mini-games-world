<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$readiness = $read('bot/staging-e2e-readiness.php');
$helper = $read('bot/helpers/StagingE2eSourceFingerprint.php');
$manifest = $read('bot/helpers/staging-e2e-runtime-files.txt');
$calculator = $read('scripts/ci/staging-runtime-fingerprint.py');
$workflow = $read('.github/workflows/staging-playwright-e2e.yml');

$assert(str_contains($readiness, 'StagingE2eSourceFingerprint')
    && str_contains($readiness, "'source_fingerprint_sha256' => \$fingerprint['sha256']")
    && str_contains($readiness, "'exact_runtime_fingerprint'"),
    'Readiness must expose the actual deployed runtime fingerprint and capability.');
$assert(str_contains($helper, "hash_file('sha256', \$absolutePath)")
    && str_contains($helper, "hash('sha256', implode(\"\\n\", \$parts))"),
    'Hostinger must hash every manifest file and then hash the canonical path:digest list.');
$assert(str_contains($calculator, 'hashlib.sha256(source.read_bytes()).hexdigest()')
    && str_contains($calculator, '"\\n".join(parts)'),
    'The exact checkout calculator must use the same canonical algorithm.');
foreach ([
    'bot/notifications.php',
    'bot/notifications/RuntimeNotificationBridgeCoordinator.php',
    'bot/services/invites/GameInviteActionTrait.php',
    'app/assets/js/games/game-invites-v110.js',
] as $criticalPath) {
    $assert(str_contains($manifest, $criticalPath),
        'Manifest must cover critical deployed source: ' . $criticalPath);
}
$assert(substr_count($workflow, 'scripts/ci/staging-runtime-fingerprint.py') === 2
    && substr_count($workflow, "payload.get('source_fingerprint_sha256') == expected_fingerprint") === 2,
    'Linux and macOS readiness routes must compare Hostinger against the exact checked-out fingerprint.');
$assert(substr_count($workflow, "capabilities.get('exact_runtime_fingerprint') is True") === 2,
    'Both independent runners must reject the legacy non-exact readiness response.');

fwrite(STDOUT, "ProductionMvp14StagingExactRuntimeFingerprintTest: {$assertions} assertions passed\n");
