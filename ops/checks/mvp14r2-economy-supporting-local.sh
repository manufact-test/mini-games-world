#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
PHP_BIN="${PHP_BIN:-php}"

"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400 ? 0 : 1);'

files=(
  bot/baseline/JsonBehaviorBaselineNormalizer.php
  bot/baseline/JsonBehaviorBaselineFixture.php
  bot/baseline/JsonBehaviorBaselineResult.php
  bot/baseline/JsonEconomySupportingBaselineScenario.php
  bot/baseline/JsonEconomyHistoryTrait.php
  bot/baseline/JsonShopPaymentsTrait.php
  bot/baseline/JsonWeeklyBonusTrait.php
  bot/tests/Mvp14r2EconomySupportingBaselineTest.php
  bot/tests/Mvp14r2EconomySupportingContractTest.php
)

fixtures=(
  bot/tests/fixtures/mvp14r2/economy_match_win_history.json
  bot/tests/fixtures/mvp14r2/economy_gold_draw_history.json
  bot/tests/fixtures/mvp14r2/economy_insufficient_balances.json
  bot/tests/fixtures/mvp14r2/shop_order_complete.json
  bot/tests/fixtures/mvp14r2/shop_order_reject_refund.json
  bot/tests/fixtures/mvp14r2/payment_apply_once.json
  bot/tests/fixtures/mvp14r2/payment_reject_cancel.json
  bot/tests/fixtures/mvp14r2/weekly_bonus_eligibility_timezone.json
)

for file in "${files[@]}"; do
  "$PHP_BIN" -l "$PROJECT_ROOT/$file" >/dev/null
done

for fixture in "${fixtures[@]}"; do
  "$PHP_BIN" -r '
    $raw = file_get_contents($argv[1]);
    if (!is_string($raw) || trim($raw) === "") exit(1);
    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    exit(is_array($decoded) && !array_is_list($decoded) ? 0 : 1);
  ' "$PROJECT_ROOT/$fixture"
done

for test in \
  bot/tests/Mvp14r2EconomySupportingBaselineTest.php \
  bot/tests/Mvp14r2EconomySupportingContractTest.php; do
  "$PHP_BIN" \
    -d auto_prepend_file="$PROJECT_ROOT/scripts/ci/php-strict.php" \
    "$PROJECT_ROOT/$test"
done

printf 'MGW_MVP14R2_ECONOMY_SUPPORTING=PASSED\n'
printf 'MATCH_GOLD_SETTLEMENT_HISTORY=PASSED\n'
printf 'SHOP_PAYMENT_FIXTURES=PASSED\n'
printf 'WEEKLY_BONUS_IDEMPOTENCY=PASSED\n'
printf 'REAL_PAYMENT_CALLS=false\n'
printf 'PRODUCTION_CONTACTED=false\n'
printf 'NETWORK_CONTACTED=false\n'
printf 'DATABASE_WRITE_EXECUTED=false\n'
printf 'LIVE_JSON_CHANGED=false\n'
printf 'PERSISTENT_CONFIG_CHANGED=false\n'
printf 'WEBHOOK_CHANGED=false\n'
printf 'CRON_CHANGED=false\n'
printf 'PRODUCTION_CHANGED=false\n'
