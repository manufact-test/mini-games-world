<?php
declare(strict_types=1);

require_once __DIR__ . '/../database/DatabaseConnectionInterface.php';
require_once __DIR__ . '/../database/DatabaseConfig.php';
require_once __DIR__ . '/../database/PdoDatabaseConnection.php';
require_once __DIR__ . '/../database/PdoConnectionFactory.php';
require_once __DIR__ . '/../storage/RuntimeStorageRouter.php';
require_once __DIR__ . '/RuntimePrimaryStateSchemaInstaller.php';
require_once __DIR__ . '/RuntimePrimaryProjectionOutboxSchemaInstaller.php';
require_once __DIR__ . '/RuntimePrimaryProjectionAuditorInterface.php';
require_once __DIR__ . '/RuntimePrimaryProjectionProjectorInterface.php';
require_once __DIR__ . '/RuntimePrimaryModuleProjectorInterface.php';
require_once __DIR__ . '/RuntimePrimaryCallbackModuleProjector.php';
require_once __DIR__ . '/RuntimePrimaryAccountsModuleProjector.php';
require_once __DIR__ . '/RuntimePrimaryAllModuleProjector.php';
require_once __DIR__ . '/RuntimePrimaryProjectionAuditorAdapter.php';

require_once __DIR__ . '/../accounts/MgwIdGenerator.php';
require_once __DIR__ . '/../accounts/AccountIdentityService.php';
require_once __DIR__ . '/../accounts/RuntimeAccountOwnershipService.php';
require_once __DIR__ . '/../accounts/RuntimeAccountIdentityResolver.php';
require_once __DIR__ . '/../realtime/RealtimeDatabaseStore.php';
require_once __DIR__ . '/../realtime/RuntimeRealtimeRepository.php';
require_once __DIR__ . '/../realtime/LegacyRealtimeShadowSyncService.php';
require_once __DIR__ . '/../notifications/RuntimeNotificationRepository.php';
require_once __DIR__ . '/../invites/RuntimeInviteRepository.php';
require_once __DIR__ . '/../ledger/LedgerIntegrity.php';
require_once __DIR__ . '/../ledger/LedgerWriteService.php';
require_once __DIR__ . '/../ledger/LedgerIntegrityVerifier.php';
require_once __DIR__ . '/../ledger/LegacyEconomyShadowSyncService.php';
require_once __DIR__ . '/../ledger/RuntimeEconomySnapshotStorage.php';
require_once __DIR__ . '/../ledger/RuntimeEconomyRepository.php';
require_once __DIR__ . '/../history/RuntimeHistoryRepository.php';
require_once __DIR__ . '/../shop/RuntimeShopSchemaInstaller.php';
require_once __DIR__ . '/../shop/RuntimeShopRepository.php';
require_once __DIR__ . '/../payments/RuntimePaymentSchemaInstaller.php';
require_once __DIR__ . '/../payments/RuntimePaymentRepository.php';
require_once __DIR__ . '/../weekly/RuntimeWeeklyBonusSchemaInstaller.php';
require_once __DIR__ . '/../weekly/RuntimeWeeklyBonusRepository.php';
require_once __DIR__ . '/RuntimePrimaryRepositoryProjectorFactory.php';

require_once __DIR__ . '/ProductionPrimaryRollbackExportGate.php';
require_once __DIR__ . '/ProductionPrimaryRollbackExportVerifier.php';
require_once __DIR__ . '/ProductionPrimaryRollbackExportService.php';
require_once __DIR__ . '/ProductionPrimaryRollbackAuditorFactory.php';
require_once __DIR__ . '/ProductionPrimaryRollbackExportInputLoader.php';
