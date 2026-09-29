<?php
declare(strict_types=1);

require_once __DIR__ . '/../database/DatabaseConnectionInterface.php';
require_once __DIR__ . '/../database/DatabaseExceptionClassifier.php';
require_once __DIR__ . '/../database/PdoDatabaseConnection.php';
require_once __DIR__ . '/../services/UserActionRateLimiter.php';

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDOUT, "SKIP: pdo_sqlite is unavailable\n");
    exit(0);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db = new PdoDatabaseConnection($pdo);

$db->execute('CREATE TABLE mgw_support_tickets (requester_mgw_id TEXT NOT NULL, created_at_utc TEXT NOT NULL)');
$db->execute('CREATE TABLE mgw_support_ticket_messages (actor_type TEXT NOT NULL, actor_ref TEXT NOT NULL, created_at_utc TEXT NOT NULL)');
$db->execute('CREATE TABLE mgw_player_reports (reporter_mgw_id TEXT NOT NULL, created_at_utc TEXT NOT NULL)');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertLimited = static function (callable $callback, string $expectedAction) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (UserActionRateLimitException $error) {
        if ($error->action !== $expectedAction) {
            throw new RuntimeException('Unexpected limited action: ' . $error->action);
        }
        if ($error->retryAfterSec < 1 || $error->retryAfterSec > 86400) {
            throw new RuntimeException('Retry-After must be bounded and positive.');
        }
        return;
    }
    throw new RuntimeException('Expected rate-limit exception for ' . $expectedAction);
};

$actor = 'MGW-RATE-LIMIT-USER';
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$recent = $now->modify('-30 seconds')->format('Y-m-d H:i:s.u');
$old = $now->modify('-2 hours')->format('Y-m-d H:i:s.u');

$limiter = new UserActionRateLimiter($db);
$limiter->assertAllowed('support_create', $actor);
$assert(true, 'Fresh actor must be allowed.');

for ($i = 0; $i < 5; $i++) {
    $db->execute(
        'INSERT INTO mgw_support_tickets (requester_mgw_id, created_at_utc) VALUES (:actor,:created)',
        ['actor'=>$actor,'created'=>$recent]
    );
}
$assertLimited(fn() => $limiter->assertAllowed('support_create', $actor), 'support_create');

$db->execute(
    'INSERT INTO mgw_support_tickets (requester_mgw_id, created_at_utc) VALUES (:actor,:created)',
    ['actor'=>'MGW-OLD-ONLY','created'=>$old]
);
$limiter->assertAllowed('support_create', 'MGW-OLD-ONLY');
$assert(true, 'Records outside the rolling window must not throttle.');

for ($i = 0; $i < 12; $i++) {
    $db->execute(
        "INSERT INTO mgw_support_ticket_messages (actor_type, actor_ref, created_at_utc)
         VALUES ('user', :actor, :created)",
        ['actor'=>$actor,'created'=>$recent]
    );
}
$db->execute(
    "INSERT INTO mgw_support_ticket_messages (actor_type, actor_ref, created_at_utc)
     VALUES ('admin', :actor, :created)",
    ['actor'=>$actor,'created'=>$recent]
);
$assertLimited(fn() => $limiter->assertAllowed('support_reply', $actor), 'support_reply');

for ($i = 0; $i < 10; $i++) {
    $db->execute(
        'INSERT INTO mgw_player_reports (reporter_mgw_id, created_at_utc) VALUES (:actor,:created)',
        ['actor'=>$actor,'created'=>$recent]
    );
}
$assertLimited(fn() => $limiter->assertAllowed('player_report', $actor), 'player_report');

$override = new UserActionRateLimiter($db, [
    'security_rate_limits'=>[
        'support_create'=>['max_requests'=>6,'window_seconds'=>30],
    ],
]);
$override->assertAllowed('support_create', $actor);
$assert(true, 'Config override must be honored without changing the domain service.');

$invalid = false;
try {
    $limiter->assertAllowed('unknown_action', $actor);
} catch (InvalidArgumentException) {
    $invalid = true;
}
$assert($invalid, 'Unknown rate-limit actions must fail closed.');

fwrite(STDOUT, "UserActionRateLimiterTest: {$assertions} assertions passed\n");
