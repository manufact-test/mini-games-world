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

fwrite(STDOUT, "ProfileV2ProductionPrimaryRoutingTest passed.\n");
