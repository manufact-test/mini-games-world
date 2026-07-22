<?php
declare(strict_types=1);

require_once __DIR__ . '/RuntimePrimaryRepositoryCommitResolver.php';

final class RuntimePrimaryStagingApiMutatingSmokeInput
{
    public const CONTRACT_VERSION = 'v1-cli-only-staging-api-mutating-smoke-input';
    public const ENV_INPUT_FILE = 'MGW_STAGING_API_SMOKE_INPUT_FILE';

    private const MAX_INPUT_BYTES = 65_536;
    private const MAX_WINDOW_SECONDS = 600;
    private const MAX_FUTURE_SKEW_SECONDS = 30;

    public static function read(array $config, string $projectRoot, ?int $now = null): string
    {
        $inputFile = getenv(self::ENV_INPUT_FILE);
        if (!is_string($inputFile) || $inputFile === '') {
            $raw = file_get_contents('php://input');
            return is_string($raw) && $raw !== '' ? $raw : '{}';
        }

        $now ??= time();
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('Staging API mutating smoke input is CLI-only.');
        }
        if ($now < 1) {
            throw new RuntimeException('Staging API mutating smoke verification time is invalid.');
        }
        if (($config['environment'] ?? null) !== 'staging') {
            throw new RuntimeException('Staging API mutating smoke input is staging-only.');
        }

        $projectRoot = self::canonicalProjectRoot($projectRoot);
        $settings = self::settings($config);
        self::assertSettings($settings, $projectRoot, $now);
        $path = self::canonicalPrivateInput($inputFile, $projectRoot);

        clearstatcache(true, $path);
        $size = filesize($path);
        if (!is_int($size) || $size < 2 || $size > self::MAX_INPUT_BYTES) {
            throw new RuntimeException('Staging API mutating smoke input size is invalid.');
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || strlen($raw) !== $size) {
            throw new RuntimeException('Staging API mutating smoke input could not be read exactly.');
        }
        if (!hash_equals($settings['expected_payload_sha256'], hash('sha256', $raw))) {
            throw new RuntimeException('Staging API mutating smoke input fingerprint does not match approval.');
        }

        try {
            $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Staging API mutating smoke input JSON is invalid.', 0, $error);
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new RuntimeException('Staging API mutating smoke input must be a JSON object.');
        }
        if (($payload['action'] ?? null) !== $settings['expected_action']) {
            throw new RuntimeException('Staging API mutating smoke input action does not match approval.');
        }
        if ($settings['expected_action'] !== 'bootstrap') {
            throw new RuntimeException('The first staging API mutating smoke supports only bootstrap.');
        }
        $initData = $payload['initData'] ?? null;
        $sessionId = $payload['sessionId'] ?? null;
        if (!is_string($initData) || $initData === '' || strlen($initData) > 16_384
            || !is_string($sessionId) || preg_match('/\A[a-zA-Z0-9_-]{32,120}\z/', $sessionId) !== 1) {
            throw new RuntimeException('Staging API mutating smoke authentication payload is invalid.');
        }

