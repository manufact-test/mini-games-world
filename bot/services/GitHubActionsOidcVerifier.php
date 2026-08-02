<?php
declare(strict_types=1);

final class GitHubActionsOidcVerifier
{
    private const ISSUER = 'https://token.actions.githubusercontent.com';
    private const JWKS_URL = 'https://token.actions.githubusercontent.com/.well-known/jwks';
    private const AUDIENCE = 'mini-games-world-staging-e2e';
    private const REPOSITORY = 'manufact-test/mini-games-world';
    private const REPOSITORY_ID = '1295733209';
    private const REPOSITORY_OWNER = 'manufact-test';
    private const REPOSITORY_OWNER_ID = '301880503';
    private const STAGING_REF = 'refs/heads/agent/mvp-13-2-staging';
    private const WORKFLOW_REF = 'manufact-test/mini-games-world/.github/workflows/staging-playwright-e2e.yml@refs/heads/agent/mvp-13-2-staging';
    private const MAX_TOKEN_LIFETIME_SECONDS = 600;
    private const CLOCK_SKEW_SECONDS = 60;
    private const JWKS_CACHE_SECONDS = 21600;

    public function __construct(
        private array $config,
        private ?Closure $httpGet = null,
        private ?Closure $clock = null
    ) {}

    public function verifyAndConsume(string $jwt): array
    {
        $jwt = trim($jwt);
        if ($jwt === '' || strlen($jwt) > 12000) {
            throw new RuntimeException('GitHub Actions OIDC token is invalid.');
        }

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RuntimeException('GitHub Actions OIDC token is malformed.');
        }

        [$encodedHeader, $encodedClaims, $encodedSignature] = $parts;
        $header = $this->decodeJsonPart($encodedHeader, 'header');
        $claims = $this->decodeJsonPart($encodedClaims, 'claims');
        $signature = $this->base64UrlDecode($encodedSignature);

        if (($header['alg'] ?? null) !== 'RS256'
            || ($header['typ'] ?? null) !== 'JWT'
            || !is_string($header['kid'] ?? null)
            || trim((string)$header['kid']) === '') {
            throw new RuntimeException('GitHub Actions OIDC header is not trusted.');
        }

        $this->verifySignature(
            $encodedHeader . '.' . $encodedClaims,
            $signature,
            trim((string)$header['kid'])
        );
        $this->validateClaims($claims);
        $this->consumeJti((string)$claims['jti'], (int)$claims['exp']);

