<?php
declare(strict_types=1);

define('MINIGAMES_INTERNAL', true);

require_once __DIR__ . '/Environment.php';
require_once __DIR__ . '/ConfigValidator.php';
require_once __DIR__ . '/RuntimeConfigLoader.php';
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
require_once __DIR__ . '/../database/DatabaseConnectionInterface.php';
require_once __DIR__ . '/../database/DatabaseConfig.php';
require_once __DIR__ . '/../database/PdoDatabaseConnection.php';
require_once __DIR__ . '/../database/PdoConnectionFactory.php';
require_once __DIR__ . '/../database/DatabaseMigrationInterface.php';
require_once __DIR__ . '/../database/MigrationRepository.php';
require_once __DIR__ . '/../database/MigrationRunner.php';
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

RuntimeRequestGuard::enforce($config, $_SERVER);
