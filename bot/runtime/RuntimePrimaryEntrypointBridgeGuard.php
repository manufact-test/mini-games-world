<?php
declare(strict_types=1);

final class RuntimePrimaryEntrypointBridgeGuard
{
    public static function legacyJsonBridgeAllowed(): bool
    {
        $productionInstalled = class_exists(
            'ProductionPrimaryEntrypointStorageContext',
            false
        ) && ProductionPrimaryEntrypointStorageContext::installed();
        $stagingInstalled = class_exists(
            'RuntimePrimaryEntrypointStorageContext',
            false
        ) && RuntimePrimaryEntrypointStorageContext::installed();

        return !$productionInstalled && !$stagingInstalled;
    }
}
