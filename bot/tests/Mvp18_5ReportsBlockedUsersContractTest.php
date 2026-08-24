<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/database/DatabaseConnectionInterface.php';
require_once $root . '/database/PdoDatabaseConnection.php';
require_once $root . '/database/DatabaseMigrationInterface.php';
require_once $root . '/accounts/MgwIdGenerator.php';
require_once $root . '/accounts/MgwIdentityPolicy.php';
require_once $root . '/social/FriendGraphService.php';
require_once $root . '/social/SocialInviteGuard.php';
require_once $root . '/social/PlayerReportService.php';

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDOUT, "SKIP: pdo_sqlite is unavailable\n");
    exit(0);
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};

$pdo = new PDO('sqlite::memory:');
$pdo->exec('PRAGMA foreign_keys = ON');
$db = new PdoDatabaseConnection($pdo);

$db->execute(<<<'SQL'
CREATE TABLE mgw_users (
    mgw_id TEXT NOT NULL PRIMARY KEY,
    status TEXT NOT NULL,
    nickname TEXT NOT NULL,
    equipped_avatar_item_id TEXT NULL
)
SQL);
$db->execute(<<<'SQL'
CREATE TABLE mgw_identities (
    mgw_id TEXT NOT NULL,
    provider TEXT NOT NULL,
    provider_subject TEXT NOT NULL,
    last_authenticated_at_utc TEXT NULL,
    PRIMARY KEY (provider, provider_subject),
    FOREIGN KEY (mgw_id) REFERENCES mgw_users (mgw_id)
)
SQL);
$db->execute(<<<'SQL'
CREATE TABLE mgw_social_relations (
    user_low_mgw_id TEXT NOT NULL,
    user_high_mgw_id TEXT NOT NULL,
    friend_status TEXT NOT NULL DEFAULT 'none',
    requested_by_mgw_id TEXT NULL,
    blocked_by_low INTEGER NOT NULL DEFAULT 0,
    blocked_by_high INTEGER NOT NULL DEFAULT 0,
    friend_requested_at_utc TEXT NULL,
    friend_resolved_at_utc TEXT NULL,
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL,
    PRIMARY KEY (user_low_mgw_id, user_high_mgw_id)
)
SQL);
$db->execute('CREATE TABLE mgw_matches (match_id TEXT NOT NULL PRIMARY KEY)');
$db->execute(<<<'SQL'
CREATE TABLE mgw_match_players (
    match_id TEXT NOT NULL,
    seat INTEGER NOT NULL,
    mgw_id TEXT NULL,
    PRIMARY KEY (match_id, seat)
)
SQL);

$actor = 'MGW-0123456789ABCDEF';
$target = 'MGW-0123456789ABCDEG';
$now = '2026-08-19 12:00:00.000000';
foreach ([[$actor, 'Alpha'], [$target, 'Beta']] as [$mgwId, $nickname]) {
    $db->execute(
        'INSERT INTO mgw_users (mgw_id, status, nickname, equipped_avatar_item_id) VALUES (:id, :status, :nickname, :avatar)',
        ['id' => $mgwId, 'status' => 'active', 'nickname' => $nickname, 'avatar' => MgwIdentityPolicy::DEFAULT_AVATAR_ITEM_ID]
    );
}
$db->execute(
    'INSERT INTO mgw_identities (mgw_id, provider, provider_subject, last_authenticated_at_utc) VALUES (:id, :provider, :subject, :seen)',
    ['id' => $actor, 'provider' => 'telegram', 'subject' => '100', 'seen' => $now]
);
$db->execute(
    'INSERT INTO mgw_identities (mgw_id, provider, provider_subject, last_authenticated_at_utc) VALUES (:id, :provider, :subject, :seen)',
    ['id' => $target, 'provider' => 'telegram', 'subject' => '200', 'seen' => $now]
);
$db->execute(
    'INSERT INTO mgw_social_relations (
        user_low_mgw_id, user_high_mgw_id, friend_status, requested_by_mgw_id,
        blocked_by_low, blocked_by_high, created_at_utc, updated_at_utc
     ) VALUES (:low, :high, :status, NULL, 1, 0, :created, :updated)',
    ['low' => $actor, 'high' => $target, 'status' => 'none', 'created' => $now, 'updated' => $now]
);

$guard = new SocialInviteGuard($db);
$blockedThrown = false;
try {
    $guard->runtimeSubjectForMgwId($actor, $target, 'telegram');
} catch (SocialInviteGuardException $error) {
    $blockedThrown = str_contains($error->getMessage(), 'заблокирован');
}
$assertTrue($blockedThrown, 'Canonical social block must reject direct invite identity resolution');

$db->execute(
    'UPDATE mgw_social_relations SET blocked_by_low = 0 WHERE user_low_mgw_id = :low AND user_high_mgw_id = :high',
    ['low' => $actor, 'high' => $target]
);
$assertSame('200', $guard->runtimeSubjectForMgwId($actor, $target, 'telegram'), 'Unblocked target must resolve to the existing runtime provider subject');

