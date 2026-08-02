<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/helpers/RsaJwkPublicKey.php';

if (!function_exists('openssl_pkey_new')) {
    fwrite(STDOUT, "ProductionMvp14R13RsaJwkPublicKeyTest skipped: OpenSSL unavailable\n");
    exit(0);
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$base64Url = static fn(string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
if ($key === false) throw new RuntimeException('Unable to create RSA fixture key.');
$details = openssl_pkey_get_details($key);
if (!is_array($details) || !is_array($details['rsa'] ?? null)) {
    throw new RuntimeException('Unable to read RSA fixture details.');
}

$pem = RsaJwkPublicKey::toPem([
    'kty' => 'RSA',
    'n' => $base64Url((string)$details['rsa']['n']),
    'e' => $base64Url((string)$details['rsa']['e']),
]);
$assert(is_string($pem)
    && str_starts_with($pem, "-----BEGIN PUBLIC KEY-----\n")
    && str_contains($pem, "-----END PUBLIC KEY-----"),
    'RSA JWK modulus and exponent must convert to a PEM public key.');

$publicKey = is_string($pem) ? openssl_pkey_get_public($pem) : false;
$assert($publicKey !== false, 'Converted RSA JWK PEM must be accepted by OpenSSL.');

$payload = 'mini-games-world-oidc-signature-fixture';
$signature = '';
$assert(openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256),
    'RSA fixture payload must be signed.');
$assert(openssl_verify($payload, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1,
    'Converted JWK public key must verify the original RSA signature.');
$assert(openssl_verify($payload . '-tampered', $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1,
    'Converted JWK public key must reject tampered input.');

$assert(RsaJwkPublicKey::toPem(['n' => '', 'e' => 'AQAB']) === null
    && RsaJwkPublicKey::toPem(['n' => 'invalid!', 'e' => 'AQAB']) === null,
    'Malformed RSA JWK values must fail closed.');

$verifier = file_get_contents($root . '/bot/services/GitHubActionsOidcVerifier.php');
$assert(is_string($verifier)
    && str_contains($verifier, 'RsaJwkPublicKey::toPem($key)')
    && str_contains($verifier, "require_once dirname(__DIR__) . '/helpers/RsaJwkPublicKey.php'"),
    'GitHub OIDC verification must use RSA n/e fallback when x5c is absent.');

fwrite(STDOUT, "ProductionMvp14R13RsaJwkPublicKeyTest: {$assertions} assertions passed\n");
