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

$requiredFiles = [
    'docs/MVP25_1_PRODUCT_WIDE_UNFINISHED_AUDIT.md',
    'docs/MVP25_2_HUMAN_FACING_COPY_CLOSURE.md',
    'docs/MVP25_2_MANUAL_ACCEPTANCE.md',
    'docs/MVP25_3_FINAL_UX_CONSISTENCY.md',
    'docs/MVP25_3_MANUAL_ACCEPTANCE.md',
    'docs/MVP25_4_PERFORMANCE_RELIABILITY.md',
    'docs/MVP25_4_MANUAL_ACCEPTANCE.md',
    'docs/MVP25_5_SECURITY_RESILIENCE_AUDIT.md',
    'docs/MVP25_6_MONETIZATION_DISABLED_COMPLETE_PRODUCT.md',
    'bot/tests/Mvp25_1RouteAndDeadResidueContractTest.php',
    'bot/tests/Mvp25_1ProfilePlaceholderCleanupContractTest.php',
    'bot/tests/Mvp25_1V110ManifestNormalizationContractTest.php',
    'bot/tests/Mvp25_2FinalHumanCopyClosureContractTest.php',
    'bot/tests/Mvp25_3FinalUxConsistencyContractTest.php',
    'bot/tests/Mvp25_4PerformanceReliabilityClosureContractTest.php',
    'bot/tests/Mvp25_5SecurityResilienceClosureContractTest.php',
    'bot/tests/Mvp25_6ClosureContractTest.php',
    '.github/workflows/staging-playwright-e2e.yml',
];

foreach ($requiredFiles as $path) {
    $assert(is_file($root . '/' . $path), 'Required MVP-25 proof owner missing: ' . $path);
}

$mvp252 = $read('docs/MVP25_2_HUMAN_FACING_COPY_CLOSURE.md');
$mvp253 = $read('docs/MVP25_3_MANUAL_ACCEPTANCE.md');
$mvp254 = $read('docs/MVP25_4_MANUAL_ACCEPTANCE.md');
$mvp255 = $read('docs/MVP25_5_SECURITY_RESILIENCE_AUDIT.md');
$mvp256 = $read('docs/MVP25_6_MONETIZATION_DISABLED_COMPLETE_PRODUCT.md');
$launch = $read('bot/helpers/WebAppLaunchUrl.php');
$store = $read('app/assets/js/screens/store-screen.js');
$storeService = $read('bot/catalog/CosmeticStoreService.php');
$profile = $read('app/assets/js/screens/profile-screen-v110.js');
$home = $read('app/assets/js/screens/home-screen.js');
$handoff = $read('app/assets/js/main-v110-handoff-shell.js');
$invites = $read('app/assets/js/games/game-invites-v110.js');
$inviteEndpoint = $read('bot/invites.php');
$uxCss = $read('app/assets/css/mvp25-3-ux-consistency-v1.css');
$manifest = require $root . '/app/runtime/client/version-manifest.php';

$assert(
    str_contains($mvp252, 'CLOSED / MANUALLY ACCEPTED / FROZEN'),
    'MVP-25.2 must remain manually accepted and frozen before RC.'
);
$assert(
    str_contains($mvp253, 'CLOSED / MANUALLY ACCEPTED / FROZEN'),
    'MVP-25.3 must remain manually accepted and frozen before RC.'
);
$assert(
    str_contains($mvp254, 'CLOSED / MANUALLY ACCEPTED WITH KNOWN RESIDUAL / FROZEN'),
    'MVP-25.4 accepted residual must remain recorded and frozen before RC.'
);
$assert(
    str_contains($mvp255, '**Status:** CLOSED'),
    'MVP-25.5 security/resilience must remain closed before RC.'
);
$assert(
    str_contains($mvp256, '**Status:** CLOSED / AUTOMATED + STAGING ACCEPTED / FROZEN'),
    'MVP-25.6 monetization-disabled product mode must remain closed before RC.'
);

$assert(
    str_contains($launch, "private const ENTRY_PATH = '/app/v110.php"),
    'Telegram Product RC must preserve v110 as the canonical Mini App launch owner.'
);
$assert(
    str_contains($storeService, 'private const EXTERNAL_BILLING_AVAILABLE = false;')
        && str_contains($store, "{ id:'coins', label:'Коины', available:false }")
        && !str_contains($store, 'Скоро'),
    'Telegram Product RC must preserve the completed monetization-disabled Store state.'
);

$assert(
    str_contains($profile, "item.owned === true && item.item_type === 'game'")
        && str_contains($profile, 'data-profile-game-tab')
        && str_contains($profile, 'state.profileInventory.equipped')
        && str_contains($profile, 'api.cosmeticStoreEquip(itemId)')
        && str_contains($profile, 'api.cosmeticStoreUnequip(slot)')
        && !str_contains($profile, 'cosmeticStorePurchase('),
    'Telegram Product RC must preserve Profile as a read/equip owner for authoritative owned game cosmetics without becoming a purchase owner.'
);

$imports = is_array($manifest['imports'] ?? null) ? $manifest['imports'] : [];
$assets = is_array($manifest['assets'] ?? null) ? $manifest['assets'] : [];
$assert(
    isset($imports['@mgw/main'])
        && is_string($imports['@mgw/main'])
        && str_contains($imports['@mgw/main'], 'main-v110-reconnect-v174.js'),
    'Telegram Product RC must preserve the accepted current v110 main owner.'
);
$profileTarget = is_string($imports['./assets/js/screens/profile-screen-v110.js?v=1108'] ?? null)
    ? $imports['./assets/js/screens/profile-screen-v110.js?v=1108']
    : '';
$apiTarget = is_string($imports['./assets/js/api/client.js?v=47'] ?? null)
    ? $imports['./assets/js/api/client.js?v=47']
    : '';
