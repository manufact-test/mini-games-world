<?php
declare(strict_types=1);

define('MINIGAMES_INTERNAL', true);

$config = require __DIR__ . '/../config/config.php';

$localConfigFile = __DIR__ . '/../config/config.local.php';
if (is_file($localConfigFile)) {
    $localConfig = require $localConfigFile;
    if (is_array($localConfig)) {
        $config = array_replace_recursive($config, $localConfig);
    }
}

if (empty($config['data_dir'])) {
    $externalDataDir = dirname(__DIR__, 3) . '/mgw_data';
    $config['data_dir'] = is_dir($externalDataDir) ? $externalDataDir : __DIR__ . '/../data';
}

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/validators.php';
require_once __DIR__ . '/../storage/JsonDatabase.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../services/UserService.php';
require_once __DIR__ . '/../services/GameService.php';
require_once __DIR__ . '/../services/StatsService.php';
require_once __DIR__ . '/../services/HistoryService.php';
require_once __DIR__ . '/../services/ShopService.php';
require_once __DIR__ . '/../services/PaymentService.php';
require_once __DIR__ . '/../services/SessionService.php';
require_once __DIR__ . '/../services/AdminService.php';
require_once __DIR__ . '/../services/TelegramService.php';
require_once __DIR__ . '/../handlers/WebhookHandler.php';
