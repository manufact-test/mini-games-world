# MVP-14.8.2c — staging notification DB read routing

This step moves authenticated notification reads to normalized MySQL/MariaDB in staging while JSON remains the notification creation source and immediate rollback source.

It does not enable production routing, change the notification UI, switch invites, or change shop/payment behavior.

## Deployment before activation

Deploy the code while the staging private runtime file still enables only `accounts`.

`/bot/health.php` must show:

- build `v92-mvp14-db-notification-routing`;
- environment `staging`;
- `storage_driver: json`;
- `database_runtime.enabled: true`;
- `database_runtime.enabled_modules: ["accounts"]`;
- `database_runtime.production_allowed: false`.

No Cron is needed for this deployment check.

## Staging activation

Edit only staging `_private_mgw/runtime.php`. Preserve all existing settings and change the database runtime block to:

```php
'database_runtime' => [
    'enabled' => true,
    'modules' => [
        'accounts' => true,
        'notifications' => true,
    ],
],
```

After saving, health must remain `ok: true` and show enabled modules:

```text
accounts
notifications
```

The global storage driver must still be `json` and production routing must remain false.

## Live staging check

1. Open the Mini App through the staging Telegram bot.
2. Open the existing notification center once.
3. Confirm the current notification list loads without an error or duplicate card.
4. Use the existing mark-all-read action once.
5. Reopen the notification center and confirm the unread count remains zero.
6. Run the temporary read-only audit below.

The notification endpoint updates JSON read state first, synchronizes the same state to DB, then serves the authenticated notification rows from DB after strict parity checks. Invite visibility and action buttons still use the current JSON invite state.

## Temporary read-only audit

Run once in staging after the live check:

```bash
/usr/bin/php /absolute/path/public_html/ops/deploy/notification-runtime-audit.php --expected-users=1
```

Expected report:

```text
ok: true
read_only: true
expected_user_count: 1
audited_user_count: 1
parity: true
sensitive_identifiers_exposed: false
blockers: []
storage_driver: json
database_runtime.enabled_modules: [accounts, notifications]
execution_mode: read-only
```

When Hostinger Cron is used to launch the command, create it only temporarily with `*/5 * * * *`, wait for one output, and delete that exact temporary Cron immediately. Do not change permanent backup or managed-migrations Cron jobs.

## Fail-closed conditions

The request or audit fails if any of these occurs:

- accounts or notifications DB routing is disabled unexpectedly;
- account ownership is missing, inactive or belongs to another MGW-ID;
- JSON notification IDs or event keys are duplicated;
- an existing DB notification differs from the JSON rollback source;
- DB read/hidden state is ahead of JSON;
- JSON and DB notification counts or fingerprints differ;
- unmanaged notification rows exist for the authenticated account.

## Rollback

Edit only staging `_private_mgw/runtime.php` and remove `notifications` or set it to `false`:

```php
'database_runtime' => [
    'enabled' => true,
    'modules' => [
        'accounts' => true,
        'notifications' => false,
    ],
],
```

Expected rollback health:

- `storage_driver: json`;
- `database_runtime.enabled: true`;
- `database_runtime.enabled_modules: ["accounts"]`;
- `database_runtime.production_allowed: false`.

Do not delete DB notification rows, restore JSON, roll back migrations, or disable account routing. JSON already contains the authoritative notification creation and read state for this substep.
