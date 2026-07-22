# MVP-14 staging read-only execution checkpoint

This checkpoint is the only approved sequence between a clean hosted PHP 8.3 result and any later mutating staging work.

It does **not** authorize production cutover, webhook execution, Cron changes, persistent DB-primary routing or mutating API smoke.

## Gate 0 — hosted PHP 8.3 evidence

Do not deploy staging until GitHub Actions has produced an actual successful run for the exact deployable branch head.

Required proof:

- workflow job executed on `ubuntu-24.04`;
- checked-out commit was read from `git rev-parse HEAD`;
- PHP version was exact 8.3.x;
- required PHP extensions were present;
- the complete fourteen-script focused stack passed;
- evidence verifier passed against the checked-out commit;
- artifact name contains the same deployable commit plus `run_id` and `run_attempt`;
- artifact contains the three-file suite bundle and separate verification JSON;
- no step was skipped or continued after failure.

A green PR label, mergeability or an empty status list is not evidence.

## Gate 1 — exact staging deployment

Deploy only the same commit accepted by Gate 0.

Before any evidence operation, confirm on staging:

```bash
php -r 'printf("PHP_VERSION=%s\nPHP_VERSION_ID=%d\n", PHP_VERSION, PHP_VERSION_ID); exit(PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400 ? 0 : 1);'
git rev-parse --verify HEAD
```

Block if the staging commit differs by one byte from the accepted CI commit.

Deployment alone must not:

- enable persistent activation;
- enable the persistent entrypoint selector;
- enable the persistent request session;
- change the webhook;
- add or change Cron;
- change production;
- run a projection worker.

## Gate 2 — private staging prerequisites

The staging runtime must remain isolated from production and use a dedicated staging database.

Required filesystem boundary:

- checkout outside `public_html` for CLI validation;
- private config outside the deployed project;
- private config exact canonical path and mode `0600`;
- private directory canonical and not group/world writable;
- JSON rollback data directory external, canonical and symlink-free;
- evidence and report files outside `public_html`;
- no symbolic-link path aliases.

Never paste or print DB host, DB name, username, password, bot token, admin identifiers or private absolute paths into GitHub, chat, logs or artifacts.

## Gate 3 — short-lived evidence approval

The lifecycle evidence collector requires a temporary explicit approval in private staging config:

```php
'staging_db_primary_evidence' => [
    'enabled' => true,
    'expected_database_identity_fingerprint' => '<exact-lowercase-64-hex>',
    'expected_repository_commit' => '<exact-lowercase-40-hex>',
    'approval_expires_at_utc' => '<exact-ISO-8601-seconds-with-offset>',
],
```

Rules:

- use the exact deployed commit;
- use the exact staging DB identity fingerprint;
- approval lifetime must not exceed two hours;
- no uppercase, whitespace padding or stringified booleans;
- approval must be created immediately before collection;
- do not reuse an approval from an earlier commit, DB or attempt.

## Gate 4 — collect fresh lifecycle evidence v4

Use a new non-existing private output path:

```bash
umask 077
php ops/runtime/collect-staging-db-primary-lifecycle-evidence.php \
  --output=/absolute/private/new-lifecycle-evidence-v4.json \
  --max-events=20
```

The collector must return success and the written file must:

- be exact manifest v4;
- have mode `0600`;
- belong to the exact deployed commit and staging DB identity;
- contain verified selector and request-session evidence;
- preserve JSON rollback availability;
- show no production, Cron or sensitive-data change.

Any warning, blocker, stale file, existing output path, cleanup failure or identity mismatch stops the sequence.

Immediately after successful collection, set the persistent `staging_db_primary_evidence.enabled` value back to exact boolean `false`. Keep persistent activation, selector and request session disabled.

## Gate 5 — CLI-only read-only API smoke

Create a new private report file from stdout. The smoke itself does not write the report:

```bash
umask 077
php ops/runtime/run-staging-db-primary-api-read-only-smoke.php \
  --evidence=/absolute/private/new-lifecycle-evidence-v4.json \
  --ttl-seconds=300 \
  > /absolute/private/new-read-only-smoke-report.json
chmod 0600 /absolute/private/new-read-only-smoke-report.json
```

The command temporarily enables activation, selector and request session only inside its own CLI process.

It must not:

- execute `bot/api.php` as an HTTP route;
- persist config changes;
- permit webhook execution;
- create a transaction or revision;
- execute a projection worker tick;
- change state, snapshot or outbox;
- change Cron or production.

The smoke report must contain all 43 exact fields and prove:

- `worker_tick_count = 0`;
- state revision equals completed outbox event count;
- projection contract matches the current all-module projector;
- lifecycle v4 and request finalizer completed;
- legacy JSON bridges were suppressed;
- state, snapshot, outbox and filters remained unchanged;
- every unsafe flag is exact boolean `false`.

## Gate 6 — verify the 43-field report

Use identities copied from the accepted evidence output, never hand-normalized values:

```bash
php ops/runtime/verify-staging-db-primary-api-read-only-smoke-evidence.php \
  --report=/absolute/private/new-read-only-smoke-report.json \
  --expected-commit=<exact-lowercase-40-hex> \
  --expected-database-identity=<exact-lowercase-64-hex> \
  --expected-evidence-fingerprint=<exact-lowercase-64-hex>
```

The verifier requires:

- exact canonical report path;
- report mode `0600`;
- parent directory not group/world writable;
- exact commit, DB identity and lifecycle evidence fingerprint;
- exact field schema and types;
- fresh valid timestamp;
- zero worker ticks and unchanged data fingerprints.

Only `staging_api_read_only_smoke_evidence_verified` is acceptance.

## Mandatory stop conditions

Stop without retrying a mutating operation when any of these occurs:

- hosted PHP 8.3 run is missing, skipped or failed;
- CI/staging commit mismatch;
- config or evidence path is noncanonical or permission-unsafe;
- approval identity or timestamp mismatch;
- evidence collector blocker;
- report verifier blocker;
- worker tick above zero;
- revision, state, snapshot or outbox changed;
- webhook, Cron or production flag is unsafe;
- any sensitive identifier appears in output.

After a blocker:

1. disable all staging approval latches;
2. preserve the private evidence for diagnosis;
3. do not run mutating smoke;
4. do not merge or cut over production;
5. fix code/config and restart from Gate 0 with a new exact commit and fresh evidence.

## Human checkpoint after success

A clean Gate 6 result allows preparation of a separate mutating staging rehearsal. It does not authorize running it automatically.

Before mutating staging or production work, review together:

- accepted commit and CI run;
- staging deployment identity;
- lifecycle evidence v4 summary;
- read-only smoke verification summary;
- rollback readiness;
- manual testing plan and maintenance window.
