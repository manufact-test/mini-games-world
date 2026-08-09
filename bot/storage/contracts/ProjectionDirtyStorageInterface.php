<?php
declare(strict_types=1);

interface ProjectionDirtyStorageInterface
{
    public function runtimeProjectionDirty(): bool;

    /**
     * Clear the durable projection marker only while the caller owns the
     * storage's exclusive frozen-snapshot boundary.
     */
    public function clearRuntimeProjectionDirty(): void;
}
