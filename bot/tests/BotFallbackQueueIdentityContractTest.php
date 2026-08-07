<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = file_get_contents($root . '/api.php');
$searchSpeed = file_get_contents($root . '/search-speed.php');
$runtime = file_get_contents($root . '/services/ChessRuntimeService.php');
$identity = file_get_contents($root . '/realtime/RuntimeRealtimeIdentityTrait.php');

if (!is_string($api) || !is_string($searchSpeed) || !is_string($runtime) || !is_string($identity)) {
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
    str_contains($api, 'function mgw_prepare_match_bot_fallback('),
    'Bot fallback policy must be prepared by the authoritative start_search owner.'
);
$assert(
    str_contains($api, "\$item['created_at'] = gmdate('c', time() - 2);")
        && str_contains($api, "\$item['status'] = 'bot_fallback_5s';"),
    'Human-present searches must publish stable queue identity with the bounded fallback policy before first projection.'
);
$assert(
    str_contains($api, "\$item['created_at'] = gmdate('c', time() - 12);"),
    'No-human searches must preserve the established three-second fallback preparation before first projection.'
);
$assert(
    str_contains($runtime, "=== 'bot_fallback_5s'")
        && str_contains($runtime, "\$runtimeConfig['match_bot_after_sec'] = 5;"),
    'Runtime must honor the pre-published bounded five-second fallback policy.'
);
$assert(
    str_contains($identity, "'created_at_utc'"),
    'Realtime parity must continue treating queue creation time as immutable identity.'
);

fwrite(STDOUT, "BotFallbackQueueIdentityContractTest: {$assertions} assertions passed\n");
