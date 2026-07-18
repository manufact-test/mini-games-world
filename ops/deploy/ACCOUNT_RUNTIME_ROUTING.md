# MVP-14.8.2b — staging account identity routing

This step makes the existing normalized MGW account identity path explicitly controlled by the staging DB runtime router.

It does not switch the global storage driver. JSON remains the product runtime and rollback source.

## Deployment state before activation

After deploying the code with no private flag changes, `/bot/health.php` must show:

- build `v91-mvp14-db-account-routing`;
- `storage_driver: json`;
- `database_runtime.enabled: false`;
- `database_runtime.enabled_modules: []`.

## Staging activation

Edit only the staging private file `_private_mgw/runtime.php` and preserve every existing setting. Add or merge:

```php
'database_runtime' => [
    'enabled' => true,
    'modules' => [
        'accounts' => true,
    ],
],
```

The file returns feature flags directly; do not wrap this block in another `feature_flags` key.

After saving, `/bot/health.php` must show:

- `ok: true`;
- `environment: staging`;
- `storage_driver: json`;
- `database_runtime.enabled: true`;
- `database_runtime.enabled_modules: ["accounts"]`;
- `database_runtime.production_allowed: false`.

## Live staging proof

Authenticate once through the staging Telegram bot, then run the CLI-only read-only audit:

```bash
php ops/deploy/account-runtime-audit.php --expected-users=1 --recent-minutes=20
```

The successful report must show:

- `ok: true`;
- `read_only: true`;
- exactly one MGW user, Telegram identity and account ownership;
- Telegram identity and ownership resolve to the same MGW account;
- zero duplicate provider subjects;
- zero orphan identity or ownership rows;
- recent Telegram authentication observed;
- at least one device and active session;
- `sensitive_identifiers_exposed: false`;
- `storage_driver: json`;
- DB runtime enabled only for `accounts`;
- `blockers: []`.

The report emits only SHA-256 fingerprints for provider and ownership identities. It never returns the raw Telegram subject or raw client session ID.

On Hostinger, this command may be run through one temporary five-minute Cron that writes its output into the private staging directory. Remove that temporary Cron immediately after collecting the result. Permanent backup and managed-migration Cron jobs must not be changed.

## Safety rules

- production cannot enable this router;
- every later normalized DB module requires `accounts: true`;
- unknown module names fail boot;
- database connection or identity ownership errors fail closed;
- raw client session IDs are never stored;
- the audit is staging-only, CLI-only and read-only.

## Rollback

Remove only the `database_runtime` block from staging `_private_mgw/runtime.php`, or set `enabled` to `false`.

Expected rollback health:

- `storage_driver: json`;
- `database_runtime.enabled: false`;
- `database_runtime.enabled_modules: []`.

No DB deletion, JSON restore, migration rollback or permanent Cron change is required.
