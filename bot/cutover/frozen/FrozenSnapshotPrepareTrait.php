<?php
declare(strict_types=1);

require_once __DIR__ . '/FrozenSnapshotPrepareActionTrait.php';
require_once __DIR__ . '/FrozenSnapshotReportBuildTrait.php';
require_once __DIR__ . '/FrozenSnapshotStatusActionTrait.php';

trait FrozenSnapshotPrepareTrait
{
    use FrozenSnapshotPrepareActionTrait;
    use FrozenSnapshotReportBuildTrait;
    use FrozenSnapshotStatusActionTrait;
}
