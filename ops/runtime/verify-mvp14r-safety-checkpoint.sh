#!/usr/bin/env bash
set -euo pipefail
umask 077

CHECKPOINT_DIR=""
EXPECTED_ID=""
CONFIRM=""

declare -A SEEN=()
for argument in "$@"; do
  case "$argument" in
    --checkpoint-dir=*) key="checkpoint"; value="${argument#*=}" ;;
    --expected-id=*) key="expected"; value="${argument#*=}" ;;
    --confirm=*) key="confirm"; value="${argument#*=}" ;;
    *) printf 'Unknown checkpoint verification option.\n' >&2; exit 2 ;;
  esac
  if [[ -n "${SEEN[$key]:-}" ]]; then
    printf 'Checkpoint verification option may be specified only once.\n' >&2
    exit 2
  fi
  SEEN[$key]=1
  # Bash command-line arguments cannot contain NUL bytes. Testing against
  # $'\0' is invalid because Bash represents that value as an empty string,
  # which would make every non-empty argument match and fail validation.
  if [[ -z "$value" ]]; then
    printf 'Checkpoint verification option value is empty or invalid.\n' >&2
    exit 2
  fi
  case "$key" in
    checkpoint) CHECKPOINT_DIR="$value" ;;
    expected) EXPECTED_ID="$value" ;;
    confirm) CONFIRM="$value" ;;
  esac
done

for required in checkpoint expected confirm; do
  if [[ -z "${SEEN[$required]:-}" ]]; then
    printf 'Checkpoint verification requires every explicit option.\n' >&2
    exit 2
  fi
done

if [[ "$CHECKPOINT_DIR" != /* || "$CHECKPOINT_DIR" == */ || "$CHECKPOINT_DIR" == *\\* ]]; then
  printf 'Checkpoint directory must be an exact absolute Linux path without a trailing slash.\n' >&2
  exit 2
fi
if [[ ! "$EXPECTED_ID" =~ ^MGW_SAFETY_CHECKPOINT_[A-Z0-9_-]{10,120}$ ]]; then
  printf 'Expected checkpoint ID is invalid.\n' >&2
  exit 2
fi
if [[ "$CONFIRM" != "VERIFY_READ_ONLY_MVP14R_SAFETY_CHECKPOINT" ]]; then
  printf 'Checkpoint verification confirmation phrase is invalid.\n' >&2
  exit 2
fi
if [[ ! -d "$CHECKPOINT_DIR" || -L "$CHECKPOINT_DIR" ]]; then
  printf 'Checkpoint directory is unavailable or is a symlink.\n' >&2
  exit 1
fi
if [[ "$(basename "$CHECKPOINT_DIR")" != "$EXPECTED_ID" ]]; then
  printf 'Checkpoint directory name does not match the expected ID.\n' >&2
  exit 1
fi

required_files=(
  database.sql.gz
  public_html.tar.gz
  private_mgw.tar.gz
  mgw_data.tar.gz
  checkpoint-info.txt
  checksums.sha256
  COMPLETE
)
for file in "${required_files[@]}"; do
  if [[ ! -f "$CHECKPOINT_DIR/$file" || -L "$CHECKPOINT_DIR/$file" ]]; then
    printf 'Checkpoint file is missing or is a symlink: %s\n' "$file" >&2
    exit 1
  fi
done

(
  cd "$CHECKPOINT_DIR"
  sha256sum -c checksums.sha256 >/dev/null
)
gzip -t "$CHECKPOINT_DIR/database.sql.gz"

TMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/mgw-mvp14r-verify-XXXXXX")"
chmod 0700 "$TMP_ROOT"
cleanup() { rm -rf "$TMP_ROOT"; }
trap cleanup EXIT INT TERM HUP

validate_tar_paths() {
  local archive="$1"
  local listing="$TMP_ROOT/listing.txt"
  tar -tzf "$archive" > "$listing"
  if [[ ! -s "$listing" ]]; then
    printf 'Checkpoint archive is empty.\n' >&2
    exit 1
  fi
  if awk 'BEGIN{bad=0} /^\//{bad=1} /(^|\/)\.\.($|\/)/{bad=1} END{exit bad ? 0 : 1}' "$listing"; then
    printf 'Checkpoint archive contains an unsafe path.\n' >&2
    exit 1
  fi
}

validate_tar_paths "$CHECKPOINT_DIR/public_html.tar.gz"
validate_tar_paths "$CHECKPOINT_DIR/private_mgw.tar.gz"
validate_tar_paths "$CHECKPOINT_DIR/mgw_data.tar.gz"

