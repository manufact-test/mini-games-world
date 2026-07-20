<?php
declare(strict_types=1);

interface RuntimePrimaryRehearsalBackendInterface
{
    public function installSchemas(): array;
    public function synchronizeCurrentSnapshot(): array;
    public function runWorkerOnce(): array;
    public function status(): array;
    public function eventStatus(int $stateRevision): array;
}
