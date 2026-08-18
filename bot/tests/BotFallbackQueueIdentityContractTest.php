<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = file_get_contents($root . '/api.php');
$searchSpeed = file_get_contents($root . '/search-speed.php');
$runtime = file_get_contents($root . '/services/ChessRuntimeService.php');
$queuePolicy = file_get_contents($root . '/services/MatchmakingQueue.php');
$configLoader = file_get_contents($root . '/core/RuntimeConfigLoader.php');
$identity = file_get_contents($root . '/realtime/RuntimeRealtimeIdentityTrait.php');

if (!is_string($api)
    || !is_string($searchSpeed)
    || !is_string($runtime)
    || !is_string($queuePolicy)
    || !is_string($configLoader)
    || !is_string($identity)) {
    throw new RuntimeException('Bot fallback queue identity sources are unavailable.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($searchSpeed, '$db->readOnly(') && !str_contains($searchSpeed, '$db->transaction('),
    'Search speed checkpoint must remain read-only after queue publication.'
);
$assert(
    !str_contains($searchSpeed, "\$item['created_at'] =")
        && !str_contains($searchSpeed, "\$item['status'] =")
        && !str_contains($searchSpeed, "\$item['updated_at'] ="),
    'Search speed checkpoint must not mutate realtime queue fields.'
);
$assert(
    !str_contains($api, 'function mgw_prepare_match_bot_fallback(')
        && !str_contains($api, "gmdate('c', time() - 2)")
        && !str_contains($api, "gmdate('c', time() - 12)"),
    'Authoritative start_search must preserve the real immutable queue creation time.'
);
$assert(
    str_contains($configLoader, "\$config['match_bot_after_sec'] = 8;"),
    'Runtime config must normalize the canonical eight-second human-priority gate server-side.'
);
$assert(
    str_contains($queuePolicy, 'public const HUMAN_PRIORITY_SEC = 8;')
        && str_contains($queuePolicy, 'public const SKILL_WIDEN_STEP_SEC = 2;')
        && str_contains($queuePolicy, 'public const MAX_SKILL_BAND_DISTANCE = 3;'),
    'MatchmakingQueue must own the bounded human-priority and widening policy.'
);
$assert(
    !str_contains($runtime, 'bot_fallback_5s')
        && !str_contains($runtime, "\$runtimeConfig['match_bot_after_sec'] = 5;"),
    'Special-game runtime must not reintroduce the removed accelerated fallback owner.'
);
$assert(
    str_contains($runtime, '$this->matchmaking->matchesKey(')
        && str_contains($runtime, "\$item['skill_band'] = \$this->matchmaking->normalizeSkillBand"),
    'Chess, Go and Domino must use the same platform-neutral matchmaking key as the base runtime.'
);
$assert(
    str_contains($identity, "'created_at_utc'"),
    'Realtime parity must continue treating queue creation time as immutable identity.'
);

fwrite(STDOUT, "BotFallbackQueueIdentityContractTest: {$assertions} assertions passed\n");
