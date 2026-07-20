#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

cd "$PROJECT_ROOT"

bash ops/checks/db-primary-staging-rehearsal-local.sh

FILES=(
  bot/runtime/RuntimePrimaryEntrypointEvidence.php
  bot/runtime/RuntimePrimaryRepositoryCommitResolver.php
  bot/runtime/RuntimePrimaryStagingEvidenceVerifier.php
  bot/runtime/RuntimePrimaryStagingEvidenceGate.php
  ops/runtime/verify-staging-db-primary-evidence.php
  bot/tests/support/RuntimePrimaryStagingEvidenceManifestFixture.php
  bot/tests/RuntimePrimaryEntrypointEvidenceTest.php
  bot/tests/RuntimePrimaryRepositoryCommitResolverTest.php
  bot/tests/RuntimePrimaryStagingEvidenceVerifierTest.php
  bot/tests/RuntimePrimaryStagingEvidenceGateTest.php
  bot/tests/RuntimePrimaryStagingEvidenceCliContractTest.php
)

for file in "${FILES[@]}"; do
  "$PHP_BIN" -l "$file" >/dev/null
  printf 'lint ok: %s\n' "$file"
done

"$PHP_BIN" bot/tests/RuntimePrimaryEntrypointEvidenceTest.php
"$PHP_BIN" bot/tests/RuntimePrimaryRepositoryCommitResolverTest.php
"$PHP_BIN" bot/tests/RuntimePrimaryStagingEvidenceVerifierTest.php
"$PHP_BIN" bot/tests/RuntimePrimaryStagingEvidenceGateTest.php
"$PHP_BIN" bot/tests/RuntimePrimaryStagingEvidenceCliContractTest.php

printf 'DB-primary staging evidence focused verification passed.\n'
