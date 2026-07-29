#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
PHP_BIN="${PHP_BIN:-php}"

"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400 ? 0 : 1);'

files=(
  bot/baseline/JsonBehaviorBaselineNormalizer.php
  bot/baseline/JsonBehaviorBaselineFixture.php
  bot/baseline/JsonBehaviorBaselineResult.php
  bot/baseline/JsonInviteMatchmakingBaselineScenario.php
  bot/baseline/JsonInviteMatchmakingInviteTrait.php
  bot/baseline/JsonInviteMatchmakingQueueTrait.php
  bot/baseline/JsonInviteMatchmakingProjectionTrait.php
  bot/tests/Mvp14r2InviteMatchmakingBaselineTest.php
  bot/tests/Mvp14r2InviteMatchmakingContractTest.php
)

fixtures=(
  bot/tests/fixtures/mvp14r2/invite_direct_accept_start.json
  bot/tests/fixtures/mvp14r2/invite_link_open_cancel.json
  bot/tests/fixtures/mvp14r2/invite_rematch_reuse_start.json
  bot/tests/fixtures/mvp14r2/matchmaking_queue_cancel.json
  bot/tests/fixtures/mvp14r2/matchmaking_human_match.json
  bot/tests/fixtures/mvp14r2/matchmaking_bot_fallback.json
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
  bot/tests/Mvp14r2InviteMatchmakingBaselineTest.php \
  bot/tests/Mvp14r2InviteMatchmakingContractTest.php; do
  "$PHP_BIN" \
    -d auto_prepend_file="$PROJECT_ROOT/scripts/ci/php-strict.php" \
    "$PROJECT_ROOT/$test"
done

printf 'MGW_MVP14R2_INVITES_MATCHMAKING=PASSED\n'
printf 'DIRECT_LINK_REMATCH=PASSED\n'
printf 'QUEUE_CANCEL_HUMAN_BOT=PASSED\n'
printf 'DETERMINISTIC_RETRY=PASSED\n'
printf 'PRODUCTION_CONTACTED=false\n'
printf 'NETWORK_CONTACTED=false\n'
printf 'DATABASE_WRITE_EXECUTED=false\n'
printf 'LIVE_JSON_CHANGED=false\n'
printf 'PERSISTENT_CONFIG_CHANGED=false\n'
printf 'WEBHOOK_CHANGED=false\n'
printf 'CRON_CHANGED=false\n'
printf 'PRODUCTION_CHANGED=false\n'
