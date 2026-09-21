<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};

$source = [
    'service'=>$read('bot/tournaments/TournamentHallService.php'),
    'endpoint'=>$read('bot/tournament-hall.php'),
    'client'=>$read('app/assets/js/api/client.js'),
    'screen'=>$read('app/assets/js/screens/tournaments-screen-v1.js'),
    'css'=>$read('app/assets/css/main.css'),
    'manifest'=>$read('app/runtime/client/version-manifest.php'),
    'manual_fixture'=>$read('bot/tournaments/StagingTournamentManualAcceptanceService.php'),
    'diagnostic'=>$read('bot/staging-projection-diagnostic.php'),
    'admin'=>$read('app/assets/js/admin-tournaments.js'),
    'admin_page'=>$read('app/admin.php'),
];

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($source['service'], 'HALL_OPEN_BEFORE_SECONDS = 900'),
    'Hall must open exactly 15 minutes before tournament start.');
$assert(str_contains($source['service'], 'HALL_PRESENCE_FRESHNESS_SECONDS = 8'),
    'Hall must reuse the accepted gameplay foreground freshness window.');
$assert(str_contains($source['service'], 'Турнирный зал доступен только зарегистрированным участникам.'),
    'Hall service must reject spectators/nonparticipants with localized copy.');
$assert(str_contains($source['service'], 'random_int(')
        && str_contains($source['service'], 'bracket_generated_at_utc'),
    'Bracket must have a server-side random immutable generation owner.');
$assert(str_contains($source['service'], 'technical_loss_at_start'),
    'Absent participants must remain in the bracket with technical loss evidence.');
$assert(!str_contains($source['service'], 'ready_at_utc')
        && !str_contains($source['service'], 'countdown_started_at_utc'),
    'MVP-21.4 service must not implement MVP-21.5 ready/countdown state.');

$assert(str_contains($source['endpoint'], 'new PresenceService()')
        && str_contains($source['endpoint'], 'gameplaySnapshot'),
    'Hall endpoint must reuse the canonical PresenceService owner.');
$assert(str_contains($source['endpoint'], "['status','enter','heartbeat']"),
    'Hall endpoint must expose only the bounded participant Hall actions.');

foreach ([
    'tournamentHallStatus',
    'tournamentHallEnter',
    'tournamentHallHeartbeat',
] as $needle) {
    $assert(str_contains($source['client'], $needle), 'Hall client transport missing: ' . $needle);
}
$assert(substr_count($source['client'], 'tournamentHallStatus:') === 1
        && substr_count($source['client'], 'tournamentHallEnter:') === 1,
    'Hall client must expose exactly one status/enter owner so dedicated endpoint actions cannot be overwritten.');
$assert(str_contains($source['client'], "requestUrl(TOURNAMENT_HALL_URL, { action:'enter' })")
        && !str_contains($source['client'], "request('tournament_hall_enter')"),
    'Hall entry must use the dedicated tournament-hall endpoint instead of the generic action router.');

foreach ([
    'data-tournament-hall-enter',
    'tournamentHallHeartbeat',
    'Турнирный зал',
    'Сетка ещё скрыта',
    'случайная сетка',
    'tournamentBracketMarkup',
] as $needle) {
    $assert(str_contains($source['screen'], $needle), 'Tournament Hall UI missing: ' . $needle);
}
$assert(!str_contains($source['screen'], 'Tournament Hall')
        && !str_contains($source['endpoint'], 'Tournament Hall')
        && !str_contains($source['service'], 'Tournament Hall'),
    'User-facing Hall copy must be localized to Russian.');
$assert(!str_contains($source['screen'], 'относятся к MVP-21.5')
        && !str_contains($source['screen'], 'Этап «Я готов»'),
    'Hall UI must not expose internal roadmap/MVP copy to players.');
$assert(str_contains($source['screen'], 'Она сформируется случайно ровно на старте турнира.')
        && !str_contains($source['screen'], 'статус присутствия участников'),
    'Pre-start Hall copy must stay player-facing and omit technical presence explanation.');
$assert(str_contains($source['screen'], "'Турнир начался'")
        && !str_contains($source['screen'], 'Время старта наступило')
        && str_contains($source['screen'], 'data-tournament-countdown-label')
        && str_contains($source['screen'], 'countdownLabel.hidden = startedNow'),
    'Post-start schedule card must collapse to a clean Турнир начался state without stale До старта copy.');
$assert(str_contains($source['screen'], "const buttonLabel = tournamentHallBusy ? 'Входим в зал…' : 'Вход';")
        && str_contains($source['screen'], "hallButton.textContent = 'Вход';"),
    'Hall CTA must stay concise: timing belongs to the Hall status copy, button label is simply Вход.');

