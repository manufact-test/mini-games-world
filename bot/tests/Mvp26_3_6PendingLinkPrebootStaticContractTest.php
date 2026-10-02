<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$ui = file_get_contents($root . '/app/assets/js/profile/mgw-account-link-ui.js');
$main = file_get_contents($root . '/app/assets/js/main-v110-handoff-shell.js');
$profile = file_get_contents($root . '/app/assets/js/screens/profile-screen-v110.js');
$manifest = file_get_contents($root . '/app/runtime/client/version-manifest.php');
$locale = json_decode((string)file_get_contents($root . '/app/locales/ru.json'), true, 512, JSON_THROW_ON_ERROR);

foreach (['ui'=>$ui,'main'=>$main,'profile'=>$profile,'manifest'=>$manifest] as $name=>$content) {
    if (!is_string($content) || $content === '') {
        throw new RuntimeException('Missing client source: ' . $name);
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($ui, 'export async function settlePendingAccountLinkBeforeBoot()'),
    'Account-link UI must expose the pre-bootstrap pending-link owner.'
);
$assert(
    str_contains($ui, "for (const delay of [0, 180, 360, 720])"),
    'Cold-start pending-link settlement must use a bounded confirmation watch.'
);
$assert(
    str_contains($ui, "for (const delay of [0, 220, 420, 780, 1200])"),
    'Foreground return must use a bounded confirmation watch.'
);
$assert(
    str_contains($ui, "renderLoading(accountLinkText('loading.checking_telegram'))"),
    'Foreground resume must keep account-link progress visibly owned through canonical localization while confirmation settles.'
);
$assert(
    ($locale['account_link']['loading']['checking_telegram'] ?? null) === 'Проверяем подтверждение в Telegram…',
    'Accepted RU foreground confirmation copy must remain unchanged in the canonical locale.'
);
$assert(
    !str_contains(
        substr(
            $ui,
            strpos($ui, 'export async function settlePendingAccountLinkBeforeBoot()'),
            strpos($ui, 'export async function openAccountLinkSheet()') - strpos($ui, 'export async function settlePendingAccountLinkBeforeBoot()')
        ),
        'isCurrentAndroidProvider()'
    ),
    'Pre-bootstrap settlement must not depend on state.user/profile hydration.'
);

$import = "import { settlePendingAccountLinkBeforeBoot } from './profile/mgw-account-link-ui.js?v=3';";
$await = 'await settlePendingAccountLinkBeforeBoot();';
$profileRead = 'const profilePromise = api.mgwProfile();';
$bootstrap = 'const result = await api.bootstrap();';
$assert(str_contains($main, $import), 'Active v110 shell must import one v3 account-link owner.');
$assert(str_contains($main, $await), 'Active v110 shell must await pending-link settlement.');
$assert(
    strpos($main, $await) < strpos($main, $profileRead)
    && strpos($main, $await) < strpos($main, $bootstrap),
    'Pending account link must settle before profile/bootstrap account reads begin.'
);
$assert(
    str_contains($profile, "../profile/mgw-account-link-ui.js?v=3"),
    'Profile must reuse the exact same v3 account-link module identity.'
);
$assert(
    !str_contains($profile, "../profile/mgw-account-link-ui.js?v=2"),
    'Profile must not instantiate the stale v2 account-link module beside v3.'
);

$assert(
    preg_match('/main-v110-handoff-shell\\.js\\?v=\\d+[^\\n]*mvp26_3_6=pending-link-preboot-v1/', $manifest) === 1,
    'Active v110 shell cache identity must include the pending-link corrective.'
);
$assert(
    str_contains($manifest, "'./assets/js/profile/mgw-account-link-ui.js?v=3' => './assets/js/profile/mgw-account-link-ui.js?v=4&mvp27_1=localized-v1'")
    && str_contains($manifest, "'./assets/js/screens/profile-screen-v110.js?v=1109' => './assets/js/screens/profile-screen-v110.js?v=1142"),
    'Active Profile graph must preserve one accepted v3 account-link specifier and resolve it to the localized canonical owner.'
);
$assert(
    !str_contains($ui, 'LedgerWriteService')
    && !str_contains($ui, 'mgw_coin'),
    'Client hydration corrective must not synthesize or rewrite account balance ownership.'
);

fwrite(STDOUT, "Mvp26_3_6PendingLinkPrebootStaticContractTest: {$assertions} assertions passed\n");
