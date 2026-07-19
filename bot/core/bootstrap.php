<?php
declare(strict_types=1);

define('MINIGAMES_INTERNAL', true);

require_once __DIR__ . '/Environment.php';
require_once __DIR__ . '/ConfigValidator.php';
require_once __DIR__ . '/RuntimeConfigLoader.php';
require_once __DIR__ . '/DatabaseConfigLoader.php';
require_once __DIR__ . '/RuntimeRequestGuard.php';

$externalConfigFile = getenv('MGW_CONFIG_FILE') ?: dirname(__DIR__, 3) . '/_private_mgw/config.php';
$legacyConfigFile = __DIR__ . '/../config/config.php';
$configFile = is_file($externalConfigFile) ? $externalConfigFile : $legacyConfigFile;

if (!is_file($configFile)) {
    throw new RuntimeException('Mini Games World config file not found. Expected external config at: ' . $externalConfigFile);
}

$config = require $configFile;
if (!is_array($config)) {
    throw new RuntimeException('Mini Games World config must return an array.');
}

$localConfigFile = __DIR__ . '/../config/config.local.php';
if (is_file($localConfigFile)) {
    $localConfig = require $localConfigFile;
    if (is_array($localConfig)) {
        $config = array_replace_recursive($config, $localConfig);
    }
}

$config = RuntimeConfigLoader::merge($config, $configFile);
$config = DatabaseConfigLoader::merge($config, $configFile);

// Weekly Match coins are a product-wide Moscow schedule. Older private configs
// copied the former Warsaw default, so migrate that legacy value safely here.
$weeklyTimezone = trim((string)($config['weekly_match_timezone'] ?? ''));
if ($weeklyTimezone === '' || $weeklyTimezone === 'Europe/Warsaw') {
    $config['weekly_match_timezone'] = 'Europe/Moscow';
}

if (empty($config['data_dir'])) {
    $externalDataDir = dirname(__DIR__, 3) . '/mgw_data';
    $config['data_dir'] = is_dir($externalDataDir) ? $externalDataDir : __DIR__ . '/../data';
}

$config = ConfigValidator::validate($config, $_SERVER);

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/validators.php';
require_once __DIR__ . '/../storage/contracts/StorageTransactionInterface.php';
require_once __DIR__ . '/../storage/contracts/StorageAdapterInterface.php';
require_once __DIR__ . '/../storage/JsonDatabase.php';
require_once __DIR__ . '/../storage/JsonStorageAdapter.php';
require_once __DIR__ . '/../storage/StorageFactory.php';
require_once __DIR__ . '/../storage/RuntimeStorageRouter.php';
require_once __DIR__ . '/../database/DatabaseConnectionInterface.php';
require_once __DIR__ . '/../database/DatabaseConfig.php';
require_once __DIR__ . '/../database/PdoDatabaseConnection.php';
require_once __DIR__ . '/../database/PdoConnectionFactory.php';
require_once __DIR__ . '/../database/DatabaseMigrationInterface.php';
require_once __DIR__ . '/../database/MigrationRepository.php';
require_once __DIR__ . '/../database/MigrationRunner.php';
require_once __DIR__ . '/../database/ManagedMigrationConfig.php';
require_once __DIR__ . '/../database/ManagedMigrationController.php';
require_once __DIR__ . '/../accounts/MgwIdGenerator.php';
require_once __DIR__ . '/../accounts/AccountIdentityService.php';
require_once __DIR__ . '/../accounts/RuntimeAccountOwnershipService.php';
require_once __DIR__ . '/../accounts/RuntimeAccountIdentityResolver.php';
require_once __DIR__ . '/../realtime/RealtimeDatabaseStore.php';
require_once __DIR__ . '/../realtime/RuntimeRealtimeRepository.php';
require_once __DIR__ . '/../realtime/LegacyRealtimeShadowSyncService.php';
require_once __DIR__ . '/../realtime/RealtimeRuntimeBridge.php';
require_once __DIR__ . '/../notifications/RuntimeNotificationRepository.php';
require_once __DIR__ . '/../invites/RuntimeInviteRepository.php';
require_once __DIR__ . '/../ledger/LedgerIntegrity.php';
require_once __DIR__ . '/../ledger/LedgerWriteService.php';
require_once __DIR__ . '/../ledger/LedgerIntegrityVerifier.php';
require_once __DIR__ . '/../ledger/LegacyEconomyShadowSyncService.php';
require_once __DIR__ . '/../ledger/LegacyEconomyDeltaImportService.php';
require_once __DIR__ . '/../ledger/LegacyEconomyRuntimeReconciliationService.php';
require_once __DIR__ . '/../ledger/RuntimeEconomySnapshotStorage.php';
require_once __DIR__ . '/../ledger/RuntimeEconomyBalanceBootstrapService.php';
require_once __DIR__ . '/../ledger/RuntimeEconomyRepository.php';
require_once __DIR__ . '/../ledger/EconomyRuntimeBridge.php';
require_once __DIR__ . '/../ledger/LegacyFinancialStatusNormalizer.php';
require_once __DIR__ . '/../ledger/LegacyFinancialArchiveImportService.php';
require_once __DIR__ . '/../ledger/LegacyFinancialArchiveDeltaService.php';
require_once __DIR__ . '/../history/RuntimeHistoryRepository.php';
require_once __DIR__ . '/../shop/RuntimeShopSchemaInstaller.php';
require_once __DIR__ . '/../shop/RuntimeShopRepository.php';
require_once __DIR__ . '/../shop/ShopRuntimeBridge.php';
require_once __DIR__ . '/../payments/RuntimePaymentSchemaInstaller.php';
require_once __DIR__ . '/../payments/RuntimePaymentRepository.php';
require_once __DIR__ . '/../payments/PaymentRuntimeBridge.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../services/UserService.php';
require_once __DIR__ . '/../services/FeatureFlagService.php';
require_once __DIR__ . '/../services/GameCatalogService.php';
require_once __DIR__ . '/../services/GameService.php';
require_once __DIR__ . '/../services/GameSettlementService.php';
require_once __DIR__ . '/../services/FourInARowBotService.php';
require_once __DIR__ . '/../services/FourInARowService.php';
require_once __DIR__ . '/../services/GameRuntimeService.php';
require_once __DIR__ . '/../services/ChessRuntimeService.php';
require_once __DIR__ . '/../services/GameActionService.php';
require_once __DIR__ . '/../services/StatsService.php';
require_once __DIR__ . '/../services/HistoryService.php';
require_once __DIR__ . '/../services/ShopCatalogService.php';
require_once __DIR__ . '/../services/ShopService.php';
require_once __DIR__ . '/../services/PaymentService.php';
require_once __DIR__ . '/../services/SessionService.php';
require_once __DIR__ . '/../services/AdminService.php';
require_once __DIR__ . '/../services/TelegramService.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../services/WeeklyMatchEconomyService.php';
require_once __DIR__ . '/../services/ShopOrderNotificationService.php';
require_once __DIR__ . '/../handlers/WebhookHandler.php';

