<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$read = static function (string $relative) use ($root): string {
    $content = file_get_contents($root . '/' . $relative);
    if (!is_string($content)) throw new RuntimeException('Missing contract source: ' . $relative);
    return $content;
};

$migration = $read('bot/database/migrations/20260920_0045_create_quarterly_season_lifecycle.php');
$calendar = $read('bot/ratings/SeasonCalendar.php');
$resolver = $read('bot/ratings/SeasonAssignmentResolver.php');
$lifecycle = $read('bot/ratings/SeasonLifecycleService.php');
$rating = $read('bot/ratings/PerGameRatingService.php');
$bridge = $read('bot/ratings/PerGameRatingRuntimeBridge.php');
$bootstrap = $read('bot/core/bootstrap.php');

$assertTrue(str_contains($migration, 'mgw_rating_seasons'), 'MVP-20.4 must persist official season rows.');
$assertTrue(str_contains($migration, 'mgw_season_reward_packages'), 'MVP-20.4 must persist next-season reward readiness.');
$assertTrue(str_contains($migration, 'mgw_season_preparation_reminders'), 'MVP-20.4 must persist T-21/T-14/T-7 due semantics.');
$assertTrue(str_contains($migration, 'mgw_season_boundary_operations'), 'MVP-20.4 must persist one idempotent boundary operation per ending season.');
$assertTrue(!str_contains($migration, "competition_state = 'active'"), 'MVP-20.4 migration must never auto-activate official competition.');
$assertTrue(!str_contains($migration, "UPDATE mgw_rating_control"), 'Schema migration must not mutate PRESEASON into ACTIVE.');

$assertTrue(str_contains($calendar, "TIMEZONE = 'Europe/Moscow'"), 'Official quarter calendar must use Moscow boundaries.');
$assertTrue(str_contains($calendar, "sprintf('%04d-q%d'"), 'Official season ids must be deterministic year-quarter ids.');
$assertTrue(str_contains($calendar, '[21, 14, 7]'), 'Calendar must own exactly the canonical T-21/T-14/T-7 checkpoints.');

$assertTrue(str_contains($lifecycle, "SEASON_FINALIZING = 'finalizing'"), 'Lifecycle must expose durable FINALIZING.');
$assertTrue(str_contains($lifecycle, "OP_ASSETS_REQUIRED = 'assets_required'"), 'Lifecycle must expose durable ASSETS_REQUIRED.');
$assertTrue(str_contains($lifecycle, "SEASON_CLOSED = 'closed'"), 'Lifecycle must have an explicit closed state.');
$assertTrue(str_contains($lifecycle, "competition_state'] !== PerGameRatingService::STATE_ACTIVE"), 'OFF/PRESEASON must not run official season operations.');
$assertTrue(str_contains($lifecycle, 'updateRewardReadiness'), 'Lifecycle must expose one readiness update seam for later reward/admin owners.');
$assertTrue(str_contains($lifecycle, 'seasonal_awards_state'), 'Readiness checklist must reserve seasonal-award validation.');
$assertTrue(str_contains($lifecycle, 'top3_frames_state'), 'Readiness checklist must reserve temporary top-3 frame validation.');
$assertTrue(str_contains($lifecycle, 'yearly_medal_state'), 'Readiness checklist must reserve yearly medal/fragment validation.');
$assertTrue(str_contains($lifecycle, 'localization_state'), 'Readiness checklist must reserve localization validation.');
$assertTrue(str_contains($lifecycle, 'preview_validation_state'), 'Readiness checklist must reserve asset preview/validation.');
$assertTrue(str_contains($lifecycle, 'recoverReadyFinalizations'), 'ASSETS_REQUIRED finalization must resume idempotently when readiness becomes READY.');
$assertTrue(str_contains($lifecycle, 'MAX_CATCH_UP_QUARTERS'), 'Lifecycle must bound missed-quarter catch-up work.');
$assertTrue(!str_contains($lifecycle, 'AdminNotificationEventService'), 'MVP-20.4 must not create a parallel admin notification writer; MVP-22 owns presentation.');

$assertTrue(str_contains($resolver, 'official_start_at_utc <= :finished_at'), 'Delayed results must resolve by official finish-time season.');
$assertTrue(str_contains($resolver, 'calendar_end_at_utc > :finished_at'), 'Season assignment must use an exclusive quarter-end boundary.');
$assertTrue(str_contains($resolver, 'STATE_PRESEASON'), 'Pre-activation results must remain PRESEASON.');

$assertTrue(str_contains($rating, 'SeasonAssignmentResolver'), 'Visible rating must use the MVP-20.4 finish-time season resolver when loaded.');
$assertTrue(str_contains($bridge, 'reconcileSeasonLifecycle'), 'Rating runtime must reconcile the season calendar before rating work.');
$assertTrue(
    strpos($bridge, '$this->reconcileSeasonLifecycle();') < strpos($bridge, 'processPendingFinishedMatches($limit)'),
    'Season lifecycle must advance before visible-rating projection consumes finished matches.'
);

foreach (['SeasonCalendar.php','SeasonAssignmentResolver.php','SeasonLifecycleService.php'] as $owner) {
    $assertTrue(str_contains($bootstrap, "../ratings/{$owner}"), 'Bootstrap must load ' . $owner . '.');
}

$assertTrue(!str_contains($lifecycle, 'awardBadge') && !str_contains($lifecycle, 'grantMedal'), 'MVP-20.4 must not steal MVP-20.5/20.6 award ownership.');
$assertTrue(!str_contains($lifecycle, 'cron'), 'MVP-20.4 must not silently create or require a Cron owner.');
$assertTrue($assertions >= 30, 'MVP-20.4 integration contract must protect lifecycle ownership and frozen boundaries.');

fwrite(STDOUT, "Mvp20_4SeasonLifecycleIntegrationContractTest: {$assertions} assertions passed\n");
