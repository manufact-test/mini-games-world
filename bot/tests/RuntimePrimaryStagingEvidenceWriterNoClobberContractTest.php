<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$source = file_get_contents($projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceWriter.php');
if (!is_string($source)) {
    throw new RuntimeException('Staging evidence writer source is unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assertTrue(
    str_contains($source, "fopen(\$temporary, 'x+b')"),
    'Temporary evidence file must be created exclusively'
);
$assertTrue(
    str_contains($source, "if (!function_exists('link'))")
        && str_contains($source, 'if (!link($temporary, $outputPath))'),
    'Final evidence publication must require atomic no-clobber hard linking'
);
$assertTrue(
    !str_contains($source, 'rename($temporary, $outputPath)'),
    'Evidence writer must not use overwrite-capable rename publication'
);
$assertTrue(
    str_contains($source, "'publish_mode' => 'atomic_no_clobber_link'"),
    'Writer report must expose the no-clobber publication mode'
);
$assertTrue(
    str_contains($source, 'if (!unlink($temporary))')
        && str_contains($source, '@unlink($outputPath);'),
    'Writer must remove the temporary link and roll back final output on cleanup failure'
);

fwrite(STDOUT, "RuntimePrimaryStagingEvidenceWriterNoClobberContractTest passed: {$assertions} assertions.\n");
