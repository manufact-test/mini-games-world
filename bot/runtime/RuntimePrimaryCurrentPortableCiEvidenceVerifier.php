<?php
declare(strict_types=1);

final class RuntimePrimaryCurrentPortableCiEvidenceVerifier
{
    public const REPORT_TYPE = 'mvp-14.8.6s-current-portable-validation';
    public const SUITE = 'db-primary-current-portable-validation-local';
    public const MANIFEST_CONTRACT = 'v2-current-db-primary-focused-suite';
    public const EXPECTED_SCRIPT_COUNT = 14;

    private const SUMMARY_FILE = 'current-focused-suite-summary.json';
    private const LOG_FILE = 'current-focused-suite.log';
    private const MANIFEST_FILE = 'current-focused-suite-manifest.json';
    private const MAX_SUMMARY_BYTES = 65_536;
    private const MAX_MANIFEST_BYTES = 262_144;
    private const MAX_LOG_BYTES = 20_971_520;
    private const MAX_FUTURE_SKEW_SECONDS = 30;

    private const EXPECTED_ROOTS = [
        [
            'script' => 'ops/checks/db-primary-projection-outbox-local.sh',
            'success_marker' => 'DB-primary projection outbox focused verification passed.',
        ],
        [
            'script' => 'ops/checks/db-primary-projection-worker-local.sh',
            'success_marker' => 'DB-primary projection worker focused verification passed.',
        ],
        [
            'script' => 'ops/checks/db-primary-staging-api-read-only-smoke-evidence-verifier-local.sh',
            'success_marker' => 'DB-primary staging API read-only smoke evidence verifier focused verification passed.',
        ],
    ];

    private const EXPECTED_CHAIN = [
        [
            'script' => 'ops/checks/db-primary-staging-api-read-only-smoke-evidence-verifier-local.sh',
            'next_script' => 'ops/checks/db-primary-staging-api-read-only-smoke-local.sh',
            'success_marker' => 'DB-primary staging API read-only smoke evidence verifier focused verification passed.',
        ],
        [
            'script' => 'ops/checks/db-primary-staging-api-read-only-smoke-local.sh',
            'next_script' => 'ops/checks/db-primary-staging-api-session-integration-local.sh',
            'success_marker' => 'DB-primary staging API read-only smoke focused verification passed.',
        ],
        [
            'script' => 'ops/checks/db-primary-staging-api-session-integration-local.sh',
            'next_script' => 'ops/checks/db-primary-staging-request-finalizer-local.sh',
            'success_marker' => 'DB-primary staging API session integration focused verification passed.',
        ],
        [
            'script' => 'ops/checks/db-primary-staging-request-finalizer-local.sh',
            'next_script' => 'ops/checks/db-primary-staging-entrypoint-selector-local.sh',
            'success_marker' => 'DB-primary staging request finalizer focused verification passed.',
        ],
        [
            'script' => 'ops/checks/db-primary-staging-entrypoint-selector-local.sh',
            'next_script' => 'ops/checks/db-primary-staging-synthetic-suite-local.sh',
            'success_marker' => 'DB-primary staging entrypoint selector focused verification passed.',
        ],
        [
            'script' => 'ops/checks/db-primary-staging-synthetic-suite-local.sh',
            'next_script' => 'ops/checks/db-primary-staging-storage-resolver-local.sh',
            'success_marker' => 'DB-primary staging synthetic suite focused verification passed.',
        ],
        [
            'script' => 'ops/checks/db-primary-staging-storage-resolver-local.sh',
            'next_script' => 'ops/checks/db-primary-staging-activation-local.sh',
            'success_marker' => 'DB-primary staging storage resolver focused verification passed.',
        ],
        [
            'script' => 'ops/checks/db-primary-staging-activation-local.sh',
            'next_script' => 'ops/checks/db-primary-staging-evidence-collector-local.sh',
            'success_marker' => 'DB-primary staging activation focused verification passed.',
        ],
        [
            'script' => 'ops/checks/db-primary-staging-evidence-collector-local.sh',
            'next_script' => 'ops/checks/db-primary-staging-evidence-local.sh',
            'success_marker' => 'DB-primary staging evidence collector focused verification passed.',
        ],
        [
            'script' => 'ops/checks/db-primary-staging-evidence-local.sh',
            'next_script' => 'ops/checks/db-primary-staging-rehearsal-local.sh',
            'success_marker' => 'DB-primary staging evidence focused verification passed.',
        ],
        [
            'script' => 'ops/checks/db-primary-staging-rehearsal-local.sh',
            'next_script' => 'ops/checks/db-primary-all-module-projector-local.sh',
            'success_marker' => 'DB-primary staging rehearsal focused verification passed.',
        ],
        [
            'script' => 'ops/checks/db-primary-all-module-projector-local.sh',
            'next_script' => null,
            'success_marker' => 'DB-primary all-module projector focused verification passed.',
        ],
    ];

