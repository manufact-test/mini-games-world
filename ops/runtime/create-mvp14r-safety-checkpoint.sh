#!/usr/bin/env bash
set -euo pipefail
umask 077

PROJECT_ROOT=""
PRIVATE_ROOT=""
JSON_ROOT=""
OUTPUT_ROOT=""
CHECKPOINT_ID=""
CONFIRM=""

declare -A SEEN=()
for argument in "$@"; do
  case "$argument" in
    --project-root=*) key="project"; value="${argument#*=}" ;;
    --private-root=*) key="private"; value="${argument#*=}" ;;
    --json-root=*) key="json"; value="${argument#*=}" ;;
    --output-root=*) key="output"; value="${argument#*=}" ;;
    --checkpoint-id=*) key="checkpoint"; value="${argument#*=}" ;;
    --confirm=*) key="confirm"; value="${argument#*=}" ;;
    *) printf 'Unknown checkpoint option.\n' >&2; exit 2 ;;
  esac
  if [[ -n "${SEEN[$key]:-}" ]]; then
    printf 'Checkpoint option may be specified only once.\n' >&2
    exit 2
  fi
  SEEN[$key]=1
  if [[ -z "$value" || "$value" == *$'\0'* ]]; then
    printf 'Checkpoint option value is empty or invalid.\n' >&2
    exit 2
  fi
  case "$key" in
    project) PROJECT_ROOT="$value" ;;
    private) PRIVATE_ROOT="$value" ;;
    json) JSON_ROOT="$value" ;;
    output) OUTPUT_ROOT="$value" ;;
    checkpoint) CHECKPOINT_ID="$value" ;;
    confirm) CONFIRM="$value" ;;
  esac
done

for required in project private json output checkpoint confirm; do
  if [[ -z "${SEEN[$required]:-}" ]]; then
    printf 'Checkpoint requires every explicit option.\n' >&2
    exit 2
  fi
done

for path in "$PROJECT_ROOT" "$PRIVATE_ROOT" "$JSON_ROOT" "$OUTPUT_ROOT"; do
  if [[ "$path" != /* || "$path" == */ || "$path" == *\\* ]]; then
    printf 'Checkpoint paths must be exact absolute Linux paths without a trailing slash.\n' >&2
    exit 2
  fi
done

if [[ ! "$CHECKPOINT_ID" =~ ^MGW_SAFETY_CHECKPOINT_[A-Z0-9_-]{10,120}$ ]]; then
  printf 'Checkpoint ID is invalid.\n' >&2
  exit 2
fi
if [[ "$CONFIRM" != "CREATE_READ_ONLY_MVP14R_SAFETY_CHECKPOINT" ]]; then
  printf 'Checkpoint confirmation phrase is invalid.\n' >&2
  exit 2
fi

for directory in "$PROJECT_ROOT" "$PRIVATE_ROOT" "$JSON_ROOT"; do
  if [[ ! -d "$directory" || -L "$directory" ]]; then
    printf 'Required checkpoint source directory is unavailable or is a symlink.\n' >&2
    exit 1
  fi
done

mkdir -p "$OUTPUT_ROOT"
chmod 0700 "$OUTPUT_ROOT"
if [[ -L "$OUTPUT_ROOT" ]]; then
  printf 'Checkpoint output root must not be a symlink.\n' >&2
  exit 1
fi

PROJECT_ROOT="$(realpath "$PROJECT_ROOT")"
PRIVATE_ROOT="$(realpath "$PRIVATE_ROOT")"
JSON_ROOT="$(realpath "$JSON_ROOT")"
OUTPUT_ROOT="$(realpath "$OUTPUT_ROOT")"

