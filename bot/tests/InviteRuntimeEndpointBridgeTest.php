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
$assertNotContains = static function (string $needle, string $message) use (&$assertions, $source): void {
    $assertions++;
    if (str_contains($source, $needle)) {
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
    '$db instanceof ExclusiveSnapshotStorageInterface',
    'Invite endpoint must require the explicit exclusive snapshot capability'
);
$assertContains(
    '$db->exclusiveReadOnlySections(',
    'Invite endpoint must keep the JSON writer lock while DB mirroring consumes the snapshot'
);
$assertContains(
    "['invites']",
    'Invite bridge must decode only the invites section'
);
$assertContains(
    'static fn(array $data): array => $runtimeInvites->synchronize($data)',
    'Invite endpoint must synchronize the locked committed JSON snapshot to DB'
);
$assertNotContains(
    '$snapshot = $db->readOnly(static fn(array $data): array => $data);',
    'Invite endpoint must not restore the detached snapshot race'
);
$assertBefore(
    '$result = $db->transaction(',
    '$db->exclusiveReadOnlySections(',
    'JSON invite state must commit before the serialized DB mirror starts'
);
$assertBefore(
    '$db->exclusiveReadOnlySections(',
    '$result[\'invite\'][\'share_url\'] = $shareUrl;',
    'Invite parity must be proven before external share preparation'
);

fwrite(STDOUT, "InviteRuntimeEndpointBridgeTest: {$assertions} assertions passed\n");
