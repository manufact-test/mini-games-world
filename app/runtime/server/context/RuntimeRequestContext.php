<?php
declare(strict_types=1);

namespace Mgw\CleanRuntime\Server\Context;

final readonly class RuntimeRequestContext
{
    /**
     * @param array<string,mixed> $identity
     * @param array{visibility:string,platform:string,timezone_offset:int} $presence
     */
    public function __construct(
        public array $identity,
        public string $installationId,
        public string $sessionId,
        public array $presence,
    ) {}

    public function accountId(): string
    {
        return (string)$this->identity['id'];
    }
}
