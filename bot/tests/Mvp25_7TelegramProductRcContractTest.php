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
$home = $read('app/assets/js/screens/home-screen.js');
$handoff = $read('app/assets/js/main-v110-handoff-shell.js');
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

$imports = is_array($manifest['imports'] ?? null) ? $manifest['imports'] : [];
$assets = is_array($manifest['assets'] ?? null) ? $manifest['assets'] : [];
$assert(
    isset($imports['@mgw/main'])
        && is_string($imports['@mgw/main'])
        && str_contains($imports['@mgw/main'], 'main-v110-reconnect-v174.js'),
    'Telegram Product RC must preserve the accepted current v110 main owner.'
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
        && str_contains($handoffTarget, 'mvp25_3=match-economy-guide-v1'),
    'Telegram Product RC must preserve the accepted MVP-25.3 Home rules and bootstrap economy owners regardless of later cache versions.'
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