        return [
            'repository' => (string)$claims['repository'],
            'ref' => (string)$claims['ref'],
            'workflow_ref' => (string)$claims['workflow_ref'],
            'event_name' => (string)$claims['event_name'],
            'run_id' => (string)$claims['run_id'],
            'run_number' => (string)($claims['run_number'] ?? ''),
            'sha' => (string)$claims['sha'],
        ];
    }

    private function validateClaims(array $claims): void
    {
        $now = $this->now();
        $issuer = trim((string)($claims['iss'] ?? ''));
        $audience = $claims['aud'] ?? null;
        $audienceMatches = is_string($audience)
            ? hash_equals(self::AUDIENCE, $audience)
            : (is_array($audience) && in_array(self::AUDIENCE, $audience, true));

        $issuedAt = (int)($claims['iat'] ?? 0);
        $notBefore = (int)($claims['nbf'] ?? $issuedAt);
        $expiresAt = (int)($claims['exp'] ?? 0);
        $jti = trim((string)($claims['jti'] ?? ''));
        $sha = trim((string)($claims['sha'] ?? ''));
        $runId = trim((string)($claims['run_id'] ?? ''));

        if ($issuer !== self::ISSUER
            || !$audienceMatches
            || (string)($claims['repository'] ?? '') !== self::REPOSITORY
            || (string)($claims['repository_id'] ?? '') !== self::REPOSITORY_ID
            || (string)($claims['repository_owner'] ?? '') !== self::REPOSITORY_OWNER
            || (string)($claims['repository_owner_id'] ?? '') !== self::REPOSITORY_OWNER_ID
            || (string)($claims['ref'] ?? '') !== self::STAGING_REF
            || (string)($claims['event_name'] ?? '') !== 'push'
            || (string)($claims['workflow_ref'] ?? '') !== self::WORKFLOW_REF
            || preg_match('/^[a-f0-9]{40}$/', $sha) !== 1
            || preg_match('/^[0-9]+$/', $runId) !== 1
            || $jti === ''
            || strlen($jti) > 255) {
            throw new RuntimeException('GitHub Actions OIDC claims are not authorized.');
        }

        if ($issuedAt <= 0
            || $notBefore <= 0
            || $expiresAt <= 0
            || $issuedAt > $now + self::CLOCK_SKEW_SECONDS
            || $notBefore > $now + self::CLOCK_SKEW_SECONDS
            || $expiresAt < $now - self::CLOCK_SKEW_SECONDS
            || $expiresAt <= $issuedAt
            || $expiresAt - $issuedAt > self::MAX_TOKEN_LIFETIME_SECONDS) {
            throw new RuntimeException('GitHub Actions OIDC token lifetime is invalid.');
        }
    }

    private function verifySignature(string $input, string $signature, string $kid): void
    {
        if (!function_exists('openssl_verify')) {
            throw new RuntimeException('OpenSSL is unavailable for OIDC verification.');
        }

        $certificate = $this->certificateForKid($kid, false);
        if ($certificate === null) {
            $certificate = $this->certificateForKid($kid, true);
        }
        if ($certificate === null) {
            throw new RuntimeException('GitHub Actions OIDC signing key is unavailable.');
        }

        $publicKey = openssl_pkey_get_public($certificate);
        if ($publicKey === false) {
            throw new RuntimeException('GitHub Actions OIDC public key is invalid.');
        }

        try {
            $verified = openssl_verify($input, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        } finally {
            if (is_resource($publicKey)) {
                openssl_free_key($publicKey);
            }
        }

        if ($verified !== 1) {
            throw new RuntimeException('GitHub Actions OIDC signature is invalid.');
        }
    }

    private function certificateForKid(string $kid, bool $forceRefresh): ?string
    {
        $jwks = $this->loadJwks($forceRefresh);
        foreach (($jwks['keys'] ?? []) as $key) {
            if (!is_array($key)
                || (string)($key['kid'] ?? '') !== $kid
                || (string)($key['kty'] ?? '') !== 'RSA'
                || (string)($key['use'] ?? '') !== 'sig'
                || (string)($key['alg'] ?? '') !== 'RS256') {
                continue;
            }
            $chain = $key['x5c'] ?? null;
            $encodedCertificate = is_array($chain) ? trim((string)($chain[0] ?? '')) : '';
            if ($encodedCertificate === '' || base64_decode($encodedCertificate, true) === false) {
                continue;
            }
            return "-----BEGIN CERTIFICATE-----\n"
                . chunk_split($encodedCertificate, 64, "\n")
                . "-----END CERTIFICATE-----\n";
        }
        return null;
    }

    private function loadJwks(bool $forceRefresh): array
    {
        $cachePath = $this->privateDirectory() . '/jwks.json';
        if (!$forceRefresh && is_file($cachePath)) {
            $modifiedAt = filemtime($cachePath) ?: 0;
            if ($modifiedAt > 0 && $this->now() - $modifiedAt <= self::JWKS_CACHE_SECONDS) {
                $cached = $this->readJsonFile($cachePath);
                if (is_array($cached['keys'] ?? null)) {
                    return $cached;
                }
            }
        }

        $raw = $this->fetch(self::JWKS_URL);
        $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_array($decoded['keys'] ?? null) || $decoded['keys'] === []) {
            throw new RuntimeException('GitHub Actions OIDC key set is invalid.');
        }
        $this->writePrivateJson($cachePath, $decoded);
        return $decoded;
    }

    private function fetch(string $url): string
    {
        if ($url !== self::JWKS_URL) {
            throw new RuntimeException('Unexpected OIDC key endpoint.');
        }
        if ($this->httpGet instanceof Closure) {
            $result = ($this->httpGet)($url);
            if (!is_string($result) || trim($result) === '') {
                throw new RuntimeException('OIDC key endpoint returned no data.');
            }
            return $result;
        }

        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            if ($curl === false) {
                throw new RuntimeException('Could not initialize OIDC key request.');
            }
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_USERAGENT => 'MiniGamesWorld-Staging-E2E/1.0',
            ]);
            $body = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            if (!is_string($body) || $status !== 200 || trim($body) === '') {
                throw new RuntimeException('OIDC key endpoint request failed.');
            }
            return $body;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 8,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: MiniGamesWorld-Staging-E2E/1.0\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if (!is_string($body) || trim($body) === '') {
            throw new RuntimeException('OIDC key endpoint request failed.');
        }
        return $body;
    }

    private function consumeJti(string $jti, int $expiresAt): void
    {
        $path = $this->privateDirectory() . '/used-jti.json';
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('OIDC replay registry is unavailable.');
        }
        @chmod($path, 0600);

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('OIDC replay registry is busy.');
            }
            rewind($handle);
            $raw = stream_get_contents($handle);
            $registry = ['schema_version' => 1, 'used' => []];
            if (is_string($raw) && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    throw new RuntimeException('OIDC replay registry is invalid.');
                }
                $registry['used'] = is_array($decoded['used'] ?? null) ? $decoded['used'] : [];
            }

            $now = $this->now();
            foreach ($registry['used'] as $hash => $storedExpiry) {
                if ((int)$storedExpiry < $now - self::CLOCK_SKEW_SECONDS) {
                    unset($registry['used'][$hash]);
                }
            }

            $jtiHash = hash('sha256', 'github-actions-oidc|' . $jti);
            if (isset($registry['used'][$jtiHash])) {
                throw new RuntimeException('GitHub Actions OIDC token replay was rejected.');
            }
            $registry['used'][$jtiHash] = $expiresAt;

            $json = json_encode($registry, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $json) === false || !fflush($handle)) {
                throw new RuntimeException('OIDC replay registry cannot be written.');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function privateDirectory(): string
    {
        $dataDir = rtrim(trim((string)($this->config['data_dir'] ?? '')), '/\\');
        if ($dataDir === '') {
            throw new RuntimeException('OIDC private data directory is unavailable.');
        }
        $directory = $dataDir . '/.runtime/staging-github-oidc';
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('OIDC private data directory is unavailable.');
        }
        @chmod($directory, 0700);
        return $directory;
    }

    private function readJsonFile(string $path): array
    {
        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writePrivateJson(string $path, array $value): void
    {
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (@file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw new RuntimeException('OIDC cache cannot be written.');
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('OIDC cache cannot be published.');
        }
        @chmod($path, 0600);
    }

    private function decodeJsonPart(string $encoded, string $label): array
    {
        $decoded = $this->base64UrlDecode($encoded);
        $value = json_decode($decoded, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new RuntimeException('GitHub Actions OIDC ' . $label . ' is invalid.');
        }
        return $value;
    }

    private function base64UrlDecode(string $encoded): string
    {
        if ($encoded === '' || preg_match('/^[A-Za-z0-9_-]+$/', $encoded) !== 1) {
            throw new RuntimeException('GitHub Actions OIDC encoding is invalid.');
        }
        $padding = (4 - strlen($encoded) % 4) % 4;
        $decoded = base64_decode(strtr($encoded . str_repeat('=', $padding), '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('GitHub Actions OIDC encoding is invalid.');
        }
        return $decoded;
    }

    private function now(): int
    {
        return $this->clock instanceof Closure ? (int)($this->clock)() : time();
    }
}
