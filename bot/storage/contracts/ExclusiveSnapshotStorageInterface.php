<?php
declare(strict_types=1);

interface ExclusiveSnapshotStorageInterface
{
    public function exclusiveReadOnly(callable $callback): mixed;

    /** @param list<string> $sections */
    public function exclusiveReadOnlySections(array $sections, callable $callback): mixed;
}
