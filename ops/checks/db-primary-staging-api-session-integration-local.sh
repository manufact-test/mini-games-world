#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

cd "$PROJECT_ROOT"

bash ops/checks/db-primary-staging-request-finalizer-local.sh

FILES=(
  bot/runtime/RuntimePrimaryEntrypointStorageContext.php
  bot/runtime/RuntimePrimaryStagingApiRequestFinalizationHook.php
  bot/runtime/RuntimePrimaryStagingApiSessionCoordinator.php
  bot/runtime/RuntimePrimaryStagingEntrypointBootstrap.php
  bot/runtime/RuntimePrimaryStagingEntrypointSelectorConfig.php
  bot/runtime/RuntimePrimaryStagingEntrypointStorageSelector.php
  bot/runtime/RuntimePrimaryStagingSelectorEvidence.php
  bot/runtime/RuntimePrimaryStagingRequestLifecycleEvidence.php
  bot/runtime/RuntimePrimaryStagingEvidenceV3Verifier.php
  bot/runtime/RuntimePrimaryStagingEvidenceV4Verifier.php
  bot/runtime/RuntimePrimaryStagingEvidenceV4Gate.php
  bot/runtime/RuntimePrimaryStagingEvidenceGate.php
  bot/runtime/RuntimePrimaryStagingLifecycleEvidenceCollector.php
  bot/storage/StorageFactory.php
  ops/runtime/collect-staging-db-primary-lifecycle-evidence.php
  bot/tests/support/RuntimePrimaryStagingEvidenceV4ManifestFixture.php
  bot/tests/RuntimePrimaryStagingApiRequestFinalizationHookTest.php
  bot/tests/RuntimePrimaryStagingApiSessionCoordinatorTest.php
  bot/tests/RuntimePrimaryStagingRequestLifecycleEvidenceTest.php
  bot/tests/RuntimePrimaryStagingEvidenceV4VerifierTest.php
  bot/tests/RuntimePrimaryStagingLifecycleEvidenceCollectorTest.php
  bot/tests/RuntimePrimaryStagingLifecycleEvidenceCollectorCliContractTest.php
)

for file in "${FILES[@]}"; do
  "$PHP_BIN" -l "$file" >/dev/null
  printf 'lint ok: %s\n' "$file"
done

TESTS=(
  bot/tests/RuntimePrimaryStagingApiRequestFinalizationHookTest.php
  bot/tests/RuntimePrimaryStagingApiSessionCoordinatorTest.php
  bot/tests/RuntimePrimaryStagingRequestLifecycleEvidenceTest.php
  bot/tests/RuntimePrimaryStagingEvidenceV4VerifierTest.php
  bot/tests/RuntimePrimaryStagingLifecycleEvidenceCollectorTest.php
  bot/tests/RuntimePrimaryStagingLifecycleEvidenceCollectorCliContractTest.php
)

for test_file in "${TESTS[@]}"; do
  "$PHP_BIN" "$test_file"
done

printf 'DB-primary staging API session integration focused verification passed.\n'
