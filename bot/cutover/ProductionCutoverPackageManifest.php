<?php
declare(strict_types=1);

final class ProductionCutoverPackageManifest
{
    public const PACKAGE_VERSION = 'v1-mvp14-10e-cutover-recovery-package';
    public const BUILD = 'v103-mvp14-production-cutover';

    private const CRITICAL_FILES = [
        'bot/api.php',
        'bot/webhook.php',
        'bot/handlers/WebhookHandler.php',
        'bot/storage/StorageFactory.php',
        'bot/storage/RuntimeStorageRouter.php',
        'bot/runtime/ProductionPrimaryRuntimeActivationContract.php',
        'bot/runtime/ProductionPrimaryRuntimeCoordinator.php',
        'bot/runtime/ProductionPrimaryEntrypointBootstrap.php',
        'bot/runtime/ProductionPrimaryEntrypointStorageContext.php',
        'bot/runtime/ProductionPrimaryAtomicStorageAdapter.php',
        'bot/runtime/ProductionPrimaryProjectorFactory.php',
        'bot/runtime/ProductionPrimaryRollbackExportGate.php',
        'bot/runtime/ProductionPrimaryRollbackExportVerifier.php',
        'bot/runtime/ProductionPrimaryRollbackExportService.php',
        'bot/runtime/ProductionPrimaryLiveRollbackGate.php',
        'bot/runtime/ProductionPrimaryLiveRollbackService.php',
        'bot/runtime/ProductionPrimaryRuntimeOverlayWriter.php',
        'bot/runtime/ProductionPrimaryLiveRollbackStateStore.php',
        'bot/cutover/ProductionPreflightService.php',
        'bot/cutover/ProductionPreflightRunner.php',
        'bot/cutover/ProductionCutoverConfig.php',
        'bot/cutover/ProductionCutoverPackageManifest.php',
        'bot/cutover/ProductionCutoverPackageGuardTrait.php',
        'bot/cutover/ProductionCutoverExactPreflight.php',
        'bot/cutover/ProductionCutoverReleaseReceiptVerifier.php',
        'bot/cutover/ProductionRuntimePrimaryContract.php',
        'bot/cutover/ProductionCutoverRunner.php',
        'bot/cutover/ProductionCutoverRunTrait.php',
        'bot/cutover/ProductionCutoverPerformTrait.php',
        'bot/cutover/ProductionCutoverReleaseTrait.php',
        'bot/cutover/ProductionCutoverControlTrait.php',
        'bot/cutover/ProductionCutoverRecoveryPolicyTrait.php',
        'bot/cutover/ProductionCutoverDataTrait.php',
        'bot/cutover/ProductionCutoverRuntimeTrait.php',
        'bot/cutover/ProductionCutoverReportTrait.php',
        'bot/cutover/ProductionCutoverNoopTrait.php',
        'ops/deploy/production-cutover.php',
        'ops/runtime/run-production-primary-rollback-export.php',
        'ops/runtime/run-production-primary-live-rollback.php',
    ];

    public function __construct(private string $projectRoot)
    {
        $this->projectRoot = $this->canonicalDirectory($this->projectRoot);
    }