// Validate the staged DB routing contract on every boot. JSON remains the
// global rollback source while individual modules prove DB parity in staging.
$runtimeStorageRouter = new RuntimeStorageRouter($config);
$runtimeRealtimeBridge = new RealtimeRuntimeBridge($config, $runtimeStorageRouter);
$runtimeEconomyBridge = new EconomyRuntimeBridge($config, $runtimeStorageRouter);
$runtimeShopBridge = new ShopRuntimeBridge($config, $runtimeStorageRouter);
$runtimePaymentBridge = new PaymentRuntimeBridge($config, $runtimeStorageRouter);
$runtimeScript = basename(trim((string)($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['PHP_SELF'] ?? '')));
$runtimeApiSuccessHooks = [];

if ($runtimeRealtimeBridge->shouldAttachToCurrentRequest($_SERVER)) {
    $runtimeApiSuccessHooks[] = static function () use ($runtimeRealtimeBridge): void {
        $runtimeRealtimeBridge->synchronizeCurrentJson();
    };
}
if ($runtimeScript === 'api.php' && $runtimeEconomyBridge->shouldAttachToCurrentRequest($_SERVER)) {
    $runtimeApiSuccessHooks[] = static function () use ($runtimeEconomyBridge): void {
        $runtimeEconomyBridge->synchronizeCurrentJson();
    };
}
if ($runtimeScript === 'api.php' && $runtimeShopBridge->shouldAttachToCurrentRequest($_SERVER)) {
    $runtimeApiSuccessHooks[] = static function () use ($runtimeShopBridge): void {
        $action = (string)($GLOBALS['mgw_api_action'] ?? '');
        if ($runtimeShopBridge->shouldSynchronizeApiAction($action)) {
            $runtimeShopBridge->synchronizeCurrentJson();
        }
    };
}
if ($runtimeScript === 'api.php' && $runtimePaymentBridge->shouldAttachToCurrentRequest($_SERVER)) {
    $runtimeApiSuccessHooks[] = static function () use ($runtimePaymentBridge): void {
        $action = (string)($GLOBALS['mgw_api_action'] ?? '');
        if ($runtimePaymentBridge->shouldSynchronizeApiAction($action)) {
            $runtimePaymentBridge->synchronizeCurrentJson();
        }
    };
    $runtimeApiDataFilters = $GLOBALS['mgw_api_data_filters'] ?? [];
    if (!is_array($runtimeApiDataFilters)) $runtimeApiDataFilters = [];
    $runtimeApiDataFilters[] = static function (array $data) use ($runtimePaymentBridge): array {
        return $runtimePaymentBridge->normalizeApiData(
            $data,
            (string)($GLOBALS['mgw_api_action'] ?? '')
        );
    };
    $GLOBALS['mgw_api_data_filters'] = $runtimeApiDataFilters;
}
if ($runtimeApiSuccessHooks !== []) {
    $GLOBALS['mgw_api_success_hooks'] = $runtimeApiSuccessHooks;
}

$runtimeWebhookSuccessHooks = [];
if ($runtimeScript === 'webhook.php' && $runtimeEconomyBridge->shouldAttachToCurrentRequest($_SERVER)) {
    $runtimeWebhookSuccessHooks[] = static function () use ($runtimeEconomyBridge): void {
        $runtimeEconomyBridge->synchronizeCurrentJson();
    };
}
if ($runtimeScript === 'webhook.php' && $runtimeShopBridge->shouldAttachToCurrentRequest($_SERVER)) {
    $runtimeWebhookSuccessHooks[] = static function () use ($runtimeShopBridge): void {
        $runtimeShopBridge->synchronizeCurrentJson();
    };
}
if ($runtimeScript === 'webhook.php' && $runtimePaymentBridge->shouldAttachToCurrentRequest($_SERVER)) {
    $runtimeWebhookSuccessHooks[] = static function () use ($runtimePaymentBridge): void {
        $runtimePaymentBridge->synchronizeCurrentJson();
    };
}
if ($runtimeWebhookSuccessHooks !== []) {
    $GLOBALS['mgw_webhook_success_hook'] = static function () use ($runtimeWebhookSuccessHooks): void {
        foreach ($runtimeWebhookSuccessHooks as $hook) $hook();
    };
}

RuntimeRequestGuard::enforce($config, $_SERVER);
