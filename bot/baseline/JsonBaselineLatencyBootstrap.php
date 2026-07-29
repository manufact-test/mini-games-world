<?php
declare(strict_types=1);

$mgwLatencyRoot = dirname(__DIR__, 2);

require_once $mgwLatencyRoot . '/bot/helpers/validators.php';
require_once $mgwLatencyRoot . '/bot/baseline/JsonBehaviorBaselineNormalizer.php';
require_once $mgwLatencyRoot . '/bot/baseline/JsonBehaviorBaselineFixture.php';
require_once $mgwLatencyRoot . '/bot/baseline/JsonBehaviorBaselineResult.php';

require_once $mgwLatencyRoot . '/bot/services/UserService.php';
require_once $mgwLatencyRoot . '/bot/services/SessionService.php';
require_once $mgwLatencyRoot . '/bot/services/NotificationService.php';

require_once $mgwLatencyRoot . '/bot/baseline/JsonAccountPassiveBaselineScenario.php';
require_once $mgwLatencyRoot . '/bot/baseline/JsonInviteMatchmakingBaselineScenario.php';
require_once $mgwLatencyRoot . '/bot/baseline/JsonGamesBaselineScenario.php';
require_once $mgwLatencyRoot . '/bot/baseline/JsonEconomySupportingBaselineScenario.php';

require_once $mgwLatencyRoot . '/bot/baseline/JsonBaselineScenarioCatalog.php';
require_once $mgwLatencyRoot . '/bot/baseline/JsonBaselineLatencyRunner.php';

unset($mgwLatencyRoot);
