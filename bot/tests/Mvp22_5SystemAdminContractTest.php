<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);

$page = $read('app/admin.php');
$js = $read('app/assets/js/admin-system.js');
$endpoint = $read('bot/admin-system.php');
$system = $read('bot/system/SystemAdminService.php');
$flags = $read('bot/system/RuntimeFeatureFlagAdminService.php');
$notifications = $read('bot/notifications/AdminNotificationEventService.php');
$migration = $read('bot/database/migrations/20260926_0065_create_system_admin_control.php');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($page, 'data-system-api="../bot/admin-system.php"')
        && str_contains($page, 'admin-system.js?v=1&mvp22_5=system-status-v1')
        && str_contains($page, 'data-admin-system'),
    'MVP-22.5 must extend the existing secure Web Admin System section.'
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
        && str_contains($system, "dev_identity.provider = :development_provider")
        && str_contains($system, "['development_provider'=>'development']")
        && str_contains($system, 'READINESS_THRESHOLD = 500'),
    '500-user readiness must read canonical MGW accounts and exclude development identities without a parallel counter.'
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
        && str_contains($system, 'Production activation нельзя выполнить из staging/local.')
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
    str_contains($page, 'PRESEASON → ACTIVE')
        && str_contains($page, 'PRESEASON не конвертируется задним числом')
        && str_contains($page, 'data-system-activation-confirm')
        && str_contains($js, "window.confirm('Запустить PRESEASON → ACTIVE в production"),
    'Production activation UI must explain consequences and require explicit confirmation.'
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
