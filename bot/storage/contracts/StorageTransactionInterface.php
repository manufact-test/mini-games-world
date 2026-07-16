<?php
declare(strict_types=1);

interface StorageTransactionInterface
{
    /**
     * Execute a write transaction against one consistent storage snapshot.
     *
     * The callback receives the current data by reference. Changes are persisted
     * only when the callback returns successfully.
     */
    public function transaction(callable $callback): mixed;

    /**
     * Execute a shared-lock read against one consistent storage snapshot.
     */
    public function readOnly(callable $callback): mixed;
}
