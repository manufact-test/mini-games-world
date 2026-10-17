<?php
declare(strict_types=1);

// Isolated bridge action routing test; no database or staging player mutation.
class DatabaseConfig {
    public static function fromApplicationConfig(array $config): self { return new self(); }
    public function enabled(): bool { return true; }
}
class RuntimeStorageRouter {
    public const DRIVER_DATABASE = 'database';
    public function routeFor(string $module): string { return self::DRIVER_DATABASE; }
}
interface DatabaseConnectionInterface {}
require dirname(__DIR__) . '/ratings/HiddenSkillRuntimeBridge.php';

$bridge = new HiddenSkillRuntimeBridge([], new RuntimeStorageRouter());
foreach (['game_action','game_state','make_move','leave_game','bootstrap','profile','history'] as $action) {
    if ($bridge->shouldProcessApiAction($action)) {
        throw new RuntimeException('Latency-critical request must not run hidden-skill MySQL catch-up: ' . $action);
    }
}
if (!$bridge->shouldProcessApiAction('start_search')) {
    throw new RuntimeException('Next matchmaking search must retain hidden-skill catch-up.');
}
if ($bridge->shouldProcessApiAction('START_SEARCH') !== true) {
    throw new RuntimeException('Action matching must remain case-insensitive.');
}
echo "MGW_HIDDEN_SKILL_MATCH_ONLY=PASS\n";