$db->execute('INSERT INTO mgw_matches (match_id) VALUES (:match_id)', ['match_id' => 'match-report-1']);
$db->execute('INSERT INTO mgw_match_players (match_id, seat, mgw_id) VALUES (:match_id, 1, :mgw_id)', ['match_id' => 'match-report-1', 'mgw_id' => $actor]);
$db->execute('INSERT INTO mgw_match_players (match_id, seat, mgw_id) VALUES (:match_id, 2, :mgw_id)', ['match_id' => 'match-report-1', 'mgw_id' => $target]);

$migration = require $root . '/database/migrations/20260819_0012_create_player_reports.php';
$assertTrue($migration instanceof DatabaseMigrationInterface, 'MVP-18.5 migration must implement DatabaseMigrationInterface');
$migration->up($db);

$reports = new PlayerReportService($db);
$case = $reports->submit($actor, $target, 'abuse', 'Нарушение в матче.', 'match-report-1');
$assertSame('open', $case['status'], 'Every player report must enter the operational queue as open');
$assertTrue(str_starts_with((string)$case['report_id'], 'RPT-'), 'Every report must receive a stable case id');

$queue = $reports->queue();
$assertSame(1, count($queue), 'Submitted report must be visible in the admin queue');
$assertSame($actor, $queue[0]['reporter_mgw_id'], 'Queue must retain reporter MGW identity');
$assertSame($target, $queue[0]['target_mgw_id'], 'Queue must retain target MGW identity');
$assertSame('abuse', $queue[0]['reason'], 'Queue must retain structured report reason');
$assertSame('match-report-1', $queue[0]['related_match_id'], 'Queue must retain validated related match');

$reviewing = $reports->setStatus((string)$case['report_id'], 'reviewing', 'telegram:admin');
$assertSame('reviewing', $reviewing['status'], 'Admin queue must support reviewing lifecycle state');
$closed = $reports->setStatus((string)$case['report_id'], 'closed', 'telegram:admin');
$assertSame('closed', $closed['status'], 'Admin queue must support closed lifecycle state');
$assertTrue((string)$closed['resolved_at'] !== '', 'Closed case must have a resolution timestamp');

$invalidReasonThrown = false;
try {
    $reports->submit($actor, $target, 'auto_ban', '', '');
} catch (PlayerReportException $error) {
    $invalidReasonThrown = $error->reason === 'invalid_reason';
}
$assertTrue($invalidReasonThrown, 'Unknown moderation actions must not be accepted as report reasons');

$friendsUi = file_get_contents(dirname($root) . '/app/assets/js/screens/friends-screen-v110.js');
$friendsEndpoint = file_get_contents($root . '/friends.php');
$invitesEndpoint = file_get_contents($root . '/invites.php');
$adminEndpoint = file_get_contents($root . '/admin-reports.php');
$adminPage = file_get_contents(dirname($root) . '/app/admin.php');
foreach ([$friendsUi, $friendsEndpoint, $invitesEndpoint, $adminEndpoint, $adminPage] as $source) {
    if (!is_string($source)) throw new RuntimeException('MVP-18.5 contract source is unavailable.');
}

$assertTrue(str_contains($friendsUi, "section('Заблокированные'"), 'Blocked users settings list must be visible in Friends UI');
$assertTrue(str_contains($friendsUi, "mutation === 'unblock'"), 'Unblock must have an explicit UI branch');
$assertTrue(str_contains($friendsUi, 'Разблокировать игрока?'), 'Unblock must require confirmation');
$assertTrue(str_contains($friendsUi, "action:'report'"), 'Report UI must submit structured moderation data');
$assertTrue(!str_contains($friendsUi, '<select') && str_contains($friendsUi, 'data-report-reason-menu'), 'Report reason must use the managed dark dropdown instead of a native browser list');
$assertTrue(!str_contains($friendsUi, "api.support('player_report'"), 'Legacy support text must not own player reports after MVP-18.5');
$assertTrue(str_contains($friendsEndpoint, 'PlayerReportService'), 'Friends endpoint must use the canonical report queue owner');
$assertTrue(str_contains($invitesEndpoint, 'SocialInviteGuard'), 'Invite endpoint must enforce the canonical social block graph');
$assertTrue(str_contains($invitesEndpoint, "case 'create_direct':") && str_contains($invitesEndpoint, "case 'open_link':") && str_contains($invitesEndpoint, "case 'rematch':"), 'Block guard must cover direct, link-open and rematch invite boundaries');
$assertTrue(str_contains($adminEndpoint, "'set_status'"), 'Admin report queue must expose explicit case lifecycle updates');
$assertTrue(str_contains($adminEndpoint, "'case_link'"), 'Admin queue must expose a stable case link');
$assertTrue(!str_contains(strtolower($adminEndpoint), 'auto-ban') && !str_contains(strtolower($adminEndpoint), 'autoban'), 'Admin report endpoint must not implement auto-ban');
$assertTrue(str_contains($adminPage, 'data-admin-reports'), 'Existing Web Admin must surface the report queue');

fwrite(STDOUT, 'PASS: MVP-18.5 reports and blocked users contract (' . $assertions . " assertions)\n");
