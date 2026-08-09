<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Missing source: ' . $path);
    return $content;
};

$authPath = 'bot/services/AuthService.php';
$watchPath = 'bot/game-watch.php';
$manifestPath = 'bot/helpers/staging-e2e-runtime-files.txt';
$auth = $read($authPath);
$watch = $read($watchPath);
$manifest = $read($manifestPath);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($auth, 'public function getUserFromRequest(array $payload, bool $attachIdentity = true): array'),
    'Normal AuthService callers must retain identity attachment by default.'
);
$assert(
    str_contains($auth, 'if (!$attachIdentity) return $user;'),
    'AuthService must support an authenticated provider-id-only read path.'
);
$assert(
    str_contains($watch, 'getUserFromRequest($payload, false)'),
    'High-frequency game-watch must explicitly skip provider-neutral identity resolution.'
);
$assert(
    str_contains($watch, 'in_array($userId, $participants, true)'),
    'Game-watch must still authorize the verified provider id as a game participant.'
);
$assert(
    str_contains($watch, 'flock($handle, LOCK_SH)'),
    'JSON game-watch must remain read-only under the games file shared lock.'
);
$assert(
    !str_contains($watch, 'RuntimeAccountIdentityResolver'),
    'Game-watch must not create its own identity resolver bypass.'
);
$assert(str_contains($manifest, $watchPath), 'game-watch must remain in exact staging fingerprint coverage.');
$assert(str_contains($manifest, $authPath), 'AuthService must be in exact staging fingerprint coverage when watch behavior depends on it.');

fwrite(STDOUT, "PhaseBReadOnlyGameWatchContractTest: {$assertions} assertions passed\n");
