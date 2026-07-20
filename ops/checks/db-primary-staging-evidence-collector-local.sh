#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

cd "$PROJECT_ROOT"

bash ops/checks/db-primary-staging-evidence-local.sh

FILES=(
  bot/runtime/RuntimePrimaryJsonEvidence.php
  bot/runtime/RuntimePrimaryStagingConcurrencyProbe.php
  bot/runtime/RuntimePrimaryStagingEvidenceSourceInterface.php
  bot/runtime/RuntimePrimaryStagingEvidenceSource.php
  bot/runtime/RuntimePrimaryStagingEvidenceCollector.php
  bot/runtime/RuntimePrimaryStagingEvidenceWriter.php
  ops/runtime/collect-staging-db-primary-evidence.php
  bot/tests/RuntimePrimaryJsonEvidenceTest.php
  bot/tests/RuntimePrimaryStagingConcurrencyProbeTest.php
  bot/tests/RuntimePrimaryStagingEvidenceCollectorTest.php
  bot/tests/RuntimePrimaryStagingEvidenceWriterTest.php
  bot/tests/RuntimePrimaryStagingEvidenceSourceContractTest.php
  bot/tests/RuntimePrimaryStagingEvidenceCollectorCliContractTest.php
)

for file in "${FILES[@]}"; do
  "$PHP_BIN" -l "$file" >/dev/null
  printf 'lint ok: %s\n' "$file"
done

"$PHP_BIN" bot/tests/RuntimePrimaryJsonEvidenceTest.php
"$PHP_BIN" bot/tests/RuntimePrimaryStagingConcurrencyProbeTest.php
"$PHP_BIN" bot/tests/RuntimePrimaryStagingEvidenceCollectorTest.php
"$PHP_BIN" bot/tests/RuntimePrimaryStagingEvidenceWriterTest.php
"$PHP_BIN" bot/tests/RuntimePrimaryStagingEvidenceSourceContractTest.php
"$PHP_BIN" bot/tests/RuntimePrimaryStagingEvidenceCollectorCliContractTest.php

printf 'DB-primary staging evidence collector focused verification passed.\n'