        return $raw;
    }

    private static function settings(array $config): array
    {
        if (!array_key_exists('staging_api_mutating_smoke_input', $config)
            || !is_array($config['staging_api_mutating_smoke_input'])
            || array_is_list($config['staging_api_mutating_smoke_input'])) {
            throw new RuntimeException('Staging API mutating smoke input approval is unavailable.');
        }
        $settings = $config['staging_api_mutating_smoke_input'];
        $expectedKeys = [
            'contract_version', 'enabled', 'expected_action', 'expected_payload_sha256',
            'expected_repository_commit', 'expires_at_utc',
        ];
        $keys = array_keys($settings);
        sort($keys, SORT_STRING);
        if ($keys !== $expectedKeys) {
            throw new RuntimeException('Staging API mutating smoke input approval schema is invalid.');
        }
        return $settings;
    }

    private static function assertSettings(array $settings, string $projectRoot, int $now): void
    {
        if (($settings['enabled'] ?? null) !== true
            || ($settings['contract_version'] ?? null) !== self::CONTRACT_VERSION
            || ($settings['expected_action'] ?? null) !== 'bootstrap') {
            throw new RuntimeException('Staging API mutating smoke input approval identity is invalid.');
        }
        $commit = $settings['expected_repository_commit'] ?? null;
        $payloadSha = $settings['expected_payload_sha256'] ?? null;
        $expiresAt = $settings['expires_at_utc'] ?? null;
        if (!is_string($commit) || preg_match('/\A[a-f0-9]{40}\z/', $commit) !== 1
            || !is_string($payloadSha) || preg_match('/\A[a-f0-9]{64}\z/', $payloadSha) !== 1
            || !is_string($expiresAt)) {
            throw new RuntimeException('Staging API mutating smoke input approval fields are invalid.');
        }
        $currentCommit = RuntimePrimaryRepositoryCommitResolver::resolve($projectRoot);
        if (!hash_equals($commit, $currentCommit)) {
            throw new RuntimeException('Staging API mutating smoke input approval belongs to a different checkout.');
        }
        $expires = self::parseExactUtc($expiresAt)->getTimestamp();
        if ($expires <= $now) {
            throw new RuntimeException('Staging API mutating smoke input approval has expired.');
        }
        if ($expires - $now > self::MAX_WINDOW_SECONDS + self::MAX_FUTURE_SKEW_SECONDS) {
            throw new RuntimeException('Staging API mutating smoke input approval window is too long.');
        }
    }

    private static function canonicalProjectRoot(string $projectRoot): string
    {
        if ($projectRoot === '' || trim($projectRoot) !== $projectRoot
            || str_contains($projectRoot, '\\') || !str_starts_with($projectRoot, '/')
            || ($projectRoot !== '/' && str_ends_with($projectRoot, '/')) || is_link($projectRoot)) {
            throw new RuntimeException('Staging API mutating smoke project root is invalid.');
        }
        $real = realpath($projectRoot);
        if (!is_string($real) || !is_dir($real) || !hash_equals($projectRoot, $real)) {
            throw new RuntimeException('Staging API mutating smoke project root is unavailable or noncanonical.');
        }
        return $real;
    }

    private static function canonicalPrivateInput(string $path, string $projectRoot): string
    {
        if ($path === '' || trim($path) !== $path || str_contains($path, '\\')
            || !str_starts_with($path, '/') || str_ends_with($path, '/')
            || is_link($path) || !is_file($path)) {
            throw new RuntimeException('Staging API mutating smoke input path is unsafe.');
        }
        $real = realpath($path);
        if (!is_string($real) || !hash_equals($path, $real)) {
            throw new RuntimeException('Staging API mutating smoke input path must be canonical.');
        }
        if ($real === $projectRoot || str_starts_with($real, $projectRoot . '/')
            || preg_match('~(?:\A|/)public_html(?:/|\z)~', $real) === 1) {
            throw new RuntimeException('Staging API mutating smoke input must remain outside the deployed project.');
        }
        $parent = dirname($real);
        clearstatcache(true, $real);
        clearstatcache(true, $parent);
        $mode = fileperms($real);
        $parentMode = fileperms($parent);
        if (!is_int($mode) || ($mode & 0777) !== 0600
            || !is_int($parentMode) || ($parentMode & 0022) !== 0) {
            throw new RuntimeException('Staging API mutating smoke input permissions are unsafe.');
        }
        return $real;
    }

    private static function parseExactUtc(string $value): DateTimeImmutable
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00\z/', $value) !== 1) {
            throw new RuntimeException('Staging API mutating smoke input expiry must use exact UTC +00:00.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date instanceof DateTimeImmutable
            || (is_array($errors)
                && (($errors['warning_count'] ?? 0) !== 0 || ($errors['error_count'] ?? 0) !== 0))
            || $date->format(DATE_ATOM) !== $value) {
            throw new RuntimeException('Staging API mutating smoke input expiry is invalid.');
        }
        return $date;
    }
}