    public function inspect(): array
    {
        $checks = [];
        $files = [];
        foreach (self::CRITICAL_FILES as $relative) {
            $path = $this->projectRoot . '/' . $relative;
            $present = !is_link($path) && is_file($path) && is_readable($path);
            $sha = $present ? hash_file('sha256', $path) : false;
            $validSha = is_string($sha) && preg_match('/\A[a-f0-9]{64}\z/', $sha) === 1;
            $files[$relative] = $validSha ? $sha : '';
            $checks['file:' . $relative] = $present && $validSha;
        }
        ksort($files, SORT_STRING);
        ksort($checks, SORT_STRING);

        $commit = '';
        $commitError = '';
        try {
            $commit = $this->resolveCommit();
        } catch (Throwable $error) {
            $commitError = $this->safeMessage($error->getMessage());
        }
        $checks['git_commit_exact'] = preg_match('/\A[a-f0-9]{40}\z/', $commit) === 1;
        $checks['package_files_complete'] = !in_array('', $files, true);

        $blockers = [];
        foreach ($checks as $name => $passed) {
            if ($passed !== true) $blockers[] = 'cutover package check failed: ' . $name;
        }
        if ($commitError !== '') $blockers[] = 'cutover package commit error: ' . $commitError;
        $blockers = array_values(array_unique($blockers));
        sort($blockers, SORT_STRING);

        $packagePayload = [
            'package_version' => self::PACKAGE_VERSION,
            'build' => self::BUILD,
            'release_commit' => $commit,
            'files' => $files,
        ];

        return [
            'ready' => $blockers === [],
            'package_version' => self::PACKAGE_VERSION,
            'build' => self::BUILD,
            'release_commit' => $commit,
            'package_fingerprint' => hash('sha256', self::canonicalJson($packagePayload)),
            'critical_file_count' => count($files),
            'critical_file_fingerprints' => $files,
            'checks' => $checks,
            'blockers' => $blockers,
            'database_contacted' => false,
            'persistent_config_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    private function resolveCommit(): string
    {
        $gitDir = $this->projectRoot . '/.git';
        if (is_link($gitDir)) {
            throw new RuntimeException('Git metadata link is not allowed.');
        }
        if (is_file($gitDir)) {
            $raw = trim((string)file_get_contents($gitDir));
            if (preg_match('/\Agitdir:\s*(.+)\z/', $raw, $matches) !== 1) {
                throw new RuntimeException('Git metadata file is invalid.');
            }
            $candidate = trim($matches[1]);
            if (!str_starts_with($candidate, '/')) {
                $candidate = $this->projectRoot . '/' . $candidate;
            }
            $gitDir = $this->canonicalDirectory($candidate);
        } else {
            $gitDir = $this->canonicalDirectory($gitDir);
        }

        $headFile = $gitDir . '/HEAD';
        if (is_link($headFile) || !is_file($headFile)) {
            throw new RuntimeException('Git HEAD is unavailable.');
        }
        $head = trim((string)file_get_contents($headFile));
        if (preg_match('/\A[a-f0-9]{40}\z/', $head) === 1) return $head;
        if (preg_match('#\Aref:\s*(refs/(?:heads|tags)/[A-Za-z0-9._/-]+)\z#', $head, $matches) !== 1
            || str_contains($matches[1], '..')) {
            throw new RuntimeException('Git HEAD reference is invalid.');
        }
        $ref = $matches[1];
        $refFile = $gitDir . '/' . $ref;
        if (!is_link($refFile) && is_file($refFile)) {
            $commit = trim((string)file_get_contents($refFile));
            if (preg_match('/\A[a-f0-9]{40}\z/', $commit) === 1) return $commit;
        }

        $packed = $gitDir . '/packed-refs';
        if (!is_link($packed) && is_file($packed)) {
            foreach (file($packed, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                if (str_starts_with($line, '#') || str_starts_with($line, '^')) continue;
                [$commit, $packedRef] = array_pad(preg_split('/\s+/', trim($line), 2) ?: [], 2, '');
                if ($packedRef === $ref && preg_match('/\A[a-f0-9]{40}\z/', $commit) === 1) {
                    return $commit;
                }
            }
        }
        throw new RuntimeException('Git commit could not be resolved exactly.');
    }

    private function canonicalDirectory(string $path): string
    {
        if ($path === '' || str_contains($path, '\\') || !str_starts_with($path, '/')
            || is_link($path) || !is_dir($path)) {
            throw new InvalidArgumentException('Cutover package directory is invalid.');
        }
        $canonical = realpath($path);
        if (!is_string($canonical) || !hash_equals($path, $canonical)) {
            throw new InvalidArgumentException('Cutover package directory is not canonical.');
        }
        return $canonical;
    }

    private function safeMessage(string $message): string
    {
        $message = preg_replace('~/(?:home|var|tmp|srv)/[^\s\'\"]+~', '[private-path]', $message) ?? $message;
        return mb_substr(trim($message), 0, 300);
    }

    private static function canonicalJson(array $value): string
    {
        $canonicalize = static function (mixed $item) use (&$canonicalize): mixed {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item, SORT_STRING);
            foreach ($item as $key => $child) $item[$key] = $canonicalize($child);
            return $item;
        };
        return json_encode(
            $canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}
