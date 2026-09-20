<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$paths = [
    'migration'=>'bot/database/migrations/20260920_0048_create_rating_admin_review.php',
    'service'=>'bot/ratings/RatingAdminService.php',
    'endpoint'=>'bot/admin-rating.php',
    'bootstrap'=>'bot/core/bootstrap.php',
    'visible'=>'bot/ratings/PerGameRatingService.php',
    'hidden'=>'bot/ratings/HiddenSkillService.php',
    'admin'=>'app/admin.php',
    'client'=>'app/assets/js/admin-rating.js',
    'css'=>'app/assets/css/admin-shell.css',
];
$sources = [];
foreach ($paths as $key=>$path) {
    $value = file_get_contents($root . '/' . $path);
    if (!is_string($value)) throw new RuntimeException('Missing MVP-20.8 source: ' . $path);
    $sources[$key] = $value;
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assertTrue(str_contains($sources['migration'], 'mgw_rating_review_exclusions'), 'MVP-20.8 must persist reviewed exclusions.');
$assertTrue(str_contains($sources['migration'], 'mgw_rating_review_audit'), 'MVP-20.8 must keep append-only review audit.');
$assertTrue(str_contains($sources['migration'], 'mgw_rating_recalculation_jobs'), 'MVP-20.8 must persist recalculation jobs.');

$assertTrue(str_contains($sources['service'], 'reconcileGameAwards('), 'Recalculation must delegate seasonal awards to MVP-20.5 owner.');
$assertTrue(str_contains($sources['service'], 'reconcileSeasonFragment('), 'Recalculation must delegate yearly fragments to MVP-20.6 owner.');
$assertTrue(str_contains($sources['service'], 'seasonCloseRehearsal'), 'MVP-20.8 must expose a season-close rehearsal.');
$assertTrue(str_contains($sources['service'], "'dry_run'=>true"), 'Season-close rehearsal must state dry-run semantics.');
$assertTrue(str_contains($sources['service'], 'botExclusionMetrics'), 'MVP-20.8 metrics must expose bot-exclusion invariant.');
$assertTrue(!str_contains($sources['service'], 'UPDATE mgw_game_rating_scores'), 'Rating Admin must never rewrite visible rating scores.');
$assertTrue(!str_contains($sources['service'], 'DELETE FROM mgw_game_rating_participation'), 'Rating Admin must never delete canonical participation history.');

$assertTrue(str_contains($sources['endpoint'], 'AdminWebAuth::authorize'), 'Rating Admin endpoint must require Telegram admin authentication.');
foreach (["action === 'exclude'","action === 'restore'","action === 'recalculate'","action === 'rehearsal'"] as $needle) {
    $assertTrue(str_contains($sources['endpoint'], $needle), 'Rating Admin endpoint missing action: ' . $needle);
}
$assertTrue(str_contains($sources['bootstrap'], "ratings/RatingAdminService.php"), 'Runtime bootstrap must load RatingAdminService.');
$assertTrue(str_contains($sources['visible'], "return $this->finalDecision(0, 'bot_game')"), 'Visible rating must keep bot games unrated.');
$assertTrue(str_contains($sources['hidden'], "'bot_game'"), 'Hidden skill must keep bot games excluded.');

$assertTrue(str_contains($sources['admin'], 'data-rating-api="../bot/admin-rating.php"'), 'Web Admin must publish Rating Admin endpoint.');
$assertTrue(str_contains($sources['admin'], 'data-rating-rehearsal'), 'Web Admin must expose season-close rehearsal.');
$assertTrue(str_contains($sources['admin'], 'data-rating-recalculate'), 'Web Admin must expose reviewed recalculation.');
$assertTrue(str_contains($sources['client'], "action:'exclude'"), 'Rating Admin client must wire reviewed exclusion.');
$assertTrue(str_contains($sources['client'], "action:'restore'"), 'Rating Admin client must wire appeal restore.');
$assertTrue(str_contains($sources['client'], "action:'recalculate'"), 'Rating Admin client must wire recalculation.');
$assertTrue(str_contains($sources['client'], "action:'rehearsal'"), 'Rating Admin client must wire dry-run rehearsal.');
$assertTrue(str_contains($sources['css'], '.mgw-admin__rating-metrics'), 'Rating Admin must have bounded responsive presentation.');

$assertTrue(is_file($root . '/bot/tests/Mvp20_8RatingLoadTest.php'), 'MVP-20.8 must ship a focused rating load test.');
$assertTrue($assertions >= 25, 'MVP-20.8 integration contract must cover review, recalculation, bot exclusion, rehearsal, load and Admin wiring.');

fwrite(STDOUT, "Mvp20_8RatingAdminIntegrationContractTest: {$assertions} assertions passed\n");
