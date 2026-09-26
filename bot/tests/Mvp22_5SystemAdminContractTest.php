<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);

$page = $read('app/admin.php');
$css = $read('app/assets/css/admin-shell.css');
$js = $read('app/assets/js/admin-system.js');
$endpoint = $read('bot/admin-system.php');
$system = $read('bot/system/SystemAdminService.php');
$flags = $read('bot/system/RuntimeFeatureFlagAdminService.php');
$notifications = $read('bot/notifications/AdminNotificationEventService.php');
$migration = $read('bot/database/migrations/20260926_0065_create_system_admin_control.php');
$stagingManifest = $read('bot/helpers/staging-e2e-runtime-files.txt');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($page, 'data-system-api="../bot/admin-system.php"')
        && str_contains($page, 'admin-system.js?v=2&mvp22_5=system-status-ru-ux-v2')
        && str_contains($page, 'data-admin-system'),
    'MVP-22.5 must extend the existing secure Web Admin System section.'
);


$assert(
    str_contains($stagingManifest, 'bot/admin-system.php')
        && str_contains($stagingManifest, 'bot/system/SystemAdminService.php')
        && str_contains($stagingManifest, 'bot/system/RuntimeFeatureFlagAdminService.php')
        && str_contains($stagingManifest, 'bot/database/migrations/20260926_0065_create_system_admin_control.php')
        && str_contains($stagingManifest, 'app/assets/js/admin-system.js'),
    'Exact staging fingerprint must cover the complete MVP-22.5 runtime surface.'
);

$assert(
    str_contains($endpoint, 'new AdminNotificationEventService()')
        && str_contains($endpoint, "'request_id'=>'official-rating-seasons-first-activation-v1'")
        && !str_contains($endpoint, 'INSERT INTO mgw_notifications'),
    'First official activation announcement must reuse the canonical notification producer.'
);

foreach (['all','one','segment','platform','tournament'] as $audience) {
    $assert(
        str_contains($notifications, "'{$audience}'"),
        'Existing canonical notification pipeline must retain MVP-22.5 audience: ' . $audience
    );
}

$assert(
    str_contains($flags, 'same private runtime.php')
        && str_contains($flags, "rename(\$temp, \$this->runtimeFile)")
        && !str_contains($migration, 'feature_flag'),
    'Feature flags must keep runtime.php / FeatureFlagService as the behavior owner, not create a DB flag store.'
);

$assert(
    str_contains($system, 'FROM mgw_users u')
        && str_contains($system, 'u.status <> :retired_fixture_status')
        && str_contains($system, "dev_identity.provider = :development_provider")
        && str_contains($system, 'FROM mgw_account_ownership fixture_ownership')
        && str_contains($system, "fixture_ownership.source_type = :legacy_fixture_source_type")
        && str_contains($system, "fixture_ownership.source_type = :runtime_identity_source_type")
        && str_contains($system, "'fixture_legacy_prefix'=>'stg_tour_'")
        && str_contains($system, "'fixture_source_ref_prefix'=>'development:stg_tour_'")
        && str_contains($system, 'READINESS_THRESHOLD = 500')
        && !str_contains($system, 'DELETE FROM mgw_users'),
    '500-user readiness must exclude development and staging tournament fixtures without deleting historical accounts.'
);

$assert(
    str_contains($migration, 'mgw_system_admin_control')
        && str_contains($migration, 'mgw_system_admin_audit')
        && str_contains($system, 'readiness_threshold_reached')
        && str_contains($system, 'staging_acceptance_recorded'),
    'Readiness, staging acceptance and system actions must be durable and auditable.'
);

foreach ([
    'official_season_rehearsal',
    'arena_presentation',
    'profile_presentation',
    'seasonal_badges',
    'top3_frames',
    'yearly_medal',
    'archive_hof',
    'ready_close',
    'missing_assets_recovery',
    'review_recalculation',
    'identity_exclusions',
    'idempotency_retries',
    'launch_notification_localization',
    'staging_e2e_green',
    'manual_acceptance',
] as $key) {
    $assert(
        str_contains($system, "'{$key}'")
            && str_contains($page, 'data-system-check="' . $key . '"'),
        'Mandatory pre-ACTIVE staging checklist item missing: ' . $key
    );
}

$assert(
    str_contains($system, "$environment !== 'production'")
        && str_contains($system, 'Запуск на боевом сервере нельзя выполнить из тестовой или локальной среды.')
        && str_contains($system, 'competition_state=:state')
        && str_contains($system, 'activated_at_utc=:activated_at'),
    'Production PRESEASON -> ACTIVE transition must be explicit and environment-gated.'
);

$assert(
    str_contains($system, "in_array(\$environment, ['staging', 'local'], true)")
        && str_contains($system, 'staging_rehearsal_started')
        && str_contains($system, 'staging_rehearsal_stopped')
        && str_contains($page, 'data-system-start-rehearsal')
        && str_contains($page, 'data-system-stop-rehearsal'),
    'Manual official-season rehearsal must be possible on staging without touching production.'
);

$assert(
    str_contains($page, 'Запуск на боевом сервере')
        && str_contains($page, 'Результаты предсезона задним числом официальными не становятся.')
        && str_contains($page, 'data-system-activation-confirm')
        && str_contains($js, "window.confirm('Запустить официальный рейтинговый сезон на боевом сервере"),
    'Production activation UI must explain consequences in Russian and require explicit confirmation.'
);

$assert(
    !str_contains($page, '</script>\\n  <script')
        && !str_contains($page, '>Staging rehearsal<')
        && !str_contains($page, '>Production activation<')
        && !str_contains($page, 'PRESEASON → ACTIVE')
        && !str_contains($js, 'Runtime: предупреждений')
        && !str_contains($js, 'staging acceptance не зафиксирован')
        && !str_contains($js, 'Checklist не завершён'),
    'Visible System Admin copy must stay Russian and the page must not render a literal newline artifact.'
);

$assert(
    str_contains($css, '[data-admin-system]>.mgw-admin__system-status{margin:14px 14px 12px}')
        && str_contains($css, '.mgw-admin__system-body{')
        && str_contains($css, 'padding:12px 12px 13px;')
        && str_contains($css, '.mgw-admin__system-body .mgw-admin__field textarea{')
        && str_contains($css, 'min-height:82px;'),
    'System Admin must retain card gutters and compact operator textareas.'
);

$assert(
    str_contains($js, "action:'update_flags'")
        && str_contains($js, "action:'accept_staging'")
        && str_contains($js, "action:'activate_official_competition'")
        && str_contains($page, 'data-system-flag-reason')
        && str_contains($page, 'data-system-activation-reason'),
    'System mutations must be explicit, reasoned Admin actions.'
);

fwrite(STDOUT, "Mvp22_5SystemAdminContractTest: {$assertions} assertions passed\n");
