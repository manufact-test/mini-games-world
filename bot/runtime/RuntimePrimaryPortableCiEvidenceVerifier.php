<?php
declare(strict_types=1);

final class RuntimePrimaryPortableCiEvidenceVerifier
{
    public const REPORT_TYPE = 'mvp-14.8.6o-portable-self-hosted-focused-suite';
    public const SUITE = 'db-primary-portable-self-hosted-ci-local';
    public const MANIFEST_CONTRACT = 'v1-portable-db-primary-focused-suite';
    public const EXPECTED_SCRIPT_COUNT = 13;

    private const SUMMARY_FILE = 'focused-suite-summary.json';
    private const LOG_FILE = 'focused-suite.log';
    private const MANIFEST_FILE = 'focused-suite-manifest.json';
    private const MAX_SUMMARY_BYTES = 65_536;
    private const MAX_MANIFEST_BYTES = 262_144;
    private const MAX_LOG_BYTES = 20_971_520;

    public function __construct(
        private string $evidenceDirectory,
        private ?string $expectedCommit = null
    ) {
        $this->evidenceDirectory = str_replace('\\', '/', trim($this->evidenceDirectory));
        $this->expectedCommit = $this->expectedCommit === null
            ? null
            : strtolower(trim($this->expectedCommit));
        if ($this->expectedCommit !== null
            && preg_match('/^[a-f0-9]{40}$/', $this->expectedCommit) !== 1) {
            throw new InvalidArgumentException('Portable CI expected commit must be a full lowercase SHA-1.');
        }
    }

