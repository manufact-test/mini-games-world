<?php
declare(strict_types=1);

require __DIR__ . '/RuntimePrimaryStagingBoundedMutatingSmokeTest.php';

$exactAssertions = 0;
$expectExactFailure = static function (callable $callback, string $messagePart) use (&$exactAssertions): void {
    $exactAssertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($messagePart))) return;
        throw new RuntimeException('Unexpected exact-identity exception: ' . $error->getMessage());
    }
    throw new RuntimeException('Expected exact-identity exception was not thrown.');
};

[$smoke, $store, $worker, $mutationAuditor, $verificationAuditor, $sha] = $make();
$expectExactFailure(
    static fn() => $smoke->run($approvalId . "\n", 1, $sha, $challengeSha),
    'approval id is invalid'
);

[$smoke, $store, $worker, $mutationAuditor, $verificationAuditor, $sha] = $make();
$expectExactFailure(
    static fn() => $smoke->run($approvalId, 1, $sha . "\n", $challengeSha),
    'expected staging baseline fingerprint is invalid'
);

[$smoke, $store, $worker, $mutationAuditor, $verificationAuditor, $sha] = $make();
$expectExactFailure(
    static fn() => $smoke->run($approvalId, 1, $sha, $challengeSha . "\n"),
    'challenge fingerprint is invalid'
);

fwrite(
    STDOUT,
    "RuntimePrimaryStagingBoundedMutatingSmokeExactIdentityTest passed: {$exactAssertions} assertions.\n"
);
