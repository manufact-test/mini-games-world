<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$arguments = array_slice($argv ?? [], 1);
if (count($arguments) !== 1 || !str_starts_with((string)$arguments[0], '--verify=')) {
    fwrite(STDERR, "Usage: php verify-staging-db-primary-evidence.php --verify=/absolute/private/manifest.json\n");
    exit(2);
}
$requestedPath = trim(substr((string)$arguments[0], strlen('--verify=')));
if ($requestedPath === '' || !str_starts_with(str_replace('\\', '/', $requestedPath), '/')) {
    fwrite(STDERR, "Evidence manifest path must be absolute.\n");
    exit(2);
}
if (is_link($requestedPath)) {
    fwrite(STDERR, "Evidence manifest must not be a symbolic link.\n");
    exit(2);
}
$manifestPath = realpath($requestedPath);
if (!is_string($manifestPath) || !is_file($manifestPath)) {
    fwrite(STDERR, "Evidence manifest file is unavailable.\n");
    exit(2);
}
$manifestPath = str_replace('\\', '/', $manifestPath);
$projectRoot = rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/');
if ($manifestPath === $projectRoot || str_starts_with($manifestPath, $projectRoot . '/')) {
    fwrite(STDERR, "Evidence manifest must remain outside the deployed project directory.\n");
    exit(2);
}
$size = filesize($manifestPath);
if (!is_int($size) || $size < 2 || $size > 512 * 1024) {
    fwrite(STDERR, "Evidence manifest size must be between 2 bytes and 512 KiB.\n");
    exit(2);
}
$permissions = fileperms($manifestPath);
if (is_int($permissions) && ($permissions & 0002) !== 0) {
    fwrite(STDERR, "Evidence manifest must not be world-writable.\n");
    exit(2);
}

require_once $projectRoot . '/bot/runtime/RuntimePrimaryStateSchemaInstaller.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryProjectionOutboxSchemaInstaller.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryEntrypointEvidence.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryRepositoryCommitResolver.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceVerifier.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceGate.php';

try {
    $raw = file_get_contents($manifestPath);
    if (!is_string($raw)) throw new RuntimeException('Evidence manifest is unreadable.');
    $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || array_is_list($manifest)) {
        throw new RuntimeException('Evidence manifest root must be a JSON object.');
    }

    $report = (new RuntimePrimaryStagingEvidenceGate($projectRoot))->verify($manifest);
    fwrite(STDOUT, json_encode(
        $report,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL);
    exit(($report['ok'] ?? false) ? 0 : 1);
} catch (Throwable $error) {
    $message = preg_replace(
        "~/(?:home|var|tmp|srv)/[^\\s'\"]+~",
        '[private-path]',
        trim($error->getMessage())
    ) ?? trim($error->getMessage());
    $message = function_exists('mb_substr')
        ? mb_substr($message, 0, 500)
        : substr($message, 0, 500);
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'report_type' => 'mvp-14.8.6f-staging-evidence-verification',
        'action' => 'evidence_verification_failed',
        'error_class' => get_class($error),
        'error_message' => $message,
        'application_entrypoints_changed' => false,
        'cron_changed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
        'generated_at_utc' => gmdate(DATE_ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
