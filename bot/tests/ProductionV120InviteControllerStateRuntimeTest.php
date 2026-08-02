<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$script = $root . '/scripts/ci/invite-controller-state-v120-runtime.mjs';
if (!is_file($script)) {
    throw new RuntimeException('Invite controller runtime scenario script is missing.');
}

$command = 'node ' . escapeshellarg($script) . ' 2>&1';
$output = [];
$exitCode = 0;
exec($command, $output, $exitCode);

if ($exitCode !== 0) {
    throw new RuntimeException("Invite controller runtime scenarios failed:\n" . implode("\n", $output));
}

$text = implode("\n", $output);
if (!str_contains($text, 'ProductionV120InviteControllerStateRuntime: assertions passed')) {
    throw new RuntimeException('Invite controller runtime scenarios did not report a passing result.');
}

fwrite(STDOUT, "ProductionV120InviteControllerStateRuntimeTest passed.\n");
