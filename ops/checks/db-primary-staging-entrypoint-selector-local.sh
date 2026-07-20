#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

cd "$PROJECT_ROOT"

bash ops/checks/db-primary-staging-synthetic-suite-local.sh

FILES=(
  bot/runtime/RuntimePrimaryEntrypointStorageContext.php
  bot/runtime/RuntimePrimaryStagingEntrypointSelectorConfig.php
  bot/runtime/RuntimePrimaryStagingEntrypointStorageSelector.php
  bot/runtime/RuntimePrimaryStagingEntrypointBootstrap.php
  bot/runtime/RuntimePrimaryStagingSelectorEvidence.php
  bot/runtime/RuntimePrimaryStagingEvidenceV3Verifier.php
  bot/runtime/RuntimePrimaryStagingEvidenceV3Gate.php
  bot/runtime/RuntimePrimaryStagingSelectorEvidenceCollector.php
  bot/storage/StorageFactory.php
  ops/runtime/collect-staging-db-primary-selector-evidence.php
  bot/tests/support/RuntimePrimaryStagingEvidenceV3ManifestFixture.php
  bot/tests/RuntimePrimaryStagingEntrypointSelectorConfigTest.php
  bot/tests/RuntimePrimaryEntrypointStorageContextTest.php
  bot/tests/RuntimePrimaryStorageFactoryEntrypointContextTest.php
  bot/tests/RuntimePrimaryStagingEntrypointStorageSelectorDisabledTest.php
  bot/tests/RuntimePrimaryStagingSelectorEvidenceTest.php
  bot/tests/RuntimePrimaryStagingEvidenceV3VerifierTest.php
  bot/tests/RuntimePrimaryStagingSelectorEvidenceCollectorTest.php
  bot/tests/RuntimePrimaryStagingEntrypointSelectorContractTest.php
  bot/tests/RuntimePrimaryStagingSelectorEvidenceCollectorCliContractTest.php
)

for file in "${FILES[@]}"; do
  "$PHP_BIN" -l "$file" >/dev/null
  printf 'lint ok: %s\n' "$file"
done

TESTS=(
  bot/tests/RuntimePrimaryStagingEntrypointSelectorConfigTest.php
  bot/tests/RuntimePrimaryEntrypointStorageContextTest.php
  bot/tests/RuntimePrimaryStorageFactoryEntrypointContextTest.php
  bot/tests/RuntimePrimaryStagingEntrypointStorageSelectorDisabledTest.php
  bot/tests/RuntimePrimaryStagingSelectorEvidenceTest.php
  bot/tests/RuntimePrimaryStagingEvidenceV3VerifierTest.php
  bot/tests/RuntimePrimaryStagingSelectorEvidenceCollectorTest.php
  bot/tests/RuntimePrimaryStagingEntrypointSelectorContractTest.php
  bot/tests/RuntimePrimaryStagingSelectorEvidenceCollectorCliContractTest.php
)

for test_file in "${TESTS[@]}"; do
  "$PHP_BIN" "$test_file"
done

printf 'DB-primary staging entrypoint selector focused verification passed.\n'
