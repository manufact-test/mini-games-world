<?php
declare(strict_types=1);

require_once __DIR__ . '/SealedSnapshotEnvironmentTrait.php';
require_once __DIR__ . '/SealedSnapshotControlReadTrait.php';
require_once __DIR__ . '/SealedSnapshotControlWriteTrait.php';
require_once __DIR__ . '/SealedSnapshotWriteBlockTrait.php';
require_once __DIR__ . '/SealedSnapshotReportTrait.php';

trait SealedSnapshotStorageTrait
{
    use SealedSnapshotEnvironmentTrait;
    use SealedSnapshotControlReadTrait;
    use SealedSnapshotControlWriteTrait;
    use SealedSnapshotWriteBlockTrait;
    use SealedSnapshotReportTrait;
}
