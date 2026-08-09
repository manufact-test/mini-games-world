<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Missing source: ' . $path);
    return $content;
};

$authPath = 'bot/services/AuthService.php';
$presencePath = 'bot/presence.php';
$manifestPath = 'bot/helpers/staging-e2e-runtime-files.txt';
$auth = $read($authPath);
$presence = $read($presencePath);
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
    'AuthService must preserve the authenticated provider-id-only path.'
);
$assert(
    str_contains($presence, 'new AuthService($config)'),
    'Presence must continue authenticating through AuthService.'
);
$assert(
    str_contains($presence, 'getUserFromRequest($payload, false)'),
    'Presence must skip redundant provider-neutral DB identity resolution.'
);
$assert(
    str_contains($presence, "$accountId = trim((string)(\$tgUser['id'] ?? ''));"),
    'Presence must continue binding the lease to the verified provider user id.'
);
$assert(
    str_contains($presence, "if (\$sessionId === '') throw new RuntimeException('Сессия устройства не найдена.');"),
    'Presence must continue requiring the device session id.'
);
$assert(
    str_contains($presence, "$presence->touch($accountId, $sessionId, $presenceLeaseId);"),
    'Presence ping/status must keep the existing lease touch owner.'
);
$assert(
    str_contains($presence, "$presence->leave($accountId, $sessionId, $presenceLeaseId);"),
    'Presence leave must keep the existing lease release owner.'
);
$assert(
    !str_contains($presence, 'RuntimeAccountIdentityResolver'),
    'Presence must not introduce its own identity resolver bypass.'
);
$assert(
    str_contains($manifest, $presencePath),
    'Presence endpoint must be covered by the exact staging runtime fingerprint.'
);

fwrite(STDOUT, "PhaseBPresenceReadOnlyAuthContractTest: {$assertions} assertions passed\n");
