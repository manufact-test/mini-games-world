<?php
declare(strict_types=1);

final class RuntimePrimaryEntrypointBridgeGuard
{
    public static function legacyJsonBridgeAllowed(): bool
    {
        return !class_exists('RuntimePrimaryEntrypointStorageContext', false)
            || !RuntimePrimaryEntrypointStorageContext::installed()
            ? (
                !class_exists('ProductionPrimaryEntrypointStorageContext', false)
                || !ProductionPrimaryEntrypointStorageContext::installed()
            )
            : false;
    }
}
