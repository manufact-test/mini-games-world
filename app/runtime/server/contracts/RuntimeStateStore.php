<?php
declare(strict_types=1);

namespace Mgw\CleanRuntime\Server\Contracts;

interface RuntimeStateStore
{
    /**
     * @template T
     * @param callable(array<string,mixed>&):T $operation
     * @return T
     */
    public function transaction(callable $operation): mixed;

    /** @return array<string,mixed> */
    public function health(): array;
}
