<?php
declare(strict_types=1);

interface SelectiveReadStorageInterface
{
    /**
     * Execute one consistent read while loading only the requested top-level
     * storage sections.
     *
     * @param list<string> $sections
     */
    public function readOnlySections(array $sections, callable $callback): mixed;
}