    public function verify(): array
    {
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
            'tracked_worktree_unchanged',
            'live_database_contacted',
            'private_config_required',
            'application_entrypoints_changed',
            'cron_changed',
            'deployment_performed',
            'production_changed',
            'sensitive_identifiers_exposed',
        ], 'Portable CI summary');

        if (($summary['ok'] ?? null) !== true
            || ($summary['report_type'] ?? '') !== self::REPORT_TYPE
            || ($summary['suite'] ?? '') !== self::SUITE
            || (int)($summary['exit_code'] ?? -1) !== 0
            || ($summary['tracked_worktree_unchanged'] ?? null) !== true) {
            throw new RuntimeException('Portable CI summary does not represent a successful immutable run.');
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
                throw new RuntimeException('Portable CI summary safety flag must be false: ' . $field . '.');
            }
        }

        $commit = strtolower(trim((string)($summary['repository_commit'] ?? '')));
        if (preg_match('/^[a-f0-9]{40}$/', $commit) !== 1) {
            throw new RuntimeException('Portable CI summary repository commit is invalid.');
        }
        if ($this->expectedCommit !== null && !hash_equals($this->expectedCommit, $commit)) {
            throw new RuntimeException('Portable CI evidence belongs to a different repository commit.');
        }
        $phpVersion = trim((string)($summary['php_version'] ?? ''));
        if (preg_match('/^8\.3\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $phpVersion) !== 1) {
            throw new RuntimeException('Portable CI evidence was not produced by PHP 8.3.x.');
        }

        $manifestSha = strtolower(trim((string)($summary['suite_manifest_sha256'] ?? '')));
        $logSha = strtolower(trim((string)($summary['log_sha256'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $manifestSha) !== 1
            || !hash_equals($manifestSha, hash('sha256', $manifestRaw))) {
            throw new RuntimeException('Portable CI manifest SHA-256 does not match the evidence file.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $logSha) !== 1
            || !hash_equals($logSha, hash('sha256', $logRaw))) {
            throw new RuntimeException('Portable CI log SHA-256 does not match the evidence file.');
        }

        $manifestReport = $this->verifyManifest($manifest);
        if ((int)($summary['suite_manifest_script_count'] ?? 0)
            !== (int)$manifestReport['unique_script_count']) {
            throw new RuntimeException('Portable CI summary script count does not match the manifest.');
        }
        $timeline = $this->verifyTimeline($summary);
        $markerReport = $this->verifyLogMarkers($logRaw, $manifestReport['ordered_success_markers']);

        return [
            'ok' => true,
            'action' => 'portable_ci_evidence_verified',
            'report_type' => self::REPORT_TYPE,
            'repository_commit' => $commit,
            'php_version' => $phpVersion,
            'suite_manifest_sha256' => $manifestSha,
            'suite_manifest_script_count' => (int)$manifestReport['unique_script_count'],
            'log_sha256' => $logSha,
            'success_marker_count' => (int)$markerReport['success_marker_count'],
            'duration_seconds' => (int)$timeline['duration_seconds'],
            'tracked_worktree_unchanged' => true,
            'live_database_contacted' => false,
            'private_config_required' => false,
            'application_entrypoints_changed' => false,
            'cron_changed' => false,
            'deployment_performed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'verified_at_utc' => gmdate(DATE_ATOM),
        ];
    }

    private function canonicalDirectory(): string
    {
        if ($this->evidenceDirectory === ''
            || !str_starts_with($this->evidenceDirectory, '/')
            || is_link($this->evidenceDirectory)
            || !is_dir($this->evidenceDirectory)) {
            throw new RuntimeException('Portable CI evidence directory must be an absolute real directory.');
        }
        $real = realpath($this->evidenceDirectory);
        if (!is_string($real)) {
            throw new RuntimeException('Portable CI evidence directory canonical path is unavailable.');
        }
        $real = str_replace('\\', '/', $real);
        if (!hash_equals(rtrim($this->evidenceDirectory, '/'), rtrim($real, '/'))) {
            throw new RuntimeException('Portable CI evidence directory must use its canonical path.');
        }
        if (preg_match('~(?:^|/)public_html(?:/|$)~', $real) === 1) {
            throw new RuntimeException('Portable CI evidence must not be verified inside public_html.');
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
            throw new RuntimeException('Portable CI evidence directory must contain exactly three evidence files.');
        }
        $paths = [];
        foreach ($expected as $name) {
            $path = $directory . '/' . $name;
            if (is_link($path) || !is_file($path)) {
                throw new RuntimeException('Portable CI evidence file is unavailable or unsafe: ' . $name . '.');
            }
            $paths[$name] = $path;
        }
        return $paths;
    }

    private function readBoundedFile(string $path, int $maximumBytes, string $label): string
    {
        $size = filesize($path);
        if (!is_int($size) || $size < 1 || $size > $maximumBytes) {
            throw new RuntimeException('Portable CI evidence ' . $label . ' size is invalid.');
        }
        $content = file_get_contents($path);
        if (!is_string($content) || strlen($content) !== $size) {
            throw new RuntimeException('Portable CI evidence ' . $label . ' could not be read exactly.');
        }
        return $content;
    }

    private function decodeObject(string $json, string $label): array
    {
        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Portable CI evidence ' . $label . ' JSON is invalid.', 0, $error);
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException('Portable CI evidence ' . $label . ' must be a JSON object.');
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
        ], 'Portable CI manifest');
        if (($manifest['contract_version'] ?? '') !== self::MANIFEST_CONTRACT
            || ($manifest['entrypoint'] ?? '') !== 'ops/checks/db-primary-portable-self-hosted-ci-local.sh'
            || (int)($manifest['expected_unique_script_count'] ?? 0) !== self::EXPECTED_SCRIPT_COUNT) {
            throw new RuntimeException('Portable CI manifest identity is invalid.');
        }

        $roots = $manifest['ordered_roots'] ?? null;
        $chain = $manifest['recursive_chain'] ?? null;
        if (!is_array($roots) || !array_is_list($roots) || count($roots) !== 3
            || !is_array($chain) || !array_is_list($chain) || count($chain) !== 11) {
            throw new RuntimeException('Portable CI manifest roots or recursive chain are invalid.');
        }
        $expectedRoots = [
            'ops/checks/db-primary-projection-outbox-local.sh',
            'ops/checks/db-primary-projection-worker-local.sh',
            'ops/checks/db-primary-staging-api-read-only-smoke-local.sh',
        ];
        $scripts = [];
        $rootMarkers = [];
        foreach ($roots as $index => $root) {
            $this->assertNode($root, 'root');
            $script = (string)$root['script'];
            if ($script !== $expectedRoots[$index]) {
                throw new RuntimeException('Portable CI manifest root order is invalid.');
            }
            $scripts[$script] = true;
            $rootMarkers[] = (string)$root['success_marker'];
        }
        $chainMarkers = [];
        foreach ($chain as $index => $node) {
            $this->assertNode($node, 'chain');
            $script = (string)$node['script'];
            $scripts[$script] = true;
            $chainMarkers[] = (string)$node['success_marker'];
            $next = $node['next_script'];
            if ($index === count($chain) - 1) {
                if ($next !== null) {
                    throw new RuntimeException('Portable CI manifest final chain node must terminate.');
                }
            } elseif (!is_string($next) || ($chain[$index + 1]['script'] ?? null) !== $next) {
                throw new RuntimeException('Portable CI manifest recursive chain link is invalid.');
            }
        }
        if (count($scripts) !== self::EXPECTED_SCRIPT_COUNT) {
            throw new RuntimeException('Portable CI manifest unique script coverage is incomplete.');
        }
        $safety = $manifest['safety'] ?? null;
        if (!is_array($safety) || array_is_list($safety)) {
            throw new RuntimeException('Portable CI manifest safety object is invalid.');
        }
        $this->assertExactKeys($safety, [
            'live_database_contacted',
            'private_config_required',
            'application_entrypoints_changed',
            'cron_changed',
            'deployment_performed',
            'production_changed',
            'sensitive_identifiers_exposed',
        ], 'Portable CI manifest safety');
        foreach ($safety as $field => $value) {
            if ($value !== false) {
                throw new RuntimeException('Portable CI manifest safety flag must be false: ' . $field . '.');
            }
        }

        $orderedMarkers = [
            $rootMarkers[0],
            $rootMarkers[1],
            ...array_reverse($chainMarkers),
            'DB-primary portable self-hosted CI focused verification passed.',
        ];
        if (count(array_unique($orderedMarkers)) !== count($orderedMarkers)) {
            throw new RuntimeException('Portable CI manifest success markers must be unique after root de-duplication.');
        }
        return [
            'unique_script_count' => count($scripts),
            'ordered_success_markers' => $orderedMarkers,
        ];
    }

    private function assertNode(mixed $node, string $type): void
    {
        if (!is_array($node) || array_is_list($node)) {
            throw new RuntimeException('Portable CI manifest ' . $type . ' node must be an object.');
        }
        $expected = $type === 'root'
            ? ['script', 'success_marker']
            : ['script', 'next_script', 'success_marker'];
        $this->assertExactKeys($node, $expected, 'Portable CI manifest ' . $type . ' node');
        $script = trim((string)($node['script'] ?? ''));
        $marker = trim((string)($node['success_marker'] ?? ''));
        if ($script === ''
            || !str_starts_with($script, 'ops/checks/')
            || !str_ends_with($script, '.sh')
            || str_contains($script, '..')
            || str_contains($script, '\\')
            || $marker === ''
            || strlen($marker) > 200
            || str_contains($marker, "\n")) {
            throw new RuntimeException('Portable CI manifest ' . $type . ' node values are invalid.');
        }
    }

    private function verifyTimeline(array $summary): array
    {
        $startedRaw = trim((string)($summary['started_at_utc'] ?? ''));
        $finishedRaw = trim((string)($summary['finished_at_utc'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $startedRaw) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $finishedRaw) !== 1) {
            throw new RuntimeException('Portable CI evidence timestamps must use exact UTC Z format.');
        }
        $started = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $startedRaw, new DateTimeZone('UTC'));
        $finished = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $finishedRaw, new DateTimeZone('UTC'));
        if (!$started instanceof DateTimeImmutable || !$finished instanceof DateTimeImmutable) {
            throw new RuntimeException('Portable CI evidence timestamps are invalid.');
        }
        $duration = (int)($summary['duration_seconds'] ?? -1);
        $actual = $finished->getTimestamp() - $started->getTimestamp();
        if ($duration < 0 || $duration > 7200 || $actual !== $duration) {
            throw new RuntimeException('Portable CI evidence duration does not match its timestamps.');
        }
        return ['duration_seconds' => $duration];
    }

    private function verifyLogMarkers(string $log, array $markers): array
    {
        $previous = -1;
        foreach ($markers as $marker) {
            $position = strpos($log, $marker);
            if ($position === false || $position <= $previous) {
                throw new RuntimeException('Portable CI log success-marker order is incomplete or invalid.');
            }
            if (strpos($log, $marker, $position + 1) !== false) {
                throw new RuntimeException('Portable CI log contains a duplicate success marker.');
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
