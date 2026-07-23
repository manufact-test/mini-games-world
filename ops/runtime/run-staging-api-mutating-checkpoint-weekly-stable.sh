#!/usr/bin/env bash
set -eEuo pipefail
umask 077

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
PRIVATE_DIR="$(cd -- "$PROJECT_ROOT/.." && pwd -P)/_private_mgw"
MODE="${1:-}"

fail() {
  printf 'MGW_STAGING_API_MUTATING_SMOKE=BLOCKED\n'
  printf 'REASON=%s\n' "$1"
  printf 'PERSISTENT_CONFIG_CHANGED=false\n'
  printf 'WEBHOOK_ALLOWED=false\n'
  printf 'CRON_CHANGED=false\n'
  printf 'PRODUCTION_CHANGED=false\n'
  exit 1
}

if [[ "$#" -gt 1 || ( -n "$MODE" && "$MODE" != "--lint-only" ) ]]; then
  fail 'weekly-stable runner accepts only optional --lint-only'
fi

[[ -d "$PROJECT_ROOT/.git" && ! -L "$PROJECT_ROOT/.git" ]] \
  || fail 'deployed checkout metadata is unavailable'
[[ -d "$PRIVATE_DIR" && ! -L "$PRIVATE_DIR" ]] \
  || fail 'private staging directory is unavailable'
[[ -z "$(git -C "$PROJECT_ROOT" status --porcelain=v1 --untracked-files=all 2>/dev/null)" ]] \
  || fail 'deployed checkout is not clean'