    public function __construct(
        private string $evidenceDirectory,
        private string $expectedCommit,
        private int $maximumAgeSeconds = 604_800
    ) {
        $this->evidenceDirectory = str_replace('\\', '/', $this->evidenceDirectory);
        if (preg_match('/^[a-f0-9]{40}$/', $this->expectedCommit) !== 1) {
            throw new InvalidArgumentException('Current portable CI expected commit must be a full lowercase SHA-1.');
        }
        if ($this->maximumAgeSeconds < 60 || $this->maximumAgeSeconds > 604_800) {
            throw new InvalidArgumentException('Current portable CI maximum age must be between 60 and 604800 seconds.');
        }
    }

    public function verify(?int $now = null): array
    {
        $now ??= time();
        if ($now < 1) {
            throw new InvalidArgumentException('Current portable CI verification time is invalid.');
        }

        $directory = $this->canonicalDirectory();
        $paths = $this->exactBundlePaths($directory);
        $summaryRaw = $this->readBoundedFile(
            $paths[self::SUMMARY_FILE],
            self::MAX_SUMMARY_BYTES,
            'summary'
        );
        $manifestRaw = $this->readBoundedFile(
            $paths[self::MANIFEST_FILE],
            self::MAX_MANIFEST_BYTES,
            'manifest'
        );
        $logRaw = $this->readBoundedFile(
            $paths[self::LOG_FILE],
            self::MAX_LOG_BYTES,
            'log'
        );

        $summary = $this->decodeObject($summaryRaw, 'summary');
        $manifest = $this->decodeObject($manifestRaw, 'manifest');
        $this->assertExactKeys($summary, [
            'ok',
            'report_type',
            'suite',
            'suite_manifest_sha256',
            'suite_manifest_script_count',
            'repository_commit',
            'php_version',
            'started_at_utc',
            'finished_at_utc',
            'duration_seconds',
            'exit_code',
            'log_sha256',
            'repository_checkout_unchanged',
            'live_database_contacted',
            'private_config_required',
            'application_entrypoints_changed',
            'cron_changed',
            'deployment_performed',
            'production_changed',
            'sensitive_identifiers_exposed',
        ], 'Current portable CI summary');

        if (($summary['ok'] ?? null) !== true
            || ($summary['report_type'] ?? '') !== self::REPORT_TYPE
            || ($summary['suite'] ?? '') !== self::SUITE
            || ($summary['exit_code'] ?? null) !== 0
            || ($summary['repository_checkout_unchanged'] ?? null) !== true) {
            throw new RuntimeException('Current portable CI summary does not represent a successful immutable run.');
        }
        foreach ([
            'live_database_contacted',
            'private_config_required',
            'application_entrypoints_changed',
            'cron_changed',
            'deployment_performed',
            'production_changed',
            'sensitive_identifiers_exposed',
        ] as $field) {
            if (($summary[$field] ?? null) !== false) {
                throw new RuntimeException(
                    'Current portable CI summary safety flag must be false: ' . $field . '.'
                );
            }
        }

        $commit = (string)($summary['repository_commit'] ?? '');
        if (preg_match('/^[a-f0-9]{40}$/', $commit) !== 1) {
            throw new RuntimeException('Current portable CI summary repository commit is invalid.');
        }
        if (!hash_equals($this->expectedCommit, $commit)) {
            throw new RuntimeException('Current portable CI evidence belongs to a different repository commit.');
        }

        $phpVersion = (string)($summary['php_version'] ?? '');
        if (preg_match('/^8\.3\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $phpVersion) !== 1) {
            throw new RuntimeException('Current portable CI evidence was not produced by PHP 8.3.x.');
        }

        $manifestSha = (string)($summary['suite_manifest_sha256'] ?? '');
        $logSha = (string)($summary['log_sha256'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/', $manifestSha) !== 1
            || !hash_equals($manifestSha, hash('sha256', $manifestRaw))) {
            throw new RuntimeException('Current portable CI manifest SHA-256 does not match the evidence file.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $logSha) !== 1
            || !hash_equals($logSha, hash('sha256', $logRaw))) {
            throw new RuntimeException('Current portable CI log SHA-256 does not match the evidence file.');
        }

        $manifestReport = $this->verifyManifest($manifest);
        if (!is_int($summary['suite_manifest_script_count'] ?? null)
            || $summary['suite_manifest_script_count'] !== $manifestReport['unique_script_count']) {
            throw new RuntimeException('Current portable CI summary script count does not match the manifest.');
        }
        $timeline = $this->verifyTimeline($summary, $now);
        $markerReport = $this->verifyLogMarkers(
            $logRaw,
            $manifestReport['ordered_success_markers']
        );

        return [
            'ok' => true,
            'action' => 'current_portable_ci_evidence_verified',
            'report_type' => self::REPORT_TYPE,
            'repository_commit' => $commit,
            'php_version' => $phpVersion,
            'summary_sha256' => hash('sha256', $summaryRaw),
            'suite_manifest_sha256' => $manifestSha,
            'suite_manifest_script_count' => $manifestReport['unique_script_count'],
            'log_sha256' => $logSha,
            'success_marker_count' => $markerReport['success_marker_count'],
            'duration_seconds' => $timeline['duration_seconds'],
            'evidence_age_seconds' => $timeline['evidence_age_seconds'],
            'repository_checkout_unchanged' => true,
            'live_database_contacted' => false,
            'private_config_required' => false,
            'application_entrypoints_changed' => false,
            'cron_changed' => false,
            'deployment_performed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'verified_at_utc' => gmdate(DATE_ATOM, $now),
        ];
    }

    private function canonicalDirectory(): string
    {
        if ($this->evidenceDirectory === ''
            || !str_starts_with($this->evidenceDirectory, '/')
            || is_link($this->evidenceDirectory)
            || !is_dir($this->evidenceDirectory)) {
            throw new RuntimeException(
                'Current portable CI evidence directory must be an absolute real directory.'
            );
        }
        $real = realpath($this->evidenceDirectory);
        if (!is_string($real)) {
            throw new RuntimeException(
                'Current portable CI evidence directory canonical path is unavailable.'
            );
        }
        $real = str_replace('\\', '/', $real);
        if (!hash_equals(rtrim($this->evidenceDirectory, '/'), rtrim($real, '/'))) {
            throw new RuntimeException(
                'Current portable CI evidence directory must use its canonical path.'
            );
        }
        if (preg_match('~(?:^|/)public_html(?:/|$)~', $real) === 1) {
            throw new RuntimeException(
                'Current portable CI evidence must not be verified inside public_html.'
            );
        }
        clearstatcache(true, $real);
        $mode = fileperms($real);
        if (!is_int($mode) || ($mode & 0o002) !== 0) {
            throw new RuntimeException(
                'Current portable CI evidence directory must not be world-writable.'
            );
        }
        return rtrim($real, '/');
    }

    private function exactBundlePaths(string $directory): array
    {
        $entries = array_values(array_filter(
            scandir($directory) ?: [],
            static fn(string $name): bool => $name !== '.' && $name !== '..'
        ));
        sort($entries, SORT_STRING);
        $expected = [self::LOG_FILE, self::MANIFEST_FILE, self::SUMMARY_FILE];
        sort($expected, SORT_STRING);
        if ($entries !== $expected) {
            throw new RuntimeException(
                'Current portable CI evidence directory must contain exactly three evidence files.'
            );
        }

        $paths = [];
        foreach ($expected as $name) {
            $path = $directory . '/' . $name;
            if (is_link($path) || !is_file($path)) {
                throw new RuntimeException(
                    'Current portable CI evidence file is unavailable or unsafe: ' . $name . '.'
                );
            }
            $paths[$name] = $path;
        }
        return $paths;
    }

    private function readBoundedFile(string $path, int $maximumBytes, string $label): string
    {
        clearstatcache(true, $path);
        $size = filesize($path);
        $mode = fileperms($path);
        if (!is_int($size) || $size < 1 || $size > $maximumBytes) {
            throw new RuntimeException(
                'Current portable CI evidence ' . $label . ' size is invalid.'
            );
        }
        if (!is_int($mode) || ($mode & 0o002) !== 0) {
            throw new RuntimeException(
                'Current portable CI evidence ' . $label . ' must not be world-writable.'
            );
        }
        $content = file_get_contents($path);
        if (!is_string($content) || strlen($content) !== $size) {
            throw new RuntimeException(
                'Current portable CI evidence ' . $label . ' could not be read exactly.'
            );
        }
        return $content;
    }

    private function decodeObject(string $json, string $label): array
    {
        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException(
                'Current portable CI evidence ' . $label . ' JSON is invalid.',
                0,
                $error
            );
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException(
                'Current portable CI evidence ' . $label . ' must be a JSON object.'
            );
        }
        return $value;
    }

    private function verifyManifest(array $manifest): array
    {
        $this->assertExactKeys($manifest, [
            'contract_version',
            'entrypoint',
            'expected_unique_script_count',
            'ordered_roots',
            'recursive_chain',
            'safety',
        ], 'Current portable CI manifest');
        if (($manifest['contract_version'] ?? '') !== self::MANIFEST_CONTRACT
            || ($manifest['entrypoint'] ?? '')
                !== 'ops/checks/db-primary-current-portable-validation-local.sh'
            || ($manifest['expected_unique_script_count'] ?? null)
                !== self::EXPECTED_SCRIPT_COUNT
            || ($manifest['ordered_roots'] ?? null) !== self::EXPECTED_ROOTS
            || ($manifest['recursive_chain'] ?? null) !== self::EXPECTED_CHAIN) {
            throw new RuntimeException(
                'Current portable CI manifest identity or exact script graph is invalid.'
            );
        }

        $safety = $manifest['safety'] ?? null;
        if (!is_array($safety) || array_is_list($safety)) {
            throw new RuntimeException('Current portable CI manifest safety object is invalid.');
        }
        $this->assertExactKeys($safety, [
            'live_database_contacted',
            'private_config_required',
            'application_entrypoints_changed',
            'cron_changed',
            'deployment_performed',
            'production_changed',
            'sensitive_identifiers_exposed',
        ], 'Current portable CI manifest safety');
        foreach ($safety as $field => $value) {
            if ($value !== false) {
                throw new RuntimeException(
                    'Current portable CI manifest safety flag must be false: ' . $field . '.'
                );
            }
        }

        $scripts = [];
        foreach (self::EXPECTED_ROOTS as $node) {
            $scripts[$node['script']] = true;
        }
        foreach (self::EXPECTED_CHAIN as $node) {
            $scripts[$node['script']] = true;
        }
        if (count($scripts) !== self::EXPECTED_SCRIPT_COUNT) {
            throw new LogicException(
                'Current portable CI expected manifest script constants are inconsistent.'
            );
        }

        $chainMarkers = array_map(
            static fn(array $node): string => $node['success_marker'],
            self::EXPECTED_CHAIN
        );
        $orderedMarkers = [
            self::EXPECTED_ROOTS[0]['success_marker'],
            self::EXPECTED_ROOTS[1]['success_marker'],
            ...array_reverse($chainMarkers),
            'DB-primary current portable validation focused verification passed.',
        ];
        if (count(array_unique($orderedMarkers)) !== count($orderedMarkers)) {
            throw new LogicException(
                'Current portable CI expected success-marker constants are inconsistent.'
            );
        }
        return [
            'unique_script_count' => count($scripts),
            'ordered_success_markers' => $orderedMarkers,
        ];
    }

    private function verifyTimeline(array $summary, int $now): array
    {
        $startedRaw = (string)($summary['started_at_utc'] ?? '');
        $finishedRaw = (string)($summary['finished_at_utc'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $startedRaw) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $finishedRaw) !== 1) {
            throw new RuntimeException(
                'Current portable CI evidence timestamps must use exact UTC Z format.'
            );
        }
        $started = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z',
            $startedRaw,
            new DateTimeZone('UTC')
        );
        $finished = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z',
            $finishedRaw,
            new DateTimeZone('UTC')
        );
        if (!$started instanceof DateTimeImmutable
            || !$finished instanceof DateTimeImmutable
            || $started->format('Y-m-d\TH:i:s\Z') !== $startedRaw
            || $finished->format('Y-m-d\TH:i:s\Z') !== $finishedRaw) {
            throw new RuntimeException('Current portable CI evidence timestamps are invalid.');
        }

