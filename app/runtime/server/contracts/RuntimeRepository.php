<?php
declare(strict_types=1);

namespace Mgw\CleanRuntime\Server\Contracts;

interface RuntimeRepository
{
    /**
     * @param array<string,mixed> $identity
     * @param array{runtime:string,path:string,source:string,invite_present:bool,telegram_available:bool} $launch
     * @param array{visibility:string,platform:string,timezone_offset:int} $presence
     * @return array<string,mixed>
     */
    public function bootstrap(
        array $identity,
        string $installationId,
        string $sessionId,
        array $launch,
        array $presence,
        int $sessionTimeoutSec,
        int $presenceTtlSec,
    ): array;

    /**
     * @param array<string,mixed> $identity
     * @param array{visibility:string,platform:string,timezone_offset:int} $presence
     * @return array<string,mixed>
     */
    public function heartbeat(
        array $identity,
        string $installationId,
        string $sessionId,
        array $presence,
        int $sessionTimeoutSec,
        int $presenceTtlSec,
    ): array;

    /** @return array<string,mixed> */
    public function health(): array;
}
