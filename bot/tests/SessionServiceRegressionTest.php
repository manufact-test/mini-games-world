<?php
declare(strict_types=1);

require dirname(__DIR__) . '/services/SessionService.php';

if (!function_exists('now_iso')) {
    function now_iso(): string
    {
        return gmdate('c');
    }
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};
$assertThrows = static function (callable $callback, string $contains, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains($error->getMessage(), $contains)) return;
        throw new RuntimeException($message . ': unexpected error ' . $error->getMessage());
    }
    throw new RuntimeException($message . ': no error was thrown');
};

$sessions = new SessionService(['active_session_timeout_sec' => 180]);
$user = ['status' => 'idle'];
$sessions->touch($user, 'session-a');
$assertSame('session-a', $user['active_session_id'], 'Idle user must allow the first session to become active');

$user['status'] = 'searching';
$user['active_session_at'] = now_iso();
$assertSame(true, $sessions->canTakeSession($user, 'session-a'), 'Owning session must keep access during matchmaking');
$assertSame(false, $sessions->canTakeSession($user, 'session-b'), 'Different session must not take an active search');
$assertThrows(
    static fn() => $sessions->assertCanPlay($user, 'session-b'),
    'ищете матч',
    'Search ownership message must remain intact'
);

$user['status'] = 'playing';
$assertSame(false, $sessions->canTakeSession($user, 'session-b'), 'Different session must not take an active game');
$state = $sessions->publicState($user, 'session-b');
$assertSame(true, $state['locked'], 'Public session state must report the active-game lock');
$assertThrows(
    static fn() => $sessions->assertCanPlay($user, 'session-b'),
    'активная игра',
    'Game ownership message must remain intact'
);

$user['active_session_at'] = gmdate('c', time() - 181);
$assertSame(true, $sessions->canTakeSession($user, 'session-b'), 'Expired active-session ownership must be recoverable');
$sessions->touch($user, 'session-b');
$assertSame('session-b', $user['active_session_id'], 'Recovered session must become the new owner');

$sessions->releaseIfCurrent($user, 'session-a');
$assertSame('session-b', $user['active_session_id'], 'Non-owner release must not clear the current session');
$sessions->releaseIfCurrent($user, 'session-b');
$assertSame(null, $user['active_session_id'], 'Current owner release must clear active-session ownership');

fwrite(STDOUT, "SessionServiceRegressionTest: {$assertions} assertions passed\n");
