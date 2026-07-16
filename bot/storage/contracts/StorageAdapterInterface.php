<?php
declare(strict_types=1);

interface StorageAdapterInterface extends StorageTransactionInterface
{
    /**
     * Stable driver identifier used by health checks, migrations and rollback.
     */
    public function driver(): string;
}
