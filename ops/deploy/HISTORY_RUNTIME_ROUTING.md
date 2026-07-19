# MVP-14.8.4e — staging history DB runtime

This stage moves profile and history reads onto verified database shadow data while JSON remains the global runtime and immediate rollback source.

## Scope

- history runtime is allowed only when `accounts`, `realtime` and `economy` DB modules are already enabled;
- each history read synchronizes the current JSON games and transactions into their verified shadow rows before formatting the response;
- the existing Russian titles, descriptions, ordering, deduplication and limits remain unchanged;
- database shadow payload hashes are verified before they are used;
- parity is checked for every current JSON user without exposing user identifiers;
- production routing is forbidden and `storage_driver` remains `json`.

## One-shot staging activation

After deployment run one temporary Cron every 5 minutes:

```bash
/usr/bin/php /home/u235811320/domains/seashell-okapi-889488.hostingersite.com/public_html/ops/deploy/history-runtime-activate.php --run
```

The command:

1. verifies staging, build, JSON rollback storage, database connectivity and schema;
2. backs up private `runtime.php`;
3. enables only `database_runtime.modules.history`;
4. synchronizes realtime and economy shadow data;
5. compares formatted JSON and database histories for every source user;
6. removes the backup after success;
7. restores the previous runtime configuration automatically on any failure;
8. becomes an idempotent no-op after completion.

Expected result:

- `ok: true`;
- `state: completed`;
- `module_enabled: true`;
- audit `mismatch_count: 0`;
- JSON and database history fingerprints equal;
- blockers empty;
- production unchanged;
- sensitive identifiers not exposed.

Delete the temporary Cron after the first completed result.

## Rollback

```bash
/usr/bin/php /home/u235811320/domains/seashell-okapi-889488.hostingersite.com/public_html/ops/deploy/history-runtime-activate.php --disable --reason=manual_staging_rollback
```

Rollback disables only the history module. JSON data, shadow rows, economy ledger rows and other enabled DB modules remain untouched.
