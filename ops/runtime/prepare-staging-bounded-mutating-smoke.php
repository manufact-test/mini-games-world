<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$values = [
    'receipt' => '',
    'output' => '',
    'expected_commit' => '',
    'ttl' => '300',
];
$prefixes = [
    '--receipt=' => 'receipt',
    '--output=' => 'output',
    '--expected-commit=' => 'expected_commit',
    '--ttl-seconds=' => 'ttl',
];
$seen = [];
foreach (array_slice($argv ?? [], 1) as $argument) {
    $matchedName = '';
    $matchedPrefix = '';
    foreach ($prefixes as $prefix => $name) {
        if (!str_starts_with($argument, $prefix)) continue;
        $matchedName = $name;
        $matchedPrefix = $prefix;
        break;
    }
    if ($matchedName === '') {
        fwrite(STDERR, "Unknown bounded mutating smoke prepare argument.\n");
        exit(2);
    }
    if (isset($seen[$matchedName])) {
        fwrite(STDERR, "Bounded mutating smoke prepare option may be specified only once: {$matchedPrefix}\n");
        exit(2);
    }
    $seen[$matchedName] = true;
    $values[$matchedName] = substr($argument, strlen($matchedPrefix));
}
foreach (['receipt', 'output', 'expected_commit'] as $required) {
    if (!isset($seen[$required]) || $values[$required] === '') {
        fwrite(STDERR, "Missing bounded mutating smoke prepare option: {$required}.\n");
        exit(2);
    }
}
foreach (['receipt', 'output'] as $pathField) {
    if (!str_starts_with($values[$pathField], '/')
        || str_contains($values[$pathField], '\\')
        || str_ends_with($values[$pathField], '/')) {
        fwrite(STDERR, "Bounded mutating smoke prepare path must be exact absolute Linux: {$pathField}.\n");
        exit(2);
    }
}
if (preg_match('/^[a-f0-9]{40}$/', $values['expected_commit']) !== 1) {
    fwrite(STDERR, "Bounded mutating smoke prepare requires exact lowercase commit.\n");
    exit(2);
}
if ($values['ttl'] === '' || preg_match('/^\d+$/', $values['ttl']) !== 1) {
    fwrite(STDERR, "Bounded mutating smoke prepare TTL must be an integer.\n");
    exit(2);
}
$ttl = (int)$values['ttl'];
if ($ttl < 60 || $ttl > 600) {
    fwrite(STDERR, "Bounded mutating smoke prepare TTL must be between 60 and 600 seconds.\n");
    exit(2);
}
if (PHP_VERSION_ID < 80300 || PHP_VERSION_ID >= 80400) {
    fwrite(STDERR, "Bounded mutating smoke prepare requires PHP 8.3.x.\n");
    exit(2);
}

umask(0077);
set_error_handler(static function (int $severity): never {
    if (!(error_reporting() & $severity)) {
        throw new ErrorException('Suppressed bounded mutating smoke prepare warning.', 0, $severity);
    }
    throw new RuntimeException('Bounded mutating smoke prepare filesystem operation failed.');
});

