#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"
cd "$PROJECT_ROOT"

FILES=(
  bot/runtime/RuntimePrimaryProjectionOutboxSchemaInstaller.php
  bot/runtime/RuntimePrimaryProjectionOutboxWriter.php
  bot/runtime/DatabasePrimaryStateStorageAdapter.php
  bot/storage/StorageFactory.php
  bot/tests/DatabasePrimaryStateStorageAdapterTest.php
  bot/tests/RuntimePrimaryProjectionOutboxTest.php
)

for file in "${FILES[@]}"; do
  "$PHP_BIN" -l "$file" >/dev/null
  printf 'lint ok: %s\n' "$file"
done

"$PHP_BIN" bot/tests/DatabasePrimaryStateStorageAdapterTest.php
"$PHP_BIN" bot/tests/RuntimePrimaryProjectionOutboxTest.php

printf 'DB-primary projection outbox focused verification passed.\n'
