<?php
declare(strict_types=1);

require __DIR__ . '/UnifiedEconomyRuntimeSyncTest.php';

$existingUninitializedRuntime = [
    'users' => [
        '111001' => [
            'id' => '111001',
            'telegram_id' => '111001',
            'first_name' => 'Alpha',
            'username' => 'alpha',
            'photo_url' => '',
            'balance_match' => 0,
            'balance_gold' => 0,
            'status' => 'idle',
            'current_game_id' => null,
            'registered_at' => '2026-08-15T09:30:00Z',
            'last_seen_at' => '2026-08-15T09:30:00Z',
            'weekly_bonus_last' => null,
        ],
    ],
    'games' => [],
];

$rehydratedExisting = $userService->ensureUser($existingUninitializedRuntime, [
    'id' => '111001',
    'first_name' => 'Provider Alpha',
    'username' => 'provider_alpha',
    'mgw_id' => $firstMgwId,
    'mgw_account_ref' => 'legacy:111001',
    'mgw_identity_provider' => 'telegram',
]);

$assertSame(130, (int)$rehydratedExisting['balance'], 'Existing runtime user without canonical balance must hydrate from canonical DB');
$assertSame(130, (int)$existingUninitializedRuntime['users']['111001']['balance'], 'Hydrated canonical balance must replace the default legacy zero before reverse sync');
$assertSame(130, (int)$database->fetchValue("SELECT available_amount FROM mgw_balances WHERE account_ref = 'legacy:111001' AND asset_code = 'mgw_coin'"), 'Hydrating an uninitialized runtime user must not debit canonical DB');

$explicitZeroRuntime = [
    'users' => [
        '111001' => [
            'id' => '111001',
            'telegram_id' => '111001',
            'first_name' => 'Alpha',
            'username' => 'alpha',
            'photo_url' => '',
            'balance' => 0,
            'status' => 'idle',
            'current_game_id' => null,
            'registered_at' => '2026-08-15T09:30:00Z',
            'last_seen_at' => '2026-08-15T09:30:00Z',
            'weekly_bonus_last' => null,
        ],
    ],
    'games' => [],
];

$explicitZero = $userService->ensureUser($explicitZeroRuntime, [
    'id' => '111001',
    'first_name' => 'Provider Alpha',
    'username' => 'provider_alpha',
    'mgw_id' => $firstMgwId,
    'mgw_account_ref' => 'legacy:111001',
    'mgw_identity_provider' => 'telegram',
]);
$assertSame(0, (int)$explicitZero['balance'], 'Explicit initialized runtime zero must stay authoritative and must not be rehydrated');

$zeroSync = $sync->run(['users' => ['111001' => $explicitZeroRuntime['users']['111001']]]);
$assertSame(1, (int)$zeroSync['applied_delta_count'], 'Explicit initialized zero must still be allowed to synchronize as a legitimate debit');
$assertSame(130, (int)$zeroSync['debited_total'], 'Explicit initialized zero must debit the exact remaining canonical amount');
$assertSame(0, (int)$database->fetchValue("SELECT available_amount FROM mgw_balances WHERE account_ref = 'legacy:111001' AND asset_code = 'mgw_coin'"), 'Explicit initialized runtime zero must still converge canonical DB to zero');

fwrite(STDOUT, "UnifiedEconomyUninitializedRuntimeHydrationTest: {$assertions} assertions passed\n");
