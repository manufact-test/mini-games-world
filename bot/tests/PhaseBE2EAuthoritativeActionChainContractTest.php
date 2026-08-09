<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$spec = file_get_contents($root . '/e2e/staging/two-context.spec.mjs');
$config = file_get_contents($root . '/e2e/playwright.config.mjs');
if (!is_string($spec) || !is_string($config)) {
    throw new RuntimeException('Staging E2E sources are unavailable.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    !str_contains($spec, 'Game state before cell ${cell}'),
    'The final Tic Tac Toe scenario must not re-read game_state before every move.'
);
$assert(
    str_contains($spec, "expect(authoritativeGame, 'Authoritative launch snapshot must be available').toBeTruthy();")
        && str_contains($spec, 'return authoritativeGame;'),
    'The launch helper must return the server-authoritative active snapshot.'
);
$assert(
    str_contains($spec, 'let authoritativeGame = await waitForAuthoritativeTicTacToeLaunch(playerA, playerB, gameId);'),
    'The move chain must start from the authoritative launch snapshot.'
);
$assert(
    str_contains($spec, "const turnId = String(authoritativeGame?.turn || '');"),
    'Each move must select the actor from the latest authoritative game payload.'
);
$assert(
    str_contains($spec, "expect(String(finalPayload.game?.id || '')")
        && str_contains($spec, 'Authoritative game id after cell ${cell}')
        && str_contains($spec, ').toBe(gameId);'),
    'Each game_action response must be proven to belong to the expected match.'
);
$assert(
    str_contains($spec, 'authoritativeGame = finalPayload.game;'),
    'The next turn must chain directly from the authoritative game_action response.'
);
$assert(
    str_contains($spec, "expect(finalPayload?.game?.status).toBe('finished');")
        && str_contains($spec, 'expect(winnerDelta).toBe(8);')
        && str_contains($spec, 'expect(loserDelta).toBe(-10);'),
    'Existing match completion and economy assertions must remain present.'
);
$assert(str_contains($config, 'timeout: 90_000'), 'Per-test Playwright timeout must remain 90 seconds.');
$assert(str_contains($config, 'retries: 0'), 'Staging Playwright retries must remain disabled.');

fwrite(STDOUT, "PhaseBE2EAuthoritativeActionChainContractTest: {$assertions} assertions passed\n");
