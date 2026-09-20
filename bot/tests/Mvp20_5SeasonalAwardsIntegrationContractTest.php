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

$migration = $read('bot/database/migrations/20260920_0046_create_seasonal_awards.php');
$awards = $read('bot/ratings/SeasonalAwardService.php');
$leaderboard = $read('bot/ratings/LeaderboardService.php');
$lifecycle = $read('bot/ratings/SeasonLifecycleService.php');
$bridge = $read('bot/ratings/PerGameRatingRuntimeBridge.php');
$bootstrap = $read('bot/core/bootstrap.php');

$assertTrue(str_contains($migration, 'mgw_season_award_runs'), 'MVP-20.5 must persist idempotent award-run fingerprints.');
$assertTrue(str_contains($migration, 'mgw_season_awards'), 'MVP-20.5 must persist durable season/game award entitlements.');
$assertTrue(str_contains($migration, 'mgw_season_award_audit'), 'MVP-20.5 must persist append-only correction audit.');
$assertTrue(str_contains($migration, 'frame_valid_until_at_utc'), 'Temporary top-3 frames must carry an explicit expiry boundary.');
$assertTrue(str_contains($migration, 'award_state'), 'Awards must support active/revoked correction state.');

$assertTrue(str_contains($awards, "BADGE_GOLD = 'gold'"), 'Rank 1 gold badge tier must be explicit.');
$assertTrue(str_contains($awards, "BADGE_SILVER = 'silver'"), 'Rank 2-10 silver badge tier must be explicit.');
$assertTrue(str_contains($awards, "BADGE_BRONZE = 'bronze'"), 'Rank 11-100 bronze badge tier must be explicit.');
$assertTrue(str_contains($awards, 'MAX_REWARDED_RANK = 100'), 'Seasonal badges must stop at top 100.');
$assertTrue(str_contains($awards, 'MAX_FRAME_RANK = 3'), 'Temporary frames must stop at top 3.');
$assertTrue(str_contains($awards, "if ($rank === 1)"), 'Gold tier boundary must start only at rank 1.');
$assertTrue(str_contains($awards, "if ($rank <= 10)"), 'Silver tier boundary must cover through rank 10.');
$assertTrue(str_contains($awards, 'frame_valid_from_at_utc'), 'Top-3 frame entitlement must have a next-season start.');
$assertTrue(str_contains($awards, 'frame_valid_until_at_utc'), 'Top-3 frame entitlement must have a next-season end.');
$assertTrue(str_contains($awards, 'projection_pending'), 'Awards must wait for rating projection before freezing results.');
$assertTrue(str_contains($awards, "PerGameRatingService::PRESEASON_ID"), 'PRESEASON must be guarded explicitly.');
$assertTrue(str_contains($awards, 'reconcileGameAwards'), 'One authoritative reconciliation seam must support corrected standings.');
$assertTrue(str_contains($awards, "'revoke'"), 'Fraud correction must support audited revocation.');
$assertTrue(str_contains($awards, "'reissue'"), 'Fraud correction must support audited reissue.');
$assertTrue(str_contains($awards, "'grant'"), 'Fraud correction must support audited grants to shifted players.');
$assertTrue(str_contains($awards, 'standings_fingerprint'), 'Identical reruns must be idempotent by standings fingerprint.');

$assertTrue(!str_contains($awards, 'ProductInventoryService'), 'Temporary seasonal awards must not hijack permanent Store inventory ownership.');
$assertTrue(!str_contains($awards, 'mgw_inventory_items'), 'Seasonal award truth must remain revocable and separate from permanent inventory.');
$assertTrue(!str_contains($awards, 'mgw_equipped_items'), 'Award finalization must not overwrite a player cosmetic equip choice.');
$assertTrue(!str_contains($awards, 'CosmeticStoreService'), 'Season close must not mutate Store purchase state.');

$assertTrue(str_contains($leaderboard, 'standingsForSeason'), 'Award calculation must reuse the canonical leaderboard ranking owner.');
$assertTrue(str_contains($leaderboard, 'NOT IN'), 'Fraud exclusions must be applied before the top-100 LIMIT so places can shift.');
$assertTrue(str_contains($leaderboard, 'development_provider'), 'Technical development identities must remain excluded from award standings.');
$assertTrue(str_contains($leaderboard, 's.points DESC'), 'Awards must preserve visible-points descending tie-break.');
$assertTrue(str_contains($leaderboard, 's.rated_wins DESC'), 'Awards must preserve credited-wins descending tie-break.');
$assertTrue(str_contains($leaderboard, 's.updated_at_utc ASC'), 'Awards must preserve earlier score-arrival tie-break.');
$assertTrue(str_contains($leaderboard, 's.mgw_id ASC'), 'Awards must preserve stable MGW-ID final tie-break.');

$assertTrue(str_contains($lifecycle, "class_exists('SeasonalAwardService')"), 'MVP-20.4 lifecycle must compose with the MVP-20.5 award owner.');
$assertTrue(str_contains($lifecycle, 'rating_projection_pending'), 'Lifecycle must remain FINALIZING while old-season projection is incomplete.');
$assertTrue(str_contains($lifecycle, 'SeasonalAwardService($database)')->finalizeSeason ?? false, 'Lifecycle must finalize awards before marking the season closed.');

$pre = strpos($bridge, '$this->reconcileSeasonLifecycle(false);');
$projection = strpos($bridge, '$summary = $this->service()->processPendingFinishedMatches($limit);');
$post = strpos($bridge, '$this->reconcileSeasonLifecycle(true);');
$assertTrue($pre !== false && $projection !== false && $post !== false && $pre < $projection && $projection < $post, 'Runtime order must be calendar advance -> rating projection -> award completion.');

$assertTrue(str_contains($bootstrap, "../ratings/SeasonalAwardService.php"), 'Runtime bootstrap must load the seasonal award owner.');
$assertTrue(
    strpos($bootstrap, "../ratings/LeaderboardService.php") < strpos($bootstrap, "../ratings/SeasonalAwardService.php")
    && strpos($bootstrap, "../ratings/SeasonalAwardService.php") < strpos($bootstrap, "../ratings/SeasonLifecycleService.php"),
    'Award dependencies must load before lifecycle composition.'
);

$assertTrue(!str_contains($awards, 'admin.php'), 'MVP-20.5 must not steal future Admin review UI ownership.');
$assertTrue(!str_contains($awards, 'Cron'), 'MVP-20.5 must not introduce an unapproved Cron owner.');
$assertTrue($assertions >= 35, 'MVP-20.5 integration contract must protect ranking, correction, temporary-frame and ownership semantics.');

fwrite(STDOUT, "Mvp20_5SeasonalAwardsIntegrationContractTest: {$assertions} assertions passed\n");
