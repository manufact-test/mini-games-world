<?php
declare(strict_types=1);

require_once __DIR__ . '/FrozenSnapshotPrepareTrait.php';
require_once __DIR__ . '/FrozenSnapshotVerifyTrait.php';
require_once __DIR__ . '/FrozenSnapshotStateTrait.php';

final class FrozenSnapshotRehearsalService
{
    use FrozenSnapshotPrepareTrait;
    use FrozenSnapshotVerifyTrait;
    use FrozenSnapshotStateTrait;

    private const REQUIRED_MODULES = [
        'accounts', 'realtime', 'invites', 'notifications', 'economy',
        'history', 'shop', 'payments', 'weekly_bonus',
    ];

    public function __construct(
        private array $config,
        private SealedSnapshotControlService $sealControl,
        private BackupManager $backupManager,
        private RuntimeStorageRouter $router,
        private string $primaryRoot,
        private string $externalRoot,
        private string $restoreRoot,
        private string $stateFile
    ) {
        foreach (['primaryRoot', 'externalRoot', 'restoreRoot', 'stateFile'] as $property) {
            $this->{$property} = rtrim(str_replace('\\', '/', trim((string)$this->{$property})), '/');
            if ($this->{$property} === '') throw new InvalidArgumentException('Frozen snapshot path is required: ' . $property . '.');
        }
    }
}
