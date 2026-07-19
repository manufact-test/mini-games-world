<?php
declare(strict_types=1);

final class StagingRuntimeSwitchRollbackRehearsalRetryOperation
{
    private const BUILD = 'v101-mvp14-db-switch-rollback-rehearsal';
    private const OPERATION_ID = 'mvp-14.8.4k2-switch-rollback-rehearsal-v2';

    public function __construct(
        private array $config,
        private StorageAdapterInterface $storage,
        private DatabaseConnectionInterface $database,
        private string $privateDir
    ) {}

    public function definition(): StagingOperationDefinition
    {
        $inner = (new StagingRuntimeSwitchRollbackRehearsalOperation(
            $this->config,
            $this->storage,
            $this->database,
            $this->privateDir
        ))->definition();

        return new StagingOperationDefinition(
            self::OPERATION_ID,
            self::BUILD,
            static fn(): array => $inner->execute(),
            static fn(?array $result, ?Throwable $error): array => $inner->rollback($result, $error)
        );
    }
}
