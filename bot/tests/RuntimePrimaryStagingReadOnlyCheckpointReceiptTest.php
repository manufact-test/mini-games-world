<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceWriter.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingReadOnlyCheckpointReceipt.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $messagePart) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($messagePart))) return;
        throw new RuntimeException('Unexpected exception: ' . $error->getMessage());
    }
    throw new RuntimeException('Expected exception was not thrown.');
};

$root = sys_get_temp_dir() . '/mgw-receipt-test-' . bin2hex(random_bytes(8));
$project = $root . '/public_html';
$private = $root . '/_private_mgw';
if (!mkdir($project, 0700, true) || !mkdir($private, 0700, true)) {
    throw new RuntimeException('Could not create receipt test directories.');
}
$reportFile = $private . '/report.json';
$receiptFile = $private . '/receipt.json';
$reportRaw = "{\"safe\":true}\n";
file_put_contents($reportFile, $reportRaw);
chmod($reportFile, 0600);
$now = 1784743200;
$verified = [
    'ok' => true,
    'action' => 'staging_api_read_only_smoke_evidence_verified',
    'repository_commit' => str_repeat('a', 40),
    'database_identity_fingerprint' => str_repeat('b', 64),
    'evidence_fingerprint' => str_repeat('c', 64),
    'report_sha256' => hash('sha256', $reportRaw),
    'state_revision' => 7,
    'state_sha256' => str_repeat('d', 64),
    'worker_tick_count' => 0,
    'state_unchanged' => true,
    'snapshot_unchanged' => true,
    'outbox_unchanged' => true,
    'persistent_config_changed' => false,
    'http_route_added' => false,
    'webhook_allowed' => false,
    'cron_changed' => false,
    'production_changed' => false,
    'sensitive_identifiers_exposed' => false,
];

try {
    $receipt = RuntimePrimaryStagingReadOnlyCheckpointReceipt::build(
        $verified,
        $reportFile,
        $now
    );
    $assertTrue(($receipt['mutating_smoke_authorized'] ?? true) === false, 'Receipt must not authorize mutation');
    $assertTrue(($receipt['worker_tick_count'] ?? -1) === 0, 'Receipt must preserve zero worker ticks');
    $written = (new RuntimePrimaryStagingEvidenceWriter($project))->write($receiptFile, $receipt);
    $assertTrue(($written['ok'] ?? false) === true, 'Receipt must publish atomically');
    $loaded = RuntimePrimaryStagingReadOnlyCheckpointReceipt::verifyFile(
        $receiptFile,
        $project,
        600,
        $now + 30
    );
    $assertTrue(($loaded['repository_commit'] ?? '') === str_repeat('a', 40), 'Receipt commit must remain exact');
    $assertTrue(($loaded['read_only_report_sha256'] ?? '') === hash('sha256', $reportRaw), 'Receipt must bind report SHA');
    $assertTrue(($loaded['receipt_age_seconds'] ?? -1) === 30, 'Receipt age must remain explicit');
    $assertTrue(preg_match('/^[a-f0-9]{64}$/', (string)($loaded['receipt_sha256'] ?? '')) === 1, 'Receipt SHA must be exposed safely');

    $assertThrows(
        static fn() => RuntimePrimaryStagingReadOnlyCheckpointReceipt::verifyFile(
            $receiptFile,
            $project,
            60,
            $now + 61
        ),
        'outside the accepted age window'
    );

    chmod($receiptFile, 0644);
    $assertThrows(
        static fn() => RuntimePrimaryStagingReadOnlyCheckpointReceipt::verifyFile(
            $receiptFile,
            $project,
            600,
            $now + 30
        ),
        'exact mode 0600'
    );
    chmod($receiptFile, 0600);

    file_put_contents($reportFile, "{\"safe\":false}\n");
    chmod($reportFile, 0600);
    $assertThrows(
        static fn() => RuntimePrimaryStagingReadOnlyCheckpointReceipt::verifyFile(
            $receiptFile,
            $project,
            600,
            $now + 30
        ),
        'fingerprint no longer matches'
    );

    file_put_contents($reportFile, $reportRaw);
    chmod($reportFile, 0600);
    $tampered = json_decode((string)file_get_contents($receiptFile), true, 512, JSON_THROW_ON_ERROR);
    $tampered['mutating_smoke_authorized'] = true;
    file_put_contents($receiptFile, json_encode($tampered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    chmod($receiptFile, 0600);
    $assertThrows(
        static fn() => RuntimePrimaryStagingReadOnlyCheckpointReceipt::verifyFile(
            $receiptFile,
            $project,
            600,
            $now + 30
        ),
        'identity is invalid'
    );
} finally {
    @unlink($receiptFile);
    @unlink($reportFile);
    @rmdir($private);
    @rmdir($project);
    @rmdir($root);
}

fwrite(STDOUT, "RuntimePrimaryStagingReadOnlyCheckpointReceiptTest passed: {$assertions} assertions.\n");