paths_overlap() {
  local left="${1%/}"
  local right="${2%/}"
  [[ "$left" == "$right" || "$left" == "$right"/* || "$right" == "$left"/* ]]
}

for source_root in "$PROJECT_ROOT" "$PRIVATE_ROOT" "$JSON_ROOT"; do
  if paths_overlap "$source_root" "$OUTPUT_ROOT"; then
    printf 'Checkpoint output root must be outside every archived source tree.\n' >&2
    exit 1
  fi
done

FINAL_DIR="$OUTPUT_ROOT/$CHECKPOINT_ID"
if [[ -e "$FINAL_DIR" ]]; then
  printf 'Checkpoint destination already exists. Refusing to overwrite it.\n' >&2
  exit 1
fi

TEMP_DIR="$(mktemp -d "$OUTPUT_ROOT/.tmp-${CHECKPOINT_ID}-XXXXXX")"
chmod 0700 "$TEMP_DIR"
cleanup() {
  rm -f "$TEMP_DIR/mysql-client.cnf" "$TEMP_DIR/database-name.txt"
  if [[ -d "$TEMP_DIR" ]]; then rm -rf "$TEMP_DIR"; fi
}
trap cleanup EXIT INT TERM HUP

PHP_BIN="${MGW_PHP_BIN:-/opt/alt/php83/usr/bin/php}"
if [[ ! -x "$PHP_BIN" ]]; then
  printf 'PHP 8.3 CLI is unavailable.\n' >&2
  exit 1
fi

DATABASE_CONFIG="$PRIVATE_ROOT/database.php"
if [[ ! -f "$DATABASE_CONFIG" || -L "$DATABASE_CONFIG" ]]; then
  printf 'Private database config is unavailable or is a symlink.\n' >&2
  exit 1
fi

"$PHP_BIN" -r '
$path = $argv[1] ?? "";
$cnfPath = $argv[2] ?? "";
$namePath = $argv[3] ?? "";
$loaded = require $path;
if (!is_array($loaded) || !is_array($loaded["database"] ?? null)) {
    fwrite(STDERR, "Private database configuration is invalid.\n");
    exit(1);
}
$db = $loaded["database"];
$required = ["host", "name", "user", "password"];
foreach ($required as $field) {
    if (!is_string($db[$field] ?? null) || $db[$field] === "") {
        fwrite(STDERR, "Private database configuration is incomplete.\n");
        exit(1);
    }
}
$port = filter_var($db["port"] ?? 3306, FILTER_VALIDATE_INT);
if ($port === false || $port < 1 || $port > 65535) {
    fwrite(STDERR, "Private database port is invalid.\n");
    exit(1);
}
foreach (["host", "name", "user"] as $field) {
    if (preg_match("/^[A-Za-z0-9_$.:\\-\\[\\]]{1,255}$/", (string)$db[$field]) !== 1) {
        fwrite(STDERR, "Private database identity contains unsupported characters.\n");
        exit(1);
    }
}
$escape = static fn(string $value): string => addcslashes($value, "\\\\\"\n\r\t");
$cnf = "[client]\n"
    . "host=\"" . $escape((string)$db["host"]) . "\"\n"
    . "port=" . (int)$port . "\n"
    . "user=\"" . $escape((string)$db["user"]) . "\"\n"
    . "password=\"" . $escape((string)$db["password"]) . "\"\n"
    . "default-character-set=utf8mb4\n";
if (file_put_contents($cnfPath, $cnf, LOCK_EX) === false || !chmod($cnfPath, 0600)) {
    fwrite(STDERR, "Unable to create private database client config.\n");
    exit(1);
}
if (file_put_contents($namePath, (string)$db["name"] . "\n", LOCK_EX) === false || !chmod($namePath, 0600)) {
    fwrite(STDERR, "Unable to create private database name file.\n");
    exit(1);
}
' "$DATABASE_CONFIG" "$TEMP_DIR/mysql-client.cnf" "$TEMP_DIR/database-name.txt"

DATABASE_NAME="$(tr -d '\r\n' < "$TEMP_DIR/database-name.txt")"
if [[ -z "$DATABASE_NAME" ]]; then
  printf 'Database name is unavailable.\n' >&2
  exit 1
fi

DUMP_BIN="$(command -v mariadb-dump || command -v mysqldump || true)"
if [[ -z "$DUMP_BIN" ]]; then
  printf 'Neither mariadb-dump nor mysqldump is available.\n' >&2
  exit 1
fi

DUMP_ARGS=(
  "--defaults-extra-file=$TEMP_DIR/mysql-client.cnf"
  --single-transaction
  --quick
  --skip-lock-tables
  --hex-blob
  --default-character-set=utf8mb4
)
if "$DUMP_BIN" --help 2>/dev/null | grep -q -- '--no-tablespaces'; then
  DUMP_ARGS+=(--no-tablespaces)
fi

"$DUMP_BIN" "${DUMP_ARGS[@]}" --databases "$DATABASE_NAME" > "$TEMP_DIR/database.sql"
if [[ ! -s "$TEMP_DIR/database.sql" ]]; then
  printf 'Database dump is empty.\n' >&2
  exit 1
fi
gzip -9 "$TEMP_DIR/database.sql"
gzip -t "$TEMP_DIR/database.sql.gz"

PROJECT_PARENT="$(dirname "$PROJECT_ROOT")"
PRIVATE_PARENT="$(dirname "$PRIVATE_ROOT")"
JSON_PARENT="$(dirname "$JSON_ROOT")"

tar -C "$PROJECT_PARENT" -czf "$TEMP_DIR/public_html.tar.gz" "$(basename "$PROJECT_ROOT")"
tar -C "$PRIVATE_PARENT" -czf "$TEMP_DIR/private_mgw.tar.gz" "$(basename "$PRIVATE_ROOT")"
tar -C "$JSON_PARENT" -czf "$TEMP_DIR/mgw_data.tar.gz" "$(basename "$JSON_ROOT")"

for archive in public_html.tar.gz private_mgw.tar.gz mgw_data.tar.gz; do
  tar -tzf "$TEMP_DIR/$archive" >/dev/null
done

GIT_COMMIT="unknown"
GIT_STATUS="unavailable"
if command -v git >/dev/null 2>&1 && git -C "$PROJECT_ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  GIT_COMMIT="$(git -C "$PROJECT_ROOT" rev-parse --verify HEAD)"
  if [[ -z "$(git -C "$PROJECT_ROOT" status --porcelain)" ]]; then
    GIT_STATUS="clean"
  else
    GIT_STATUS="dirty"
  fi
fi

{
  printf 'checkpoint_id=%s\n' "$CHECKPOINT_ID"
  printf 'created_at_utc=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  printf 'project_root=%s\n' "$PROJECT_ROOT"
  printf 'private_root=%s\n' "$PRIVATE_ROOT"
  printf 'json_root=%s\n' "$JSON_ROOT"
  printf 'output_root=%s\n' "$OUTPUT_ROOT"
  printf 'git_commit=%s\n' "$GIT_COMMIT"
  printf 'git_status=%s\n' "$GIT_STATUS"
  printf 'database_dump_mode=single_transaction\n'
  printf 'production_runtime_changed=false\n'
  printf 'database_write_executed=false\n'
} > "$TEMP_DIR/checkpoint-info.txt"
chmod 0600 "$TEMP_DIR/checkpoint-info.txt"

rm -f "$TEMP_DIR/mysql-client.cnf" "$TEMP_DIR/database-name.txt"
(
  cd "$TEMP_DIR"
  sha256sum database.sql.gz public_html.tar.gz private_mgw.tar.gz mgw_data.tar.gz checkpoint-info.txt > checksums.sha256
  chmod 0600 checksums.sha256
  sha256sum -c checksums.sha256 >/dev/null
)

touch "$TEMP_DIR/COMPLETE"
chmod 0600 "$TEMP_DIR/COMPLETE"
mv "$TEMP_DIR" "$FINAL_DIR"
trap - EXIT INT TERM HUP

printf 'MGW_MVP14R_SAFETY_CHECKPOINT=PASSED\n'
printf 'CHECKPOINT_ID=%s\n' "$CHECKPOINT_ID"
printf 'CHECKPOINT_DIR=%s\n' "$FINAL_DIR"
printf 'GIT_COMMIT=%s\n' "$GIT_COMMIT"
printf 'GIT_STATUS=%s\n' "$GIT_STATUS"
printf 'DATABASE_DUMP=database.sql.gz\n'
printf 'PUBLIC_ARCHIVE=public_html.tar.gz\n'
printf 'PRIVATE_ARCHIVE=private_mgw.tar.gz\n'
printf 'JSON_ARCHIVE=mgw_data.tar.gz\n'
printf 'CHECKSUMS=checksums.sha256\n'
printf 'PRODUCTION_RUNTIME_CHANGED=false\n'
printf 'DATABASE_WRITE_EXECUTED=false\n'