        $duration = $summary['duration_seconds'] ?? null;
        $actual = $finished->getTimestamp() - $started->getTimestamp();
        if (!is_int($duration) || $duration < 0 || $duration > 7200 || $actual !== $duration) {
            throw new RuntimeException(
                'Current portable CI evidence duration does not match its timestamps.'
            );
        }
        $age = $now - $finished->getTimestamp();
        if ($age < -self::MAX_FUTURE_SKEW_SECONDS) {
            throw new RuntimeException(
                'Current portable CI evidence timestamp is unexpectedly in the future.'
            );
        }
        if ($age > $this->maximumAgeSeconds) {
            throw new RuntimeException(
                'Current portable CI evidence is too old for operational acceptance.'
            );
        }
        return [
            'duration_seconds' => $duration,
            'evidence_age_seconds' => max(0, $age),
        ];
    }

    private function verifyLogMarkers(string $log, array $markers): array
    {
        $lines = preg_split('/\R/', rtrim($log, "\r\n"));
        if (!is_array($lines)) {
            throw new RuntimeException('Current portable CI log lines could not be parsed.');
        }
        $previous = -1;
        foreach ($markers as $marker) {
            $positions = array_keys($lines, $marker, true);
            if (count($positions) !== 1) {
                throw new RuntimeException(
                    count($positions) > 1
                        ? 'Current portable CI log contains a duplicate success marker.'
                        : 'Current portable CI log success-marker order is incomplete or invalid.'
                );
            }
            $position = $positions[0];
            if ($position <= $previous) {
                throw new RuntimeException(
                    'Current portable CI log success-marker order is incomplete or invalid.'
                );
            }
            $previous = $position;
        }
        return ['success_marker_count' => count($markers)];
    }

    private function assertExactKeys(array $value, array $expected, string $label): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new RuntimeException($label . ' fields are not exact.');
        }
    }
}
