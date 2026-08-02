<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/services/GitHubActionsOidcVerifier.php';

if (!function_exists('openssl_pkey_new')) {
    fwrite(STDOUT, "ProductionMvp14R13GitHubActionsOidcTest skipped: OpenSSL unavailable\n");
    exit(0);
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$expectFailure = static function (Closure $callback, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException($message);
};

$base64Url = static fn(string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

$key = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
    'private_key_bits' => 2048,
]);
if ($key === false) throw new RuntimeException('Unable to create OIDC fixture key.');
$csr = openssl_csr_new(['commonName' => 'token.actions.githubusercontent.com'], $key, ['digest_alg' => 'sha256']);
if ($csr === false) throw new RuntimeException('Unable to create OIDC fixture CSR.');
$certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
if ($certificate === false) throw new RuntimeException('Unable to sign OIDC fixture certificate.');
$certificatePem = '';
if (!openssl_x509_export($certificate, $certificatePem)) {
    throw new RuntimeException('Unable to export OIDC fixture certificate.');
}
$x5c = preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/', '', $certificatePem) ?? '';
if ($x5c === '') throw new RuntimeException('Unable to encode OIDC fixture certificate.');

$kid = 'mgw-test-kid';
$jwks = json_encode([
    'keys' => [[
        'kty' => 'RSA',
        'use' => 'sig',
        'alg' => 'RS256',
        'kid' => $kid,
        'x5c' => [$x5c],
    ]],
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

$now = 1785700000;
$tempDir = sys_get_temp_dir() . '/mgw-staging-oidc-' . bin2hex(random_bytes(6));
if (!mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
    throw new RuntimeException('Unable to create OIDC fixture directory.');
}
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path)) {
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $remove($path . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($path);
    } else {
        @unlink($path);
    }
};

$claims = [
    'iss' => 'https://token.actions.githubusercontent.com',
    'aud' => 'mini-games-world-staging-e2e',
    'sub' => 'repo:manufact-test/mini-games-world:ref:refs/heads/agent/mvp-13-2-staging',
    'repository' => 'manufact-test/mini-games-world',
    'repository_id' => '1295733209',
    'repository_owner' => 'manufact-test',
    'repository_owner_id' => '301880503',
    'ref' => 'refs/heads/agent/mvp-13-2-staging',
    'event_name' => 'push',
    'workflow_ref' => 'manufact-test/mini-games-world/.github/workflows/staging-playwright-e2e.yml@refs/heads/agent/mvp-13-2-staging',
    'sha' => str_repeat('a', 40),
    'run_id' => '123456789',
    'run_number' => '7',
    'jti' => 'oidc-jti-1',
    'iat' => $now - 30,
    'nbf' => $now - 30,
    'exp' => $now + 300,
];

$makeToken = static function (array $payload, mixed $signingKey = null) use ($base64Url, $kid, $key): string {
    $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid];
    $encodedHeader = $base64Url(json_encode($header, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $encodedPayload = $base64Url(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $input = $encodedHeader . '.' . $encodedPayload;
    $signature = '';
    if (!openssl_sign($input, $signature, $signingKey ?? $key, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Unable to sign OIDC fixture token.');
    }
    return $input . '.' . $base64Url($signature);
};

try {
    $requestedUrls = [];
    $verifier = new GitHubActionsOidcVerifier(
        ['data_dir' => $tempDir],
        static function (string $url) use ($jwks, &$requestedUrls): string {
            $requestedUrls[] = $url;
            return $jwks;
        },
        static fn(): int => $now
    );

    $token = $makeToken($claims);
    $verified = $verifier->verifyAndConsume($token);
    $assert(($verified['repository'] ?? null) === 'manufact-test/mini-games-world'
        && ($verified['ref'] ?? null) === 'refs/heads/agent/mvp-13-2-staging'
        && ($verified['event_name'] ?? null) === 'push'
        && ($verified['run_id'] ?? null) === '123456789',
        'A correctly signed exact staging workflow token must be accepted.');
    $assert($requestedUrls === ['https://token.actions.githubusercontent.com/.well-known/jwks'],
        'The verifier must fetch signing keys only from the exact GitHub OIDC JWKS endpoint.');

    $expectFailure(static fn() => $verifier->verifyAndConsume($token),
        'The same OIDC jti must be rejected on replay.');

    $variants = [
        'audience' => ['aud' => 'wrong-audience'],
        'repository' => ['repository' => 'manufact-test/other'],
        'repository_id' => ['repository_id' => '1'],
        'owner_id' => ['repository_owner_id' => '1'],
        'ref' => ['ref' => 'refs/heads/main'],
        'event' => ['event_name' => 'pull_request'],
        'workflow' => ['workflow_ref' => 'manufact-test/mini-games-world/.github/workflows/other.yml@refs/heads/agent/mvp-13-2-staging'],
        'sha' => ['sha' => 'not-a-sha'],
        'expired' => ['iat' => $now - 700, 'nbf' => $now - 700, 'exp' => $now - 100],
        'lifetime' => ['iat' => $now - 10, 'nbf' => $now - 10, 'exp' => $now + 700],
    ];
    $index = 2;
    foreach ($variants as $name => $changes) {
        $variant = array_replace($claims, $changes, ['jti' => 'oidc-jti-' . $index]);
        $index++;
        $expectFailure(static fn() => $verifier->verifyAndConsume($makeToken($variant)),
            'OIDC verifier must reject invalid claim variant: ' . $name);
    }

    $otherKey = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'private_key_bits' => 2048,
    ]);
    if ($otherKey === false) throw new RuntimeException('Unable to create alternate OIDC fixture key.');
    $badSignatureClaims = array_replace($claims, ['jti' => 'oidc-jti-bad-signature']);
    $expectFailure(static fn() => $verifier->verifyAndConsume($makeToken($badSignatureClaims, $otherKey)),
        'OIDC verifier must reject a token signed by an untrusted key.');

    $replayPath = $tempDir . '/.runtime/staging-github-oidc/used-jti.json';
    $replayRaw = file_get_contents($replayPath);
    $assert(is_string($replayRaw)
        && !str_contains($replayRaw, 'oidc-jti-1')
        && !str_contains($replayRaw, $token),
        'OIDC replay storage must contain only hashes and expiry timestamps.');

    $serviceSource = file_get_contents($root . '/bot/services/GitHubActionsOidcVerifier.php');
    $endpointSource = file_get_contents($root . '/bot/staging-test-auth.php');
    $assert(is_string($serviceSource)
        && str_contains($serviceSource, "private const AUDIENCE = 'mini-games-world-staging-e2e'")
        && str_contains($serviceSource, "private const STAGING_REF = 'refs/heads/agent/mvp-13-2-staging'")
        && str_contains($serviceSource, "private const REPOSITORY_ID = '1295733209'")
        && str_contains($serviceSource, 'openssl_verify')
        && str_contains($serviceSource, 'consumeJti'),
        'OIDC verifier source must pin audience, repository identity, staging ref, signature and replay checks.');
    $assert(is_string($endpointSource)
        && str_contains($endpointSource, 'new GitHubActionsOidcVerifier($config)')
        && str_contains($endpointSource, "'authorization_mode' => $authorizationMode")
        && str_contains($endpointSource, "'github_actions_oidc'"),
        'The protected broker must route JWT credentials through the OIDC verifier without exposing them.');
} finally {
    $remove($tempDir);
}

fwrite(STDOUT, "ProductionMvp14R13GitHubActionsOidcTest: {$assertions} assertions passed\n");
