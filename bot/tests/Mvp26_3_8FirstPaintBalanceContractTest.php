<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;

$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};

$profile = $read('bot/profile.php');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$manifest = $read('app/runtime/client/version-manifest.php');

$assert(
    str_contains($profile, 'function mgw_profile_ensure_runtime_balance')
        && str_contains($profile, 'StorageFactory::create($config)')
        && str_contains($profile, '$users = new UserService($config, $database);')
        && str_contains($profile, '$storage->transaction(')
        && str_contains($profile, '$runtimeUser = $users->ensureUser($data, $authenticatedUser);'),
    'Early profile hydration must converge the primary runtime user through the canonical UserService before first paint.'
);
$assert(
    str_contains($profile, "'runtime' => [")
        && str_contains($profile, "'balance' => \$liveRuntimeBalance")
        && str_contains($profile, "'source' => 'primary_runtime_ensured'"),
    'Profile response must expose the ensured primary runtime balance explicitly without moving profile identity ownership.'
);
$assert(
    str_contains($profile, '$profile = (new MgwProfileService($database))->publicProfile($mgwId);')
        && str_contains($profile, '$inventory = (new ProductInventoryService($database))->snapshot($mgwId);'),
    'Canonical profile identity and inventory must remain DB-owned.'
);
$assert(
    !str_contains($profile, 'LedgerWriteService')
        && !str_contains($profile, 'available_delta')
        && !str_contains($profile, 'mgw_balances'),
    'First-paint convergence must not implement a competing balance mutation or direct ledger copy in profile.php.'
);

$assert(
    str_contains($shell, 'const profilePromise = api.mgwProfile();')
        && str_contains($shell, 'const result = await api.bootstrap();')
        && str_contains($shell, 'const [mgwProfileResult, prestigeResult] = await Promise.all([profilePromise, prestigePromise]);'),
    'The balance fix must reuse the already-awaited parallel profile request instead of adding a startup request.'
);
$assert(
    str_contains($shell, 'const liveFirstPaintBalance = mgwProfileResult?.runtime?.balance;')
        && str_contains($shell, 'Number.isSafeInteger(liveFirstPaintBalance)')
        && str_contains($shell, 'liveFirstPaintBalance >= 0')
        && !str_contains($shell, 'Number(mgwProfileResult?.runtime?.balance)'),
    'Client must accept only an explicit safe non-negative integer and must not coerce null into zero.'
);
$assert(
    str_contains($shell, '? { ...(result.user || {}), balance:liveFirstPaintBalance }')
        && str_contains($shell, 'state.user = applyCanonicalMgwProfile(firstPaintRuntimeUser, state.mgwProfile);'),
    'First visible user must combine primary runtime balance with canonical MGW identity before rendering.'
);

$balanceHydration = strpos($shell, 'const liveFirstPaintBalance = mgwProfileResult?.runtime?.balance;');
$render = strpos($shell, 'renderBalances(state.user);');
$preloaderRelease = strrpos($shell, 'hidePreloader();');
$assert(
    $balanceHydration !== false
        && $render !== false
        && $preloaderRelease !== false
        && $balanceHydration < $render
        && $render < $preloaderRelease,
    'Primary runtime balance must be hydrated before the first visible balance render and before preloader release.'
);
$assert(
    str_contains($manifest, 'main-v110-handoff-shell.js?v=1163')
        && str_contains($manifest, 'mvp26_3_8=first-paint-live-balance-v1'),
    'Version manifest must publish the first-paint live-balance client cache identity.'
);

fwrite(STDOUT, "Mvp26_3_8FirstPaintBalanceContractTest: {$assertions} assertions passed\n");
