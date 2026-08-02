# MVP-14R.0 — Hostinger safety-checkpoint runbook

## Purpose

Create and verify the exact production checkpoint:

`MGW_SAFETY_CHECKPOINT_2026-07-28_V109_PRE_RELATIONAL_REBUILD`

This operation does not switch storage, modify MySQL, replace JSON, change runtime flags, change webhook or change Cron.

## Mandatory gate

Do not run this from an unmerged branch.

The operator must replace `__DEPLOYED_MERGE_COMMIT__` with the exact PR #173 merge commit after:

1. exact-head CI succeeds;
2. PR #173 receives explicit merge authorization;
3. PR #173 is merged;
4. Hostinger redeploys that exact merge commit;
5. the deployed checkout is clean.

The checkpoint creator fails closed if the deployed commit differs or the checkout is dirty.

## Production paths

```text
DOMAIN_ROOT=$HOME/domains/lemonchiffon-gerbil-545102.hostingersite.com
PROJECT_ROOT=$DOMAIN_ROOT/public_html
PRIVATE_ROOT=$DOMAIN_ROOT/_private_mgw
JSON_ROOT=$DOMAIN_ROOT/mgw_data
OUTPUT_ROOT=$DOMAIN_ROOT/mgw_checkpoints
```

The output root is deliberately outside every archived source tree.

## Create checkpoint

Run from SSH after replacing the commit placeholder:

```bash
set -euo pipefail
DOMAIN_ROOT="$HOME/domains/lemonchiffon-gerbil-545102.hostingersite.com"
PROJECT_ROOT="$DOMAIN_ROOT/public_html"
PRIVATE_ROOT="$DOMAIN_ROOT/_private_mgw"
JSON_ROOT="$DOMAIN_ROOT/mgw_data"
OUTPUT_ROOT="$DOMAIN_ROOT/mgw_checkpoints"
CHECKPOINT_ID="MGW_SAFETY_CHECKPOINT_2026-07-28_V109_PRE_RELATIONAL_REBUILD"
EXPECTED_DEPLOYED_COMMIT="__DEPLOYED_MERGE_COMMIT__"

bash "$PROJECT_ROOT/ops/runtime/create-mvp14r-safety-checkpoint.sh" \
  --project-root="$PROJECT_ROOT" \
  --private-root="$PRIVATE_ROOT" \
  --json-root="$JSON_ROOT" \
  --output-root="$OUTPUT_ROOT" \
  --checkpoint-id="$CHECKPOINT_ID" \
  --expected-git-commit="$EXPECTED_DEPLOYED_COMMIT" \
  --confirm=CREATE_READ_ONLY_MVP14R_SAFETY_CHECKPOINT
```

Expected first marker:

```text
MGW_MVP14R_SAFETY_CHECKPOINT=PASSED
```

## Verify isolated restore

Run immediately after the create command succeeds:

```bash
set -euo pipefail
DOMAIN_ROOT="$HOME/domains/lemonchiffon-gerbil-545102.hostingersite.com"
PROJECT_ROOT="$DOMAIN_ROOT/public_html"
OUTPUT_ROOT="$DOMAIN_ROOT/mgw_checkpoints"
CHECKPOINT_ID="MGW_SAFETY_CHECKPOINT_2026-07-28_V109_PRE_RELATIONAL_REBUILD"
CHECKPOINT_DIR="$OUTPUT_ROOT/$CHECKPOINT_ID"

bash "$PROJECT_ROOT/ops/runtime/verify-mvp14r-safety-checkpoint.sh" \
  --checkpoint-dir="$CHECKPOINT_DIR" \
  --expected-id="$CHECKPOINT_ID" \
  --confirm=VERIFY_READ_ONLY_MVP14R_SAFETY_CHECKPOINT
```

Expected first marker:

```text
MGW_MVP14R_SAFETY_CHECKPOINT_VERIFY=PASSED
```

## Required evidence to return

Copy the complete output of both commands. It must include:

- checkpoint ID and directory;
- exact Git commit;
- `GIT_STATUS=clean`;
- artifact names;
- checksum verification;
- database gzip verification;
- public/private/JSON isolated restore verification;
- count of decoded JSON files;
- `PRODUCTION_RUNTIME_CHANGED=false`;
- `DATABASE_WRITE_EXECUTED=false`.

## Failure rule

On any failure:

- do not delete or overwrite an existing completed checkpoint;
- do not switch storage;
- do not run the DB→JSON recovery export;
- return the exact command output for diagnosis.

MVP-14R.1 begins only after both PASS markers are recorded and a separate approval is given for maintenance/read-only DB→JSON export work.
