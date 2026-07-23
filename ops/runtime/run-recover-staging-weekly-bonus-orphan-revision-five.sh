#!/usr/bin/env bash
set -euo pipefail
umask 077

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
PRIVATE_DIR="$(cd -- "$PROJECT_ROOT/.." && pwd -P)/_private_mgw"
PHP_BIN="/opt/alt/php83/usr/bin/php"
SOURCE="$PROJECT_ROOT/ops/runtime/recover-staging-weekly-bonus-orphan-revision-five.php"
BASELINE_RECEIPT_COMMIT="742631f627c4278b35bac77636c55bf32f740035"

if [[ "$#" -ne 2 ]]; then
  echo "RECOVERY_LAUNCHER=BLOCKED"
  echo "REASON=expected mode and exact commit"
  exit 2
fi

MODE="$1"
EXPECTED_COMMIT="$2"

if [[ "$MODE" != "--inspect" && "$MODE" != "--apply" ]]; then
  echo "RECOVERY_LAUNCHER=BLOCKED"
  echo "REASON=invalid mode"
  exit 2
fi

if [[ ! "$EXPECTED_COMMIT" =~ ^[a-f0-9]{40}$ ]]; then
  echo "RECOVERY_LAUNCHER=BLOCKED"
  echo "REASON=invalid expected commit"
  exit 2
fi

[[ -x "$PHP_BIN" ]] || {
  echo "RECOVERY_LAUNCHER=BLOCKED"
  echo "REASON=PHP 8.3 binary unavailable"
  exit 1
}

[[ -d "$PRIVATE_DIR" && ! -L "$PRIVATE_DIR" ]] || {
  echo "RECOVERY_LAUNCHER=BLOCKED"
  echo "REASON=private staging directory unavailable"
  exit 1
}

CURRENT_COMMIT="$(git -C "$PROJECT_ROOT" rev-parse --verify HEAD)"
if [[ "$CURRENT_COMMIT" != "$EXPECTED_COMMIT" ]]; then
  echo "RECOVERY_LAUNCHER=BLOCKED"
  echo "EXPECTED_COMMIT=$EXPECTED_COMMIT"
  echo "CURRENT_COMMIT=$CURRENT_COMMIT"
  exit 1
fi

if [[ -n "$(git -C "$PROJECT_ROOT" status --porcelain=v1 --untracked-files=all)" ]]; then
  echo "RECOVERY_LAUNCHER=BLOCKED"
  echo "REASON=deployed checkout is not clean"
  exit 1
fi

RUN_ID="$(date -u '+%Y%m%dT%H%M%SZ')-$$-${RANDOM}"
PATCHED="$PRIVATE_DIR/recover-weekly-revision-five-$RUN_ID.php"
PATCHER="$PRIVATE_DIR/recover-weekly-revision-five-patcher-$RUN_ID.php"

cleanup() {
  rm -f -- "$PATCHED" "$PATCHER" 2>/dev/null || true
}
trap cleanup EXIT HUP INT TERM

for path in "$PATCHED" "$PATCHER"; do
  [[ ! -e "$path" && ! -L "$path" ]] || {
    echo "RECOVERY_LAUNCHER=BLOCKED"
    echo "REASON=fresh private recovery path already exists"
    exit 1
  }
done

cat > "$PATCHER" <<'PHP'
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || count($argv) !== 5) {
    exit(2);
}

[, $source, $output, $projectRoot, $baselineCommit] = $argv;

$content = file_get_contents($source);
if (!is_string($content)) {
    throw new RuntimeException('Recovery source could not be read.');
}

$replaceOnce = static function (
    string $content,
    string $needle,
    string $replacement,
    string $label
): string {
    $count = substr_count($content, $needle);
    if ($count !== 1) {
        throw new RuntimeException(
            $label . ' exact source match count was ' . $count . '.'
        );
    }
    return str_replace($needle, $replacement, $content);
};

$content = $replaceOnce(
    $content,
    <<<'OLD'
$root = rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/');
OLD,
    '$root = ' . var_export($projectRoot, true) . ';',
    'project root patch'
);

$content = $replaceOnce(
    $content,
    '$receipt = exactBaselineReceipt($privateDir, $root, $expectedCommit);',
    '$receipt = exactBaselineReceipt($privateDir, $root, '
        . var_export($baselineCommit, true)
        . ');',
    'baseline receipt commit patch'
);

$written = file_put_contents($output, $content, LOCK_EX);
if ($written !== strlen($content) || !chmod($output, 0600)) {
    @unlink($output);
    throw new RuntimeException('Patched recovery could not be written safely.');
}
PHP

chmod 0600 "$PATCHER"

"$PHP_BIN" "$PATCHER" \
  "$SOURCE" \
  "$PATCHED" \
  "$PROJECT_ROOT" \
  "$BASELINE_RECEIPT_COMMIT"

"$PHP_BIN" -l "$PATCHER" >/dev/null
"$PHP_BIN" -l "$PATCHED" >/dev/null

"$PHP_BIN" "$PATCHED" "$MODE" "$EXPECTED_COMMIT"
