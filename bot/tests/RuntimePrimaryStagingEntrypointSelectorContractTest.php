<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$factory = file_get_contents($projectRoot . '/bot/storage/StorageFactory.php');
$selector = file_get_contents($projectRoot . '/bot/runtime/RuntimePrimaryStagingEntrypointStorageSelector.php');
$context = file_get_contents($projectRoot . '/bot/runtime/RuntimePrimaryEntrypointStorageContext.php');
$v3 = file_get_contents($projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceV3Verifier.php');
$collector = file_get_contents($projectRoot . '/bot/runtime/RuntimePrimaryStagingSelectorEvidenceCollector.php');
if (!is_string($factory) || !is_string($selector) || !is_string($context)
    || !is_string($v3) || !is_string($collector)) {
    throw new RuntimeException('Staging entrypoint selector sources are unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assertTrue(
    str_contains($factory, 'installGuardedEntrypointContextIfEligible()')
        && str_contains($factory, "'api.php' => 'api'")
        && str_contains($factory, "'webhook.php' => 'webhook'")
        && str_contains($factory, "default => ''"),
    'StorageFactory must attempt the selector only for API or webhook requests'
);
$assertTrue(
    str_contains($factory, 'RuntimePrimaryStagingEntrypointBootstrap.php')
        && str_contains($factory, 'RuntimePrimaryStagingEntrypointStorageSelector(')
        && str_contains($factory, '->installIfEnabled()'),
    'StorageFactory must load and invoke the guarded selector lazily'
);
$assertTrue(
    str_contains($factory, 'RuntimePrimaryEntrypointStorageContext::installed()')
        && str_contains($factory, 'RuntimePrimaryEntrypointStorageContext::storage()')
        && str_contains($factory, 'return new JsonStorageAdapter($dataDir);'),
    'StorageFactory must reuse the request-local DB context or preserve JSON fallback'
);
$assertTrue(
    str_contains($selector, "if (\$environment !== 'staging')")
        && str_contains($selector, 'cannot be enabled outside staging')
        && str_contains($selector, 'enabledFor($this->entrypoint)'),
    'Selector must remain staging-only and explicitly allowlisted'
);
$assertTrue(
    str_contains($selector, 'RuntimePrimaryStagingEvidenceV3Gate(')
        && str_contains($selector, 'Real staging entrypoint routing requires selector-aware evidence v3.')
        && str_contains($selector, 'expectedEvidenceFingerprint()'),
    'Selector must independently verify exact evidence v3 and approval fingerprint'
);
$assertTrue(
    str_contains($selector, 'RuntimePrimaryStagingStorageResolver(')
        && str_contains($selector, 'RuntimePrimaryEntrypointStorageContext::install(')
        && str_contains($selector, "'selector_evidence_fingerprint'")
        && str_contains($selector, "'selector_contract_version'"),
    'Selector must pass guarded resolution and selector evidence into the request context'
);
$assertTrue(
    str_contains($context, 'accepts only guarded DB-primary storage')
        && str_contains($context, 'requires selector-aware evidence v3')
        && str_contains($context, 'requires the exact selector contract version')
        && str_contains($context, 'valid selector evidence fingerprint'),
    'Request context must independently require DB storage, evidence v3 and exact selector contract'
);
$assertTrue(
    str_contains($v3, "public const MANIFEST_VERSION = 'v3-staging-db-primary-selector-evidence'")
        && str_contains($v3, 'RuntimePrimaryStagingSelectorEvidence::inspect(')
        && str_contains($v3, 'does not match the current repository sources'),
    'Evidence v3 must recompute selector source evidence from the current checkout'
);
$assertTrue(
    str_contains($collector, 'RuntimePrimaryStagingEvidenceCollector(')
        && str_contains($collector, 'RuntimePrimaryStagingEvidenceV3Verifier::MANIFEST_VERSION')
        && str_contains($collector, "\$manifest['selector_evidence'] = \$selectorEvidence")
        && str_contains($collector, 'RuntimePrimaryStagingEvidenceV3Gate('),
    'Selector collector must extend verified v2 evidence and pass the v3 gate'
);
$assertTrue(
    !str_contains($selector, 'bot/api.php')
        && !str_contains($selector, 'WebhookHandler.php')
        && !str_contains($selector, 'crontab')
        && !str_contains($selector, 'production-cutover.php')
        && !str_contains($context, 'crontab')
        && !str_contains($context, 'production-cutover.php'),
    'Selector implementation must not rewrite entrypoints, Cron or production cutover'
);

fwrite(STDOUT, "RuntimePrimaryStagingEntrypointSelectorContractTest passed: {$assertions} assertions.\n");
