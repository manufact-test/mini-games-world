<?php
declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/invites.php');
if (!is_string($source) || $source === '') {
    throw new RuntimeException('Invite runtime endpoint source is unavailable.');
}

$assertions = 0;
$assertContains = static function (string $needle, string $message) use (&$assertions, $source): void {
    $assertions++;
    if (!str_contains($source, $needle)) {
        throw new RuntimeException($message);
    }
};
$assertBefore = static function (string $first, string $second, string $message) use (&$assertions, $source): void {
    $assertions++;
    $firstPosition = strpos($source, $first);
    $secondPosition = strpos($source, $second);
    if ($firstPosition === false || $secondPosition === false || $firstPosition >= $secondPosition) {
        throw new RuntimeException($message);
    }
};

$assertContains(
    "$runtimeStorageRouter->routeFor('invites') === RuntimeStorageRouter::DRIVER_DATABASE",
    'Invite endpoint must activate the DB bridge only through the runtime router'
);
$assertContains(
    'new RuntimeInviteRepository($config, $runtimeStorageRouter)',
    'Invite endpoint must construct the staged repository with the validated router'
);
$assertContains(
    '$runtimeInvites->synchronize($data);',
    'Invite endpoint must synchronize the mutated JSON snapshot before commit'
);
$assertBefore(
    '$runtimeInvites->synchronize($data);',
    '$core[\'user\'] = $users->publicUser($user);',
    'Invite parity must be proven before the endpoint returns public state'
);

fwrite(STDOUT, "InviteRuntimeEndpointBridgeTest: {$assertions} assertions passed\n");
