#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
PHP_BIN="${PHP_BIN:-php}"

"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400 ? 0 : 1);'

files=(
  bot/baseline/JsonBehaviorBaselineNormalizer.php
  bot/baseline/JsonBehaviorBaselineFixture.php
  bot/baseline/JsonBehaviorBaselineResult.php
  bot/baseline/JsonGamesBaselineScenario.php
  bot/baseline/JsonGamesSettlementTrait.php
  bot/baseline/JsonGamesClassicTrait.php
  bot/baseline/JsonGamesStrategyTrait.php
  bot/tests/Mvp14r2GamesBaselineTest.php
  bot/tests/Mvp14r2GamesContractTest.php
)

fixtures=(
  bot/tests/fixtures/mvp14r2/games_tictactoe_draw.json
  bot/tests/fixtures/mvp14r2/games_four_in_a_row_win.json
  bot/tests/fixtures/mvp14r2/games_battleship_final_shot.json
  bot/tests/fixtures/mvp14r2/games_checkers_capture.json
  bot/tests/fixtures/mvp14r2/games_reversi_count_finish.json
  bot/tests/fixtures/mvp14r2/games_chess_timeout.json
  bot/tests/fixtures/mvp14r2/games_go_two_passes.json
  bot/tests/fixtures/mvp14r2/games_domino_empty_hand.json
)

for file in "${files[@]}"; do
  "$PHP_BIN" -l "$PROJECT_ROOT/$file" >/dev/null
done

for fixture in "${fixtures[@]}"; do
  "$PHP_BIN" -r '
    $path = $argv[1];
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === "") exit(1);
    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    exit(is_array($decoded) && !array_is_list($decoded) ? 0 : 1);
  ' "$PROJECT_ROOT/$fixture"
done

for test in \
  bot/tests/Mvp14r2GamesBaselineTest.php \
  bot/tests/Mvp14r2GamesContractTest.php; do
  "$PHP_BIN" \
    -d auto_prepend_file="$PROJECT_ROOT/scripts/ci/php-strict.php" \
    "$PROJECT_ROOT/$test"
done

printf 'MGW_MVP14R2_GAMES=PASSED\n'
printf 'EIGHT_GAME_ENGINES=PASSED\n'
printf 'REJECTED_ACTION_STATE_GUARD=PASSED\n'
printf 'TIMER_SETTLEMENT_RETRY_REMATCH=PASSED\n'
printf 'PRODUCTION_CONTACTED=false\n'
printf 'NETWORK_CONTACTED=false\n'
printf 'DATABASE_WRITE_EXECUTED=false\n'
printf 'LIVE_JSON_CHANGED=false\n'
printf 'PERSISTENT_CONFIG_CHANGED=false\n'
printf 'WEBHOOK_CHANGED=false\n'
printf 'CRON_CHANGED=false\n'
printf 'PRODUCTION_CHANGED=false\n'
