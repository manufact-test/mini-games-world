<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$path = $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceSource.php';
$source = file_get_contents($path);
if (!is_string($source)) {
    throw new RuntimeException('Staging evidence source code is unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assertTrue(
    str_contains($source, "if (\$environment !== 'staging')")
        && str_contains($source, 'evidence collection is staging-only'),
    'Real evidence source must reject every non-staging environment'
);
$assertTrue(
    str_contains($source, "if (\$this->jsonStorage->driver() !== 'json')")
        && str_contains($source, 'requires the JSON rollback driver'),
    'Real evidence source must require JSON rollback storage'
);
$assertTrue(
    str_contains($source, "if (\$this->database->driver() !== 'mysql')")
        && str_contains($source, 'requires MySQL/MariaDB'),
    'Real evidence source must require MySQL/MariaDB'
);
$assertTrue(
    str_contains($source, 'RuntimePrimaryRepositoryCommitResolver::resolve($this->projectRoot)'),
    'Repository commit evidence must come from the shell-free resolver'
);
$assertTrue(
    str_contains($source, "'version' => PHP_VERSION")
        && str_contains($source, "'version_id' => PHP_VERSION_ID")
        && str_contains($source, "'sapi' => PHP_SAPI"),
    'PHP evidence must come from the running staging CLI'
);
$assertTrue(
    str_contains($source, "fetchValue('SELECT VERSION()')")
        && str_contains($source, "'driver' => \$this->database->driver()"),
    'Database evidence must come from the live staging connection'
);
$assertTrue(
    str_contains($source, 'RuntimePrimaryJsonEvidence::capture($this->jsonStorage)')
        && !str_contains($source, '$this->jsonStorage->transaction('),
    'JSON evidence must use the non-sensitive read-only capture'
);
$assertTrue(
    str_contains($source, '$this->rehearsal->rehearse()')
        && str_contains($source, '$this->concurrencyProbe->run()'),
    'Real evidence source must execute bounded rehearsal and isolated concurrency probes'
);
$assertTrue(
    str_contains($source, 'RuntimePrimaryEntrypointEvidence::inspect($this->projectRoot)'),
    'Entrypoint evidence must be recomputed from current repository sources'
);

fwrite(STDOUT, "RuntimePrimaryStagingEvidenceSourceContractTest passed: {$assertions} assertions.\n");
