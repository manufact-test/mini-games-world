<?php
declare(strict_types=1);

interface ProjectionSnapshotStorageInterface
{
    public function projectionReadOnly(callable $callback): mixed;

    /**
     * @param list<string> $sections
     */
    public function projectionReadOnlySections(array $sections, callable $callback): mixed;
}
