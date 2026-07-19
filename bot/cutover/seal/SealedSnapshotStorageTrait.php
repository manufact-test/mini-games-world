<?php
declare(strict_types=1);

require_once __DIR__ . '/SealedSnapshotControlFileTrait.php';
require_once __DIR__ . '/SealedSnapshotWriteBlockTrait.php';

trait SealedSnapshotStorageTrait
{
    use SealedSnapshotControlFileTrait;
    use SealedSnapshotWriteBlockTrait;
}
