<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Missing contract source: ' . $path);
    return $content;
};

$page = $read('app/admin.php');
$css = $read('app/assets/css/admin-shell.css');
$shell = $read('app/assets/js/admin-shell.js');
$client = $read('app/assets/js/admin-analytics.js');
$endpoint = $read('bot/admin-analytics.php');
$service = $read('bot/analytics/ProductEconomyAnalyticsService.php');
$scope = $read('bot/analytics/RealAccountScope.php');
$system = $read('bot/system/SystemAdminService.php');
$queue = $read('bot/services/MatchmakingQueue.php');
$economy = $read('bot/ledger/RuntimeEconomyRepository.php');
$manifest = $read('bot/helpers/staging-e2e-runtime-files.txt');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($page, 'data-admin-nav-target="analytics">Аналитика</button>')
        && str_contains($page, 'data-admin-section="analytics" data-admin-analytics')
        && str_contains($page, 'data-analytics-api="../bot/admin-analytics.php"')
        && str_contains($page, 'admin-analytics.js?v=1&mvp22_6=product-economy-analytics-v1')
        && str_contains($shell, "analytics:['Аналитика'"),
    'MVP-22.6 must be a first-class Russian Web Admin section.'
);

foreach ([
    'Пользователи',
    'Возврат пользователей',
    'Игры',
    'Подбор соперников',
    'Покупки',
    'Реклама',
    'Турниры',
    'Коины: источники и сжигание',
    'Согласованность экономики',
    'Что именно умеет и не умеет эта статистика',
] as $copy) {
    $assert(str_contains($page, $copy), 'Analytics UI missing product area: ' . $copy);
}

$assert(
    str_contains($endpoint, 'AdminWebAuth::authorize')
        && str_contains($endpoint, "(string)($payload['action'] ?? '') !== 'snapshot'")
        && !str_contains($endpoint, 'INSERT INTO')
        && !str_contains($endpoint, 'UPDATE ')
        && !str_contains($endpoint, 'DELETE FROM'),
    'Analytics endpoint must be authenticated and read-only.'
);

$assert(
    str_contains($endpoint, 'new MatchmakingQueue()')
        && str_contains($endpoint, 'RuntimeEconomyRepository')
        && str_contains($endpoint, 'auditParity($data)')
        && str_contains($service, 'matchmaking_skill_wait_ms_sum')
        && str_contains($queue, 'matchmaking_skill_wait_ms_sum'),
    'Matchmaking and reconciliation analytics must consume existing canonical owners.'
);

$assert(
    str_contains($service, "RealAccountScope::userPredicate('u', 'users')")
        && str_contains($system, "RealAccountScope::userPredicate('u', 'readiness')")
        && str_contains($scope, 'staging_fixture_retired')
        && str_contains($scope, 'staging_fixture_repair')
        && str_contains($scope, 'development:stg_tour_'),
    'System readiness and analytics must share one real-account exclusion owner.'
);

$assert(
    str_contains($service, 'mgw_matches')
        && str_contains($service, 'mgw_match_players')
        && str_contains($service, 'mgw_cosmetic_purchases')
        && str_contains($service, 'mgw_tournament_registrations')
        && str_contains($service, 'mgw_tournament_results')
        && str_contains($service, 'mgw_ledger_entries')
        && str_contains($service, 'mgw_balances'),
    'Analytics must read established durable product/economy tables rather than create parallel history.'
);

$assert(
    str_contains($service, "'available' => false")
        && str_contains($service, "'status' => 'not_collected'")
        && str_contains($service, "'history_reconstructable' => false")
        && str_contains($client, 'Телеметрия пока не собирается'),
    'Missing ad telemetry must be explicit and must not be represented as fabricated zero history.'
);

$assert(
    str_contains($service, "'method' => 'ever_active_after_threshold'")
        && str_contains($service, 'не классический D1/D7 retention')
        && str_contains($page, 'По доступной истории'),
    'Retention must disclose the available-history approximation instead of claiming exact D1/D7 events.'
);

$assert(
    str_contains($service, "$deltaExpression = '(l.available_delta + l.reserved_delta)'")
        && str_contains($service, "'delta_definition' => 'available_delta_plus_reserved_delta'"),
    'Coin sources/sinks must use total wealth delta so reserve transfers are not falsely counted as mint/burn.'
);

$assert(
    str_contains($service, 'blocking_reasons')
        && str_contains($service, 'integrity_failure_count')
        && str_contains($service, 'active_reservation_count')
        && str_contains($client, 'Аналитика ничего не исправляет автоматически.'),
    'Reconciliation warnings must remain read-only and visible.'
);

foreach ([
    'bot/admin-analytics.php',
    'bot/analytics/RealAccountScope.php',
    'bot/analytics/ProductEconomyAnalyticsService.php',
    'app/assets/js/admin-analytics.js',
] as $path) {
    $assert(str_contains($manifest, $path), 'Exact staging fingerprint missing analytics runtime file: ' . $path);
}

$assert(
    str_contains($css, '/* MVP-22.6 · product/economy analytics */')
        && str_contains($css, '.mgw-admin__analytics-kpis')
        && str_contains($css, '@media(max-width:760px)'),
    'Analytics workspace must have isolated responsive Admin styling.'
);

fwrite(STDOUT, "Mvp22_6ProductEconomyAnalyticsContractTest: {$assertions} assertions passed\n");