mkdir -p "$TMP_ROOT/public" "$TMP_ROOT/private" "$TMP_ROOT/json"
tar -xzf "$CHECKPOINT_DIR/public_html.tar.gz" -C "$TMP_ROOT/public"
tar -xzf "$CHECKPOINT_DIR/private_mgw.tar.gz" -C "$TMP_ROOT/private"
tar -xzf "$CHECKPOINT_DIR/mgw_data.tar.gz" -C "$TMP_ROOT/json"

PUBLIC_TOP_COUNT="$(find "$TMP_ROOT/public" -mindepth 1 -maxdepth 1 | wc -l | tr -d ' ')"
PRIVATE_TOP_COUNT="$(find "$TMP_ROOT/private" -mindepth 1 -maxdepth 1 | wc -l | tr -d ' ')"
JSON_TOP_COUNT="$(find "$TMP_ROOT/json" -mindepth 1 -maxdepth 1 | wc -l | tr -d ' ')"
if [[ "$PUBLIC_TOP_COUNT" != "1" || "$PRIVATE_TOP_COUNT" != "1" || "$JSON_TOP_COUNT" != "1" ]]; then
  printf 'Checkpoint archive top-level structure is invalid.\n' >&2
  exit 1
fi

PUBLIC_RESTORE="$(find "$TMP_ROOT/public" -mindepth 1 -maxdepth 1 -type d -print -quit)"
PRIVATE_RESTORE="$(find "$TMP_ROOT/private" -mindepth 1 -maxdepth 1 -type d -print -quit)"
JSON_RESTORE="$(find "$TMP_ROOT/json" -mindepth 1 -maxdepth 1 -type d -print -quit)"

for path in "$PUBLIC_RESTORE/bot/core/bootstrap.php" "$PUBLIC_RESTORE/app/index.html" "$PRIVATE_RESTORE/config.php" "$PRIVATE_RESTORE/database.php"; do
  if [[ ! -f "$path" ]]; then
    printf 'Restored checkpoint is missing a required file.\n' >&2
    exit 1
  fi
done

PHP_BIN="${MGW_PHP_BIN:-/opt/alt/php83/usr/bin/php}"
if [[ ! -x "$PHP_BIN" ]]; then
  PHP_BIN="$(command -v php || true)"
fi
if [[ -z "$PHP_BIN" || ! -x "$PHP_BIN" ]]; then
  printf 'PHP CLI is unavailable for checkpoint verification.\n' >&2
  exit 1
fi

"$PHP_BIN" -l "$PUBLIC_RESTORE/bot/core/bootstrap.php" >/dev/null

JSON_FILES=0
JSON_FILE_LIST="$TMP_ROOT/json-files.list"
find "$JSON_RESTORE" -type f -name '*.json' -print0 > "$JSON_FILE_LIST"
while IFS= read -r -d '' file; do
  "$PHP_BIN" -r '
$path = $argv[1] ?? "";
$contents = file_get_contents($path);
if (!is_string($contents)) exit(1);
json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
' "$file"
  JSON_FILES=$((JSON_FILES + 1))
done < "$JSON_FILE_LIST"
if [[ "$JSON_FILES" -lt 1 ]]; then
  printf 'Restored JSON checkpoint contains no JSON files.\n' >&2
  exit 1
fi

if ! gzip -cd "$CHECKPOINT_DIR/database.sql.gz" | grep -Eq '^(--|/\*!|CREATE |INSERT |USE |DROP |SET )'; then
  printf 'Database dump does not look like a SQL dump.\n' >&2
  exit 1
fi

CHECKPOINT_INFO_ID="$(awk -F= '$1=="checkpoint_id"{print substr($0,index($0,"=")+1)}' "$CHECKPOINT_DIR/checkpoint-info.txt")"
if [[ "$CHECKPOINT_INFO_ID" != "$EXPECTED_ID" ]]; then
  printf 'Checkpoint metadata ID does not match.\n' >&2
  exit 1
fi

printf 'MGW_MVP14R_SAFETY_CHECKPOINT_VERIFY=PASSED\n'
printf 'CHECKPOINT_ID=%s\n' "$EXPECTED_ID"
printf 'CHECKSUMS_VERIFIED=true\n'
printf 'DATABASE_DUMP_GZIP_VALID=true\n'
printf 'ARCHIVE_PATHS_SAFE=true\n'
printf 'PUBLIC_RESTORE_VALID=true\n'
printf 'PRIVATE_RESTORE_VALID=true\n'
printf 'JSON_RESTORE_VALID=true\n'
printf 'JSON_FILES_VALIDATED=%d\n' "$JSON_FILES"
printf 'PRODUCTION_RUNTIME_CHANGED=false\n'
printf 'DATABASE_WRITE_EXECUTED=false\n'
