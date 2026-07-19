<?php
declare(strict_types=1);

require_once __DIR__ . '/FrozenSnapshotPairTrait.php';
require_once __DIR__ . '/FrozenSnapshotDataTrait.php';
require_once __DIR__ . '/FrozenSnapshotCleanupTrait.php';

trait FrozenSnapshotVerifyTrait
{
    use FrozenSnapshotPairTrait;
    use FrozenSnapshotDataTrait;
    use FrozenSnapshotCleanupTrait;
}
