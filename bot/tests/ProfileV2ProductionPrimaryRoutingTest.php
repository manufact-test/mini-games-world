<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/bot/runtime/ProductionPrimaryApplicationEntrypoints.php';

$resolved = ProductionPrimaryApplicationEntrypoints::resolve(
    $projectRoot,
    ['SCRIPT_FILENAME' => $projectRoot . '/bot/profile-v2.php']
);

if ($resolved !== 'api') {
    throw new RuntimeException(
        'Profile V2 must reuse the production API DB-primary context; resolved: ' . var_export($resolved, true)
    );
}

$storageFactory = file_get_contents($projectRoot . '/bot/storage/StorageFactory.php');
if (!is_string($storageFactory)
    || !str_contains($storageFactory, "'api.php', 'admin-read.php', 'profile-v2.php' => 'api'")) {
    throw new RuntimeException(
        'Profile V2 must also reuse the API DB-primary context on staging.'
    );
}

fwrite(STDOUT, "ProfileV2PrimaryRoutingTest passed.\n");
