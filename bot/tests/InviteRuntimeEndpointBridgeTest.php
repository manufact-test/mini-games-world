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
    '$runtimeStorageRouter->routeFor(\'invites\') === RuntimeStorageRouter::DRIVER_DATABASE',
    'Invite endpoint must activate the DB bridge only through the runtime router'
);
$assertContains(
    'new RuntimeInviteRepository($config, $runtimeStorageRouter)',
    'Invite endpoint must construct the staged repository with the validated router'
);
$assertContains(
    '$snapshot = $db->readOnly(static fn(array $data): array => $data);',
    'Invite endpoint must reload the committed JSON rollback snapshot'
);
$assertContains(
    '$runtimeInvites->synchronize($snapshot);',
    'Invite endpoint must synchronize the committed JSON snapshot to DB'
);
$assertBefore(
    '$result = $db->transaction(',
    '$runtimeInvites->synchronize($snapshot);',
    'JSON invite state must commit before DB mirroring starts'
);
$assertBefore(
    '$runtimeInvites->synchronize($snapshot);',
    '$result[\'invite\'][\'share_url\'] = $shareUrl;',
    'Invite parity must be proven before external share preparation'
);

fwrite(STDOUT, "InviteRuntimeEndpointBridgeTest: {$assertions} assertions passed\n");