$exitCode = 1;
$response = [];
try {
    $projectRootRaw = dirname(__DIR__, 2);
    $projectRootReal = realpath($projectRootRaw);
    if (!is_string($projectRootReal) || !is_dir($projectRootReal) || is_link($projectRootRaw)) {
        throw new RuntimeException('Bounded mutating smoke project root is unavailable.');
    }
    $projectRoot = rtrim(str_replace('\\', '/', $projectRootReal), '/');

    require $projectRoot . '/bot/core/bootstrap.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryPrivateConfigGuard.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryRepositoryCommitResolver.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceApproval.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingActivationConfig.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEntrypointSelectorConfig.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingRequestSessionConfig.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingReadOnlyCheckpointReceipt.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingMutatingSmokeApproval.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceWriter.php';

    if ($ttl > RuntimePrimaryStagingMutatingSmokeApproval::MAX_APPROVAL_SECONDS) {
        throw new RuntimeException('Bounded mutating smoke prepare TTL exceeds runtime approval contract.');
    }
    if (($config['environment'] ?? null) !== 'staging') {
        throw new RuntimeException('Bounded mutating smoke prepare is staging-only.');
    }
    RuntimePrimaryPrivateConfigGuard::assertExternal(
        is_string($configFile ?? null) ? $configFile : '',
        $projectRoot
    );
    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        throw new RuntimeException('Bounded mutating smoke prepare requires enabled staging database config.');
    }
    $databaseIdentity = $databaseConfig->identityFingerprint();
    if (preg_match('/^[a-f0-9]{64}$/', $databaseIdentity) !== 1) {
        throw new RuntimeException('Bounded mutating smoke staging database identity is invalid.');
    }
    $currentCommit = RuntimePrimaryRepositoryCommitResolver::resolve($projectRoot);
    if (!hash_equals($values['expected_commit'], $currentCommit)) {
        throw new RuntimeException('Bounded mutating smoke deployed checkout does not match expected commit.');
    }

    $evidenceApproval = RuntimePrimaryStagingEvidenceApproval::fromConfig($config)->safeSummary();
    $activation = RuntimePrimaryStagingActivationConfig::fromApplicationConfig($config)->safeSummary();
    $selector = RuntimePrimaryStagingEntrypointSelectorConfig::fromApplicationConfig($config)->safeSummary();
    $session = RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig($config)->safeSummary();
    foreach ([
        'evidence approval' => $evidenceApproval['enabled'] ?? null,
        'activation' => $activation['enabled'] ?? null,
        'entrypoint selector' => $selector['enabled'] ?? null,
        'request session' => $session['enabled'] ?? null,
    ] as $label => $enabled) {
        if ($enabled !== false) {
            throw new RuntimeException('Persistent staging latch must remain disabled during mutating smoke prepare: ' . $label . '.');
        }
    }
    if (($selector['webhook_allowed'] ?? null) !== false
        || ($session['webhook_allowed'] ?? null) !== false) {
        throw new RuntimeException('Webhook must remain forbidden during bounded mutating smoke prepare.');
    }

    $receipt = RuntimePrimaryStagingReadOnlyCheckpointReceipt::verifyFile(
        $values['receipt'],
        $projectRoot,
        1800
    );
    if (!hash_equals($currentCommit, (string)$receipt['repository_commit'])
        || !hash_equals($databaseIdentity, (string)$receipt['database_identity_fingerprint'])) {
        throw new RuntimeException('Read-only checkpoint receipt does not match current staging identity.');
    }

    $challenge = bin2hex(random_bytes(32));
    $approvalId = bin2hex(random_bytes(32));
    $approvalPayload = [
        'approval_id' => $approvalId,
        'challenge_sha256' => hash('sha256', $challenge),
        'contract_version' => RuntimePrimaryStagingMutatingSmokeApproval::CONTRACT_VERSION,
        'cron_change_allowed' => false,
        'enabled' => true,
        'expected_baseline_state_revision' => (int)$receipt['state_revision'],
        'expected_baseline_state_sha256' => (string)$receipt['state_sha256'],
        'expected_database_identity_fingerprint' => $databaseIdentity,
        'expected_read_only_report_sha256' => (string)$receipt['read_only_report_sha256'],
        'expected_repository_commit' => $currentCommit,
        'expires_at_utc' => gmdate(DATE_ATOM, time() + $ttl),
        'max_state_writes' => 1,
        'production_allowed' => false,
        'rollback_required' => true,
        'webhook_allowed' => false,
    ];
    RuntimePrimaryStagingMutatingSmokeApproval::fromArray($approvalPayload);
    $written = (new RuntimePrimaryStagingEvidenceWriter($projectRoot))->write(
        $values['output'],
        $approvalPayload
    );
    if (($written['ok'] ?? false) !== true
        || ($written['written'] ?? false) !== true
        || ($written['permissions'] ?? '') !== '0600'
        || ($written['publish_mode'] ?? '') !== 'atomic_no_clobber_link') {
        throw new RuntimeException('Bounded mutating smoke approval publication proof is incomplete.');
    }

    $response = [
        'ok' => true,
        'action' => 'staging_bounded_mutating_smoke_prepared',
        'approval_contract_version' => RuntimePrimaryStagingMutatingSmokeApproval::CONTRACT_VERSION,
        'approval_id' => $approvalId,
        'challenge' => $challenge,
        'expires_at_utc' => $approvalPayload['expires_at_utc'],
        'repository_commit' => $currentCommit,
        'database_identity_fingerprint' => $databaseIdentity,
        'read_only_report_sha256' => $receipt['read_only_report_sha256'],
        'baseline_state_revision' => $receipt['state_revision'],
        'baseline_state_sha256' => $receipt['state_sha256'],
        'approval_file_sha256' => $written['file_sha256'],
        'approval_permissions' => '0600',
        'max_state_writes' => 1,
        'rollback_required' => true,
        'mutating_smoke_prepared' => true,
        'mutating_smoke_executed' => false,
        'persistent_config_changed' => false,
        'webhook_allowed' => false,
        'cron_changed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
        'generated_at_utc' => gmdate(DATE_ATOM),
    ];
    $exitCode = 0;
} catch (Throwable $error) {
    $message = preg_replace(
        "~/(?:home|var|tmp|srv|opt)/[^\\s'\"]+~",
        '[private-path]',
        trim($error->getMessage())
    ) ?? trim($error->getMessage());
    $message = function_exists('mb_substr') ? mb_substr($message, 0, 500) : substr($message, 0, 500);
    $response = [
        'ok' => false,
        'action' => 'staging_bounded_mutating_smoke_prepare_blocked_or_failed',
        'error_class' => get_class($error),
        'error_message' => $message,
        'mutating_smoke_prepared' => false,
        'mutating_smoke_executed' => false,
        'persistent_config_changed' => false,
        'webhook_allowed' => false,
        'cron_changed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
        'generated_at_utc' => gmdate(DATE_ATOM),
    ];
} finally {
    restore_error_handler();
}

fwrite(STDOUT, json_encode(
    $response,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
) . PHP_EOL);
exit($exitCode);