$assert(
    str_contains($profileTarget, 'mvp19_3_2=game-cosmetics')
        && str_contains($profileTarget, 'mvp19_6=checkers-profile-manual-repair-v3')
        && str_contains($profileTarget, 'profile_card_runtime=checkers-hard-square-v1')
        && str_contains($apiTarget, 'profile_inventory=store-sync-v1'),
    'Telegram Product RC must preserve the current Store/Profile game-cosmetics and inventory-sync owners regardless of later cache revisions.'
);

$inviteTarget = is_string($imports['./assets/js/games/game-invites-v110.js?v=1142&zone=unified&rematch=optimistic&terminal=self-silent'] ?? null)
    ? $imports['./assets/js/games/game-invites-v110.js?v=1142&zone=unified&rematch=optimistic&terminal=self-silent']
    : '';
$assert(
    str_contains($inviteTarget, 'social=1')
        && str_contains($inviteTarget, 'share=telegram-native')
        && str_contains($inviteTarget, 'waiting=draft-safe')
        && str_contains($inviteTarget, 'mvp21_6=tournament-rematch-exclusion-v1')
        && str_contains($inviteTarget, 'mvp25_2=network-human-error-v1')
        && str_contains($inviteTarget, 'mvp24=room-copy-removed-v1'),
    'Telegram Product RC must preserve the current draft-safe Telegram-native invite owner, tournament rematch exclusion, human network errors and retired room copy regardless of cache revision.'
);
$assert(
    str_contains($invites, "tg.onEvent('shareMessageSent', () => settleNativeShare(true));")
        && str_contains($invites, "inviteRequest('confirm_shared', { token:String(attempt.invite?.token || '') });")
        && str_contains($invites, 'restoreWarmShareDraft(attempt);')
        && str_contains($invites, 'void discardDraft(attempt.invite);')
        && str_contains($invites, 'const SHARE_CALLBACK_TIMEOUT_MS = 12000;')
        && str_contains($invites, 'showOwnerWaiting(currentInvite);'),
    'Telegram Product RC must preserve the accepted native-share draft lifecycle and owner-waiting recovery semantics.'
);
$assert(
    str_contains($inviteEndpoint, 'function mgw_invite_share_url(array $config, string $token): string')
        && str_contains($inviteEndpoint, "return $baseUrl . '/invite/' . rawurlencode($normalizedToken);")
        && str_contains($inviteEndpoint, 'function mgw_invite_telegram_open_url(array $config, string $token): string')
        && str_contains($inviteEndpoint, "'?start=invite_' . rawurlencode($normalizedToken)")
        && !str_contains($inviteEndpoint, 'leaveSearch('),
    'Telegram Product RC must preserve separate public/Telegram invite ingress and must not cancel matchmaking implicitly.'
);

$mainCss = is_string($assets['main_css'] ?? null) ? $assets['main_css'] : '';
$assert(
    str_contains($mainCss, 'mvp25_3=ux-consistency-v2')
        && str_contains($mainCss, 'rules=human-guide-top-spacing-v1')
        && str_contains($mainCss, 'mvp25_4=profile-mobile-compositor-v1'),
    'Telegram Product RC must preserve accepted MVP-25.3 UX/rules semantics inside the current MVP-25.4 main CSS identity.'
);

$homeTarget = is_string($imports['./assets/js/screens/home-screen.js?v=74'] ?? null)
    ? $imports['./assets/js/screens/home-screen.js?v=74']
    : '';
$handoffTarget = is_string($imports['./assets/js/main-v110-handoff-shell.js?v=1137&ux=1&sk=3&icons=c1efd5af&render=5'] ?? null)
    ? $imports['./assets/js/main-v110-handoff-shell.js?v=1137&ux=1&sk=3&icons=c1efd5af&render=5']
    : '';

$assert(
    str_contains($homeTarget, 'mvp25_3=human-rules-guide-v1')
        && str_contains($handoffTarget, 'mvp25_3=match-economy-guide-v1')
        && str_contains($handoffTarget, 'mvp25_4=profile-preloader-unblock-v1')
        && str_contains($handoffTarget, 'profile_tap=post-paint-v1'),
    'Telegram Product RC must preserve accepted MVP-25.3 rules/economy semantics and MVP-25.4 startup/Profile owners regardless of later cache versions.'
);
$assert(
    str_contains($home, 'Как работают обычные матчи')
        && str_contains($home, 'Пока идёт поиск, коины не списываются.')
        && str_contains($home, 'amount(entry)')
        && str_contains($home, 'amount(winnerReward)')
        && str_contains($home, 'amount(commission)')
        && str_contains($home, 'amount(drawRefund)'),
    'Telegram Product RC must preserve the accepted human rules guide and live economy values.'
);
$assert(
    str_contains($handoff, 'matchWinnerReward')
        && str_contains($handoff, 'matchSystemSink')
        && str_contains($handoff, 'matchDrawRefund'),
    'Telegram Product RC must preserve authoritative public match economy bootstrap fields.'
);
$assert(
    str_contains($uxCss, '.rules-guide-section')
        && str_contains($uxCss, '.rules-guide-economy')
        && str_contains($uxCss, 'padding-top:8px'),
    'Telegram Product RC must preserve the product-owner accepted rules-guide styling and top inset.'
);

$assert(
    is_file($root . '/docs/MVP25_7_TELEGRAM_PRODUCT_RELEASE_CANDIDATE.md')
        && is_file($root . '/docs/MVP25_7_MANUAL_ACCEPTANCE.md'),
    'MVP-25.7 RC and manual acceptance documents must exist.'
);

fwrite(STDOUT, "Mvp25_7TelegramProductRcContractTest: {$assertions} assertions passed\n");
