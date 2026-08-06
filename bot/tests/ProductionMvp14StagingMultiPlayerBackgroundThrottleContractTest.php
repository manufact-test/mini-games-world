<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) {
        throw new RuntimeException('Cannot read multi-player E2E source: ' . $path);
    }
    return $content;
};

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$config = $read('e2e/playwright.config.mjs');
$scenario = $read('e2e/staging/two-context.spec.mjs');

$assert(str_contains($config, 'launchOptions: {')
    && str_contains($config, "'--disable-background-timer-throttling'")
    && str_contains($config, "'--disable-backgrounding-occluded-windows'")
    && str_contains($config, "'--disable-renderer-backgrounding'"),
    'Chromium must keep both independent player pages active during sequential multi-player E2E.');

$assert(str_contains($config, 'fullyParallel: false')
    && str_contains($config, 'workers: 1')
    && str_contains($config, 'retries: 0'),
    'The staging suite must remain one exact sequential run without retry masking.');

$assert(str_contains($scenario, "const turnId = String(statePayload.game?.turn || '')")
    && str_contains($scenario, 'const actor = playersById[turnId]')
    && str_contains($scenario, 'finalPayload = await playTicTacToeCell(actor, cell)'),
    'Every Tic Tac Toe move must still use the authoritative server turn owner.');

$assert(str_contains($scenario, 'async function playTicTacToeCell(player, cell)')
    && str_contains($scenario, '#screen-game.active [data-game-cell=')
    && str_contains($scenario, 'await expect(locator).toBeEnabled({ timeout: 25_000 })')
    && str_contains($scenario, "isActionResponse(API_ROUTE, 'game_action')")
    && str_contains($scenario, 'await locator.click()'),
    'The match must still complete through a visible enabled UI cell and a real game_action response.');

$assert(!str_contains($scenario, "postFromPlayer(player.page, '/bot/api.php', { action: 'game_action'"),
    'The test must not bypass the UI by posting a game move directly.');

fwrite(STDOUT, 'ProductionMvp14StagingMultiPlayerBackgroundThrottleContractTest: ' . $assertions . " assertions passed\n");
