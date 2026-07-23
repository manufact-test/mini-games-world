<?php
declare(strict_types=1);

require_once __DIR__ . '/ProductionPrimaryRollbackExportBootstrap.php';
require_once __DIR__ . '/../core/RuntimeConfigLoader.php';
require_once __DIR__ . '/../core/DatabaseConfigLoader.php';
require_once __DIR__ . '/../storage/JsonDatabase.php';
require_once __DIR__ . '/../storage/JsonStorageAdapter.php';
require_once dirname(__DIR__, 2) . '/ops/backup/BackupManager.php';
require_once __DIR__ . '/ProductionPrimaryRollbackRestoreService.php';
require_once __DIR__ . '/ProductionPrimaryRollbackArtifactIdentity.php';
require_once __DIR__ . '/ProductionPrimaryLiveRollbackGate.php';
require_once __DIR__ . '/ProductionPrimaryRuntimeOverlayWriter.php';
require_once __DIR__ . '/ProductionPrimaryLiveRollbackStateStore.php';
require_once __DIR__ . '/ProductionPrimaryLiveRollbackInputLoader.php';
require_once __DIR__ . '/ProductionPrimaryLiveRollbackAuditorFactory.php';
require_once __DIR__ . '/ProductionPrimaryLiveRollbackService.php';
require_once __DIR__ . '/ProductionPrimaryLiveRollbackBootstrap.php';
