#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

cd "$PROJECT_ROOT"

bash ops/checks/db-primary-staging-evidence-collector-local.sh

FILES=(
  bot/runtime/RuntimePrimaryAllModuleProjector.php
  bot/runtime/RuntimePrimaryStagingEvidenceV2Verifier.php
  bot/runtime/RuntimePrimaryStagingEvidenceV2Gate.php
  bot/runtime/RuntimePrimaryStagingEvidenceSource.php
  bot/runtime/RuntimePrimaryStagingEvidenceCollector.php
  bot/runtime/RuntimePrimaryStagingSchemaInspector.php
  bot/runtime/RuntimePrimaryStagingActivationConfig.php
  bot/runtime/RuntimePrimaryStagingActivationEvidenceLoader.php
  bot/runtime/RuntimePrimaryStagingActivationGuard.php
  ops/runtime/inspect-staging-db-primary-activation.php
  bot/tests/support/RuntimePrimaryStagingEvidenceV2ManifestFixture.php
  bot/tests/RuntimePrimaryAllModuleProjectorTest.php
  bot/tests/RuntimePrimaryStagingEvidenceV2VerifierTest.php
  bot/tests/RuntimePrimaryStagingEvidenceCollectorTest.php
  bot/tests/RuntimePrimaryStagingEvidenceSourceContractTest.php
  bot/tests/RuntimePrimaryStagingSchemaInspectorTest.php
  bot/tests/RuntimePrimaryStagingActivationConfigTest.php
  bot/tests/RuntimePrimaryStagingActivationEvidenceLoaderTest.php
  bot/tests/RuntimePrimaryStagingActivationGuardTest.php
  bot/tests/RuntimePrimaryStagingActivationCliContractTest.php
)

for file in "${FILES[@]}"; do
  "$PHP_BIN" -l "$file" >/dev/null
  printf 'lint ok: %s\n' "$file"
done

TESTS=(
  bot/tests/RuntimePrimaryAllModuleProjectorTest.php
  bot/tests/RuntimePrimaryStagingEvidenceV2VerifierTest.php
  bot/tests/RuntimePrimaryStagingEvidenceCollectorTest.php
  bot/tests/RuntimePrimaryStagingEvidenceSourceContractTest.php
  bot/tests/RuntimePrimaryStagingSchemaInspectorTest.php
  bot/tests/RuntimePrimaryStagingActivationConfigTest.php
  bot/tests/RuntimePrimaryStagingActivationEvidenceLoaderTest.php
  bot/tests/RuntimePrimaryStagingActivationGuardTest.php
  bot/tests/RuntimePrimaryStagingActivationCliContractTest.php
)

for test_file in "${TESTS[@]}"; do
  "$PHP_BIN" "$test_file"
done

printf 'DB-primary staging activation focused verification passed.\n'
