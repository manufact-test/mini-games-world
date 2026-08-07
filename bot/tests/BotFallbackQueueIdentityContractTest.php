<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$searchSpeed = file_get_contents($root . '/search-speed.php');
$runtime = file_get_contents($root . '/services/ChessRuntimeService.php');
$identity = file_get_contents($root . '/realtime/RuntimeRealtimeIdentityTrait.php');

if (!is_string($searchSpeed) || !is_string($runtime) || !is_string($identity)) {
    throw new RuntimeException('Bot fallback queue identity sources are unavailable.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    !str_contains($searchSpeed, "\$item['created_at'] ="),
    'Search speed checkpoint must not rewrite immutable queue creation time.'
);
$assert(
    str_contains($searchSpeed, "\$item['status'] = 'bot_fallback_5s';"),
    'Search speed checkpoint must use the mutable queue status for acceleration.'
);
$assert(
    str_contains($searchSpeed, "\$item['updated_at'] = now_iso();"),
    'Search speed status transition must advance the mutable queue timestamp.'
);
$assert(
    str_contains($runtime, "=== 'bot_fallback_5s'"),
    'Runtime must recognize the accelerated queue status.'
);
$assert(
    str_contains($runtime, "\$runtimeConfig['match_bot_after_sec'] = 5;"),
    'Accelerated queue status must use the existing bounded five-second bot threshold.'
);
$assert(
    str_contains($identity, "'created_at_utc'"),
    'Realtime parity must continue treating queue creation time as immutable identity.'
);

fwrite(STDOUT, "BotFallbackQueueIdentityContractTest: {$assertions} assertions passed\n");
