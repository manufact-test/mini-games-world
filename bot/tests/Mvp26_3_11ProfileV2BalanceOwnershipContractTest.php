<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$profile = file_get_contents($root . '/app/assets/js/screens/profile-screen-v110.js');
$shell = file_get_contents($root . '/app/assets/js/main-v110-handoff-shell.js');
$manifest = file_get_contents($root . '/app/runtime/client/version-manifest.php');
if (!is_string($profile) || !is_string($shell) || !is_string($manifest)) throw new RuntimeException('sources unavailable');
$checks = [
  str_contains($profile, 'delete profileRuntimeUser.balance;'),
  str_contains($profile, 'mergeCanonicalMgwUser(state.user, profileRuntimeUser, state.mgwProfile);'),
  !str_contains($profile, 'mergeCanonicalMgwUser(state.user, result.user, state.mgwProfile);'),
  str_contains($shell, 'profile-screen-v110.js?v=1109'),
  preg_match('/main-v110-handoff-shell\\.js\\?v=\\d+/', $manifest) === 1,
  str_contains($manifest, 'mvp26_3_11=profile-v2-wallet-nonowner-v1'),
];
foreach ($checks as $i => $ok) if (!$ok) throw new RuntimeException('assertion ' . ($i + 1) . ' failed');
fwrite(STDOUT, 'Mvp26_3_11ProfileV2BalanceOwnershipContractTest: ' . count($checks) . " assertions passed\n");