PHP_BIN=''
declare -a PHP_CANDIDATES=(
  php php8.3 php83 lsphp83 /usr/bin/php8.3 /usr/local/bin/php8.3
  /usr/local/lsws/lsphp83/bin/php /usr/local/lsws/lsphp83/bin/lsphp
  /opt/alt/php83/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php
  /opt/php83/bin/php /opt/hostinger/php83/bin/php
)
for candidate in "${PHP_CANDIDATES[@]}"; do
  resolved=''
  if [[ "$candidate" == */* ]]; then
    [[ -x "$candidate" ]] || continue
    resolved="$candidate"
  else
    resolved="$(command -v "$candidate" 2>/dev/null || true)"
    [[ -n "$resolved" ]] || continue
  fi
  if "$resolved" -r 'exit(PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400 ? 0 : 1);' >/dev/null 2>&1; then
    PHP_BIN="$resolved"
    break
  fi
done
[[ -n "$PHP_BIN" ]] || fail 'PHP 8.3 CLI binary was not found'

RUN_ID="$(date -u '+%Y%m%dT%H%M%SZ')-$$-${RANDOM}"
PATCHER="$PRIVATE_DIR/staging-api-mutating-weekly-stable-patcher-$RUN_ID.php"
PATCHED_PHP="$PRIVATE_DIR/staging-api-mutating-smoke-weekly-stable-$RUN_ID.php"
PATCHED_SH="$PRIVATE_DIR/staging-api-mutating-checkpoint-weekly-stable-$RUN_ID.sh"
SOURCE_PHP="$PROJECT_ROOT/ops/runtime/run-staging-api-mutating-smoke.php"
SOURCE_SH="$PROJECT_ROOT/ops/runtime/run-staging-api-mutating-checkpoint.sh"

for path in "$PATCHER" "$PATCHED_PHP" "$PATCHED_SH"; do
  [[ ! -e "$path" && ! -L "$path" ]] \
    || fail 'fresh private weekly-stable smoke path already exists'
done

cleanup() {
  rm -f -- "$PATCHER" "$PATCHED_PHP" "$PATCHED_SH" 2>/dev/null || true
}
trap cleanup EXIT HUP INT TERM

cat > "$PATCHER" <<'PHP'
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || count($argv) !== 6) {
    exit(2);
}

[, $sourcePhp, $outputPhp, $sourceShell, $outputShell, $projectRoot] = $argv;

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

$writePrivate = static function (string $path, string $content): void {
    if (file_exists($path) || is_link($path)) {
        throw new RuntimeException('Private output path already exists.');
    }
    $written = file_put_contents($path, $content, LOCK_EX);
    if ($written !== strlen($content) || !chmod($path, 0600)) {
        @unlink($path);
        throw new RuntimeException(
            'Private patched file could not be written safely.'
        );
    }
};

$php = file_get_contents($sourcePhp);
if (!is_string($php)) {
    throw new RuntimeException(
        'API mutating smoke source could not be read.'
    );
}

$oldRoot = <<<'OLD'
$projectRoot = rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/');
OLD;
$newRoot = '$projectRoot = ' . var_export($projectRoot, true) . ';';
$php = $replaceOnce(
    $php,
    $oldRoot,
    $newRoot,
    'project root patch'
);

$oldBaseline = <<<'OLD'
    $baselineSnapshot = $verificationStorage->readOnly(static fn(array $state): array => $state);
    if (!is_array($baselineSnapshot)) {
        throw new RuntimeException('Staging API mutating smoke baseline snapshot is unavailable.');
    }
    $jsonFingerprintBefore = directoryFingerprint((string)($config['data_dir'] ?? ''), $projectRoot);
OLD;

$newBaseline = <<<'NEW'
    $baselineSnapshot = $verificationStorage->readOnly(static fn(array $state): array => $state);
    if (!is_array($baselineSnapshot)) {
        throw new RuntimeException('Staging API mutating smoke baseline snapshot is unavailable.');
    }
    $baselineProjectionAudit = $auditor->auditOnly(
        $baselineSnapshot,
        (int)$baseline['state_revision'],
        (string)$baseline['state_sha256']
    );
    $baselineModuleContent = [];
    foreach ((array)($baselineProjectionAudit['module_fingerprints'] ?? []) as $module => $fingerprints) {
        if (!is_string($module) || !is_array($fingerprints)) {
            throw new RuntimeException('Staging API mutating smoke baseline module fingerprint is invalid.');
        }
        $sourceFingerprint = strtolower(trim((string)($fingerprints['source_fingerprint'] ?? '')));
        $databaseFingerprint = strtolower(trim((string)($fingerprints['database_fingerprint'] ?? '')));
        if (preg_match('/\A[a-f0-9]{64}\z/', $sourceFingerprint) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $databaseFingerprint) !== 1
            || !hash_equals($sourceFingerprint, $databaseFingerprint)) {
            throw new RuntimeException('Staging API mutating smoke baseline module content parity is invalid.');
        }
        $baselineModuleContent[$module] = [
            'source_fingerprint' => $sourceFingerprint,
            'database_fingerprint' => $databaseFingerprint,
        ];
    }
    ksort($baselineModuleContent, SORT_STRING);
    if (count($baselineModuleContent) !== 9) {
        throw new RuntimeException('Staging API mutating smoke baseline module content is incomplete.');
    }
    $baselineAllModuleContentFingerprint = hash(
        'sha256',
        canonicalJson($baselineModuleContent)
    );
    $jsonFingerprintBefore = directoryFingerprint((string)($config['data_dir'] ?? ''), $projectRoot);
NEW;

$php = $replaceOnce(
    $php,
    $oldBaseline,
    $newBaseline,
    'stable baseline module fingerprint patch'
);

$oldCleanup = <<<'OLD'
    $mappingCleanup = $identityCleanup->removeMappingsBeforeCleanupProjection(
        $provider,
        $subject,
        $subject,
        $sessionId,
        $mgwId
    );
    $cleanupTick = $worker->runOnce();
OLD;

$newCleanup = <<<'NEW'
    $mappingCleanup = $identityCleanup->removeMappingsBeforeCleanupProjection(
        $provider,
        $subject,
        $subject,
        $sessionId,
        $mgwId
    );
    $weeklyCleanup = $verificationDatabase->transaction(
        static function (DatabaseConnectionInterface $database) use ($subject): array {
            $accountRef = 'legacy:' . $subject;
            $rows = $database->fetchAll(
                'SELECT account_ref,legacy_user_id,state_json,state_sha256,status_json,status_sha256 '
                . 'FROM mgw_runtime_weekly_bonus_state '
                . 'WHERE account_ref=:account_ref OR legacy_user_id=:legacy_user_id FOR UPDATE',
                [
                    'account_ref' => $accountRef,
                    'legacy_user_id' => $subject,
                ]
            );
            if (count($rows) !== 1) {
                throw new RuntimeException(
                    'Staging API mutating smoke weekly bonus cleanup row count is invalid.'
                );
            }

            $row = $rows[0];
            $stateJson = (string)($row['state_json'] ?? '');
            $stateSha = strtolower(trim((string)($row['state_sha256'] ?? '')));
            $statusJson = (string)($row['status_json'] ?? '');
            $statusSha = strtolower(trim((string)($row['status_sha256'] ?? '')));

            try {
                $statePayload = json_decode($stateJson, true, 512, JSON_THROW_ON_ERROR);
                $statusPayload = json_decode($statusJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new RuntimeException(
                    'Staging API mutating smoke weekly bonus cleanup payload is invalid.',
                    0,
                    $error
                );
            }

            if (!hash_equals($accountRef, (string)($row['account_ref'] ?? ''))
                || !hash_equals($subject, (string)($row['legacy_user_id'] ?? ''))
                || preg_match('/\A[a-f0-9]{64}\z/', $stateSha) !== 1
                || !hash_equals($stateSha, hash('sha256', $stateJson))
                || preg_match('/\A[a-f0-9]{64}\z/', $statusSha) !== 1
                || !hash_equals($statusSha, hash('sha256', $statusJson))
                || !is_array($statePayload)
                || !is_array($statusPayload)) {
                throw new RuntimeException(
                    'Staging API mutating smoke weekly bonus cleanup identity is invalid.'
                );
            }

            $deleted = $database->execute(
                'DELETE FROM mgw_runtime_weekly_bonus_state '
                . 'WHERE account_ref=:account_ref AND legacy_user_id=:legacy_user_id',
                [
                    'account_ref' => $accountRef,
                    'legacy_user_id' => $subject,
                ]
            );
            if ($deleted !== 1) {
                throw new RuntimeException(
                    'Staging API mutating smoke weekly bonus cleanup deletion count is invalid.'
                );
            }

            return ['weekly_bonus_rows_deleted' => $deleted];
        }
    );
    if (($weeklyCleanup['weekly_bonus_rows_deleted'] ?? null) !== 1) {
        throw new RuntimeException(
            'Staging API mutating smoke weekly bonus cleanup proof is incomplete.'
        );
    }
    $cleanupTick = $worker->runOnce();
NEW;

$php = $replaceOnce(
    $php,
    $oldCleanup,
    $newCleanup,
    'weekly cleanup patch'
);

$oldFinal = <<<'OLD'
    $final = $guard->capture();
    $finalSnapshot = $verificationStorage->readOnly(static fn(array $state): array => $state);
    $jsonFingerprintAfter = directoryFingerprint((string)($config['data_dir'] ?? ''), $projectRoot);
OLD;

$newFinal = <<<'NEW'
    $final = $guard->capture();
    $finalSnapshot = $verificationStorage->readOnly(static fn(array $state): array => $state);
    if (!is_array($finalSnapshot)) {
        throw new RuntimeException('Staging API mutating smoke final snapshot is unavailable.');
    }
    $finalProjectionAudit = $auditor->auditOnly(
        $finalSnapshot,
        (int)$final['state_revision'],
        (string)$final['state_sha256']
    );
    $finalModuleContent = [];
    foreach ((array)($finalProjectionAudit['module_fingerprints'] ?? []) as $module => $fingerprints) {
        if (!is_string($module) || !is_array($fingerprints)) {
            throw new RuntimeException('Staging API mutating smoke final module fingerprint is invalid.');
        }
        $sourceFingerprint = strtolower(trim((string)($fingerprints['source_fingerprint'] ?? '')));
        $databaseFingerprint = strtolower(trim((string)($fingerprints['database_fingerprint'] ?? '')));
        if (preg_match('/\A[a-f0-9]{64}\z/', $sourceFingerprint) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $databaseFingerprint) !== 1
            || !hash_equals($sourceFingerprint, $databaseFingerprint)) {
            throw new RuntimeException('Staging API mutating smoke final module content parity is invalid.');
        }
        $finalModuleContent[$module] = [
            'source_fingerprint' => $sourceFingerprint,
            'database_fingerprint' => $databaseFingerprint,
        ];
    }
    ksort($finalModuleContent, SORT_STRING);
    if (count($finalModuleContent) !== 9) {
        throw new RuntimeException('Staging API mutating smoke final module content is incomplete.');
    }
    $finalAllModuleContentFingerprint = hash(
        'sha256',
        canonicalJson($finalModuleContent)
    );
    $jsonFingerprintAfter = directoryFingerprint((string)($config['data_dir'] ?? ''), $projectRoot);
NEW;

$php = $replaceOnce(
    $php,
    $oldFinal,
    $newFinal,
    'stable final module fingerprint patch'
);

$php = $replaceOnce(
    $php,
    "        || !hash_equals((string)\$final['all_module_fingerprint'], (string)\$baseline['all_module_fingerprint'])",
    "        || !hash_equals(\$finalAllModuleContentFingerprint, \$baselineAllModuleContentFingerprint)",
    'stable final proof comparison patch'
);

$php = $replaceOnce(
    $php,
    "        'baseline_all_module_fingerprint' => (string)\$baseline['all_module_fingerprint'],",
    "        'baseline_all_module_fingerprint' => \$baselineAllModuleContentFingerprint,",
    'stable baseline evidence field patch'
);

$php = $replaceOnce(
    $php,
    "        'final_all_module_fingerprint' => (string)\$final['all_module_fingerprint'],",
    "        'final_all_module_fingerprint' => \$finalAllModuleContentFingerprint,",
    'stable final evidence field patch'
);

$writePrivate($outputPhp, $php);

$shell = file_get_contents($sourceShell);
if (!is_string($shell)) {
    throw new RuntimeException(
        'API mutating checkpoint source could not be read.'
    );
}

$oldShellRoot = <<<'OLD'
PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
OLD;
$newShellRoot = 'PROJECT_ROOT=' . escapeshellarg($projectRoot);
$shell = $replaceOnce(
    $shell,
    $oldShellRoot,
    $newShellRoot,
    'checkpoint root patch'
);

$oldCommand = <<<'OLD'
if ! "$PHP_BIN" "$PROJECT_ROOT/ops/runtime/run-staging-api-mutating-smoke.php" \
OLD;
$newCommand = 'if ! "$PHP_BIN" '
    . escapeshellarg($outputPhp)
    . " \\";
$shell = $replaceOnce(
    $shell,
    $oldCommand,
    $newCommand,
    'checkpoint PHP patch'
);
$writePrivate($outputShell, $shell);
PHP

chmod 0600 "$PATCHER" \
  || fail 'weekly-stable patcher permissions could not be applied'

"$PHP_BIN" "$PATCHER" \
  "$SOURCE_PHP" \
  "$PATCHED_PHP" \
  "$SOURCE_SH" \
  "$PATCHED_SH" \
  "$PROJECT_ROOT" \
  || fail 'weekly-stable smoke patch could not be constructed'

"$PHP_BIN" -l "$PATCHER" >/dev/null \
  || fail 'weekly-stable patcher did not pass syntax validation'
"$PHP_BIN" -l "$PATCHED_PHP" >/dev/null \
  || fail 'weekly-stable patched PHP did not pass syntax validation'
bash -n "$PATCHED_SH" \
  || fail 'weekly-stable patched checkpoint did not pass syntax validation'

if [[ "$MODE" == "--lint-only" ]]; then
  printf 'MGW_STAGING_API_WEEKLY_STABLE_PATCH=PASSED\n'
  printf 'REPOSITORY_COMMIT=%s\n' "$(git -C "$PROJECT_ROOT" rev-parse --verify HEAD)"
  printf 'DATABASE_WRITE_EXECUTED=false\n'
  printf 'WORKER_EXECUTED=false\n'
  printf 'PERSISTENT_CONFIG_CHANGED=false\n'
  printf 'WEBHOOK_ALLOWED=false\n'
  printf 'CRON_CHANGED=false\n'
  printf 'PRODUCTION_CHANGED=false\n'
  exit 0
fi

exec bash "$PATCHED_SH"
