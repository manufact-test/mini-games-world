# MVP-14.8.6g — staging DB/commit approval

Automated evidence collection can create staging-only schemas and process the staged JSON snapshot. `environment=staging` alone is not sufficient because a misconfigured staging config could point to another MySQL database.

The collector therefore refuses to open MySQL until a private short-lived approval matches:

- the exact `DatabaseConfig::identityFingerprint()` of the configured database;
- the exact 40-character repository commit;
- an offset-aware expiry no more than two hours ahead.

The database identity fingerprint is derived from driver, host, port, database name, username and charset. The password is deliberately excluded, so normal password rotation does not invalidate database identity.

## 1. Inspect the current target without opening MySQL

Run from the intended isolated staging checkout:

```bash
php ops/runtime/inspect-staging-db-primary-evidence-target.php
```

The inspector:

- accepts no arguments;
- requires environment `staging`;
- does not call `PdoConnectionFactory`;
- does not open JSON storage;
- prints no hostname, database name, username or password;
- returns only the repository commit, database identity fingerprint and safe approval summary.

Expected important fields:

```json
{
  "ok": true,
  "environment": "staging",
  "repository_commit": "<40-character Git SHA>",
  "database_enabled": true,
  "database_driver": "mysql",
  "database_identity_fingerprint": "<64-character SHA-256>",
  "database_connection_opened": false
}
```

Before approving, independently confirm in the hosting panel that the selected database is the isolated staging database and not the production database. The fingerprint proves the private config did not change between inspection and collection; it cannot determine business intent by itself.

## 2. Add a short-lived private approval

Add only to the external private staging config, never to GitHub:

```php
'staging_db_primary_evidence' => [
    'enabled' => true,
    'expected_database_identity_fingerprint' => '<exact fingerprint from inspector>',
    'expected_repository_commit' => '<exact commit from inspector>',
    'approval_expires_at_utc' => '<ISO-8601 timestamp no more than 2 hours ahead>',
],
```

Examples of accepted expiry forms:

```text
2026-07-20T18:00:00Z
2026-07-20T20:00:00+02:00
```

A timestamp without `Z` or an explicit offset is rejected. Expired approvals and approvals more than two hours ahead are rejected.

## 3. Re-run the inspector

```bash
php ops/runtime/inspect-staging-db-primary-evidence-target.php
```

The safe approval summary must show:

- `enabled: true`;
- `database_identity_fingerprint_configured: true`;
- `repository_commit_configured: true`;
- `approval_expiry_configured: true`.

The inspector does not claim the approval is valid because it intentionally does not execute collection. The collector performs the exact fingerprint, commit and expiry checks immediately before acquiring the rehearsal lock and before opening MySQL.

## 4. Run the collector

```bash
php ops/runtime/collect-staging-db-primary-evidence.php \
  --output=/absolute/private/path/staging-db-primary-evidence.json \
  --max-events=20
```

The output file must be outside deployment and must not already exist.

## 5. Disable the approval immediately

After success or failure, change:

```php
'enabled' => false,
```

Do not reuse the same approval for another checkout, database target or later attempt. Generate a fresh inspector report and a fresh short expiry each time.

## Fail-closed behavior

Collection stops before the rehearsal lock and before both MySQL connections when:

- approval is absent or disabled;
- the config section is malformed;
- `enabled` is not a strict boolean;
- the expected database fingerprint is invalid or mismatched;
- the expected commit is invalid or mismatched;
- expiry lacks an explicit offset;
- approval is expired;
- approval is valid for more than two hours;
- database configuration is disabled.
