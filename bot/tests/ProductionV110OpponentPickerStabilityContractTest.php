<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$runtime = $read('app/assets/js/production-v110-opponent-picker-stability.js');
$invites = $read('app/assets/js/games/game-invites-v110.js');

$assert(
    str_contains($shell, "production-v110-opponent-picker-stability.js?v=1118")
        && substr_count($shell, 'initV110OpponentPickerStability();') === 1
        && strpos($shell, 'initV110OpponentPickerStability();') < strpos($shell, 'initGameInvites();'),
    'The opponent cache must initialize exactly once before the canonical invite owner.'
);
$assert(
    str_contains($runtime, "OPPONENTS_PATH = '/bot/invite-opponents.php'")
        && str_contains($runtime, 'if (!isOpponentRequest(input)) return runtime.originalFetch(input, init);'),
    'The fetch wrapper must affect only the recent-opponents endpoint.'
);
$assert(
    str_contains($runtime, 'freshCachedItems()')
        && str_contains($runtime, 'return jsonResponse({ ok:true, items:cached });')
        && str_contains($runtime, 'void refreshFromNetwork'),
    'A fresh non-empty cache must paint immediately while one background refresh reconciles it.'
);
$assert(
    str_contains($runtime, 'EMPTY_RETRY_DELAYS_MS = [240, 680]')
        && str_contains($runtime, 'for (const delayMs of EMPTY_RETRY_DELAYS_MS)')
        && str_contains($runtime, 'if (hasItems(payload))'),
    'A transient empty response must be retried before the picker is allowed to render an empty state.'
);
$assert(
    str_contains($runtime, "document.addEventListener('mgw:app-ready'")
        && str_contains($runtime, 'schedulePrefetch(0)')
        && str_contains($runtime, 'PREFETCH_INTERVAL_MS = 12000'),
    'The picker cache must warm only after authenticated app readiness and refresh while visible.'
);
$assert(
    str_contains($runtime, 'CACHE_TTL_MS = 5 * 60 * 1000')
        && str_contains($runtime, 'cacheKey()')
        && str_contains($runtime, "new URLSearchParams(getInitData()).get('user')"),
    'Cached opponent lists must be bounded and scoped to the authenticated Telegram user.'
);
$assert(
    str_contains($invites, 'renderPlayerPicker(items, context);')
        && str_contains($invites, 'postJson(OPPONENTS_URL, {})'),
    'The canonical picker renderer and direct-invite action owner must remain unchanged.'
);

fwrite(STDOUT, "ProductionV110OpponentPickerStabilityContractTest: {$assertions} assertions passed\n");