foreach ([
    '.tournaments-v2-hall-gate',
    '.tournaments-v2-hall-roster-grid',
    '.tournaments-v2-bracket-grid',
    '.tournaments-v2-bracket-player.is-loss',
] as $needle) {
    $assert(str_contains($source['css'], $needle), 'Tournament Hall CSS missing: ' . $needle);
}

$assert(str_contains($source['manifest'], 'client.js?v=1143')
        && str_contains($source['manifest'], 'mvp21_4=tournament-hall-v1')
        && str_contains($source['manifest'], 'hall_transport=direct-endpoint-v2'),
    'Hall release must preserve the accepted API cache contract and publish the direct-endpoint corrective identity.');
$assert(str_contains($source['manifest'], 'tournaments-screen-v1.js?v=18')
        && str_contains($source['manifest'], 'mvp21_4=tournament-hall-bracket-v2')
        && str_contains($source['manifest'], 'hall_cta=entry-v1')
        && str_contains($source['manifest'], 'copy_polish=final-v1'),
    'Hall release must preserve the accepted Tournament screen base version and publish the final copy-polish identity.');
$assert(str_contains($source['manifest'], 'main.css?v=199')
        && str_contains($source['manifest'], 'mvp21_4=tournament-hall-bracket-v2'),
    'Hall release must preserve accepted CSS base version and add a fresh Hall identity.');

$assert(str_contains($source['manual_fixture'], '$runtimeBatch')
        && str_contains($source['manual_fixture'], 'ensureRuntimeUsers($runtimeBatch)')
        && str_contains($source['manual_fixture'], 'repairFixtureRuntimeParity($server)'),
    'Manual 7/8 preparation must batch runtime writes and verify fixture parity after reseed.');
$assert(str_contains($source['manual_fixture'], "preg_match('/^stg_tour_(?:v2_)?[a-f0-9]{12}$/', \$legacyUserId)")
        && str_contains($source['manual_fixture'], "'runtime_fixture_users_removed'"),
    'Fixture parity repair must remain narrowly scoped to tournament test identities.');
$assert(str_contains($source['diagnostic'], 'unified_economy_probe_failed')
        && str_contains($source['diagnostic'], "'unified_economy_preview'")
        && str_contains($source['diagnostic'], 'repairFixtureRuntimeParity($_SERVER)'),
    'Staging deploy diagnostic must repair fixture parity and prove unified-economy readiness.');
$assert(str_contains($source['diagnostic'], "'notification_runtime_parity'")
        && str_contains($source['diagnostic'], "'user_ref_sha256'")
        && str_contains($source['diagnostic'], "'sensitive_identifiers_exposed'=>false")
        && str_contains($source['diagnostic'], "'user_ref_sha256'=>substr(hash('sha256', \$legacyUserId), 0, 16)"),
    'Staging diagnostic must expose notification parity failures through bounded hashed user references.');
$assert(str_contains($source['diagnostic'], "preg_match('/^stg_tour_(?:v2_)?[a-f0-9]{12}$/', \$legacyUserId)")
        && str_contains($source['diagnostic'], "'technical_ab'")
        && str_contains($source['diagnostic'], "'tournament_fixture'"),
    'Notification diagnostic must classify technical staging identities without publishing raw identifiers.');
$assert(str_contains($source['diagnostic'], "'mismatch_field_counts'")
        && str_contains($source['diagnostic'], "'mismatch_samples'")
        && str_contains($source['diagnostic'], "'event_ref_sha256'")
        && !str_contains($source['diagnostic'], "'event_key'=>\$eventKey"),
    'Notification mismatch diagnostic must expose only field names and hashed event references.');
$assert(str_contains($source['diagnostic'], "'notification_primary_parity'")
        && str_contains($source['diagnostic'], "'rollback_vs_primary'")
        && str_contains($source['diagnostic'], "'primary_revision'")
        && str_contains($source['diagnostic'], 'new DatabasePrimaryStateStorageAdapter($db)'),
    'Staging diagnostic must compare the active DB-primary notification snapshot with module DB and rollback JSON.');
$assert(str_contains($source['admin'], '{ lockDraftControls:false }')
        && str_contains($source['admin'], 'control === title || control === game || control === capacity'),
    'Read-only Tournament Admin refresh must not freeze draft title/game/capacity controls.');
$assert(str_contains($source['admin_page'], 'admin-tournaments.js?v=7')
        && str_contains($source['admin_page'], 'mvp21_4=staging-reset-reseed-v2'),
    'Tournament Admin corrective must publish a fresh cache identity.');

if ($assertions < 30) throw new RuntimeException('MVP-21.4 UX contract is too shallow.');
fwrite(STDOUT, "Mvp21_4TournamentHallUxContractTest: {$assertions} assertions passed\n");
