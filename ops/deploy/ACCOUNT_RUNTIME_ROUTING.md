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

Then authenticate once through the staging Telegram bot and confirm the application opens the same user without creating a duplicate account.

## Safety rules

- production cannot enable this router;
- every later normalized DB module requires `accounts: true`;
- unknown module names fail boot;
- database connection or identity ownership errors fail closed;
- raw client session IDs are never stored.

## Rollback

Remove only the `database_runtime` block from staging `_private_mgw/runtime.php`, or set `enabled` to `false`.

Expected rollback health:

- `storage_driver: json`;
- `database_runtime.enabled: false`;
- `database_runtime.enabled_modules: []`.

No DB deletion, JSON restore, migration rollback or Cron change is required.
