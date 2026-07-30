<?php
declare(strict_types=1);

namespace Mgw\CleanRuntime\Server\Contracts;

interface RuntimeRepository
{
    /**
     * @param array{runtime:string,path:string,source:string,invite_present:bool,telegram_available:bool} $launch
     * @return array<string,mixed>
     */
    public function bootstrap(string $installationId, array $launch): array;

    /** @return array<string,mixed> */
    public function health(): array;
}
