# MVP-14.8.2a — staging-only DB runtime router

This step adds the routing contract used to move individual backend modules from
JSON to normalized MySQL/MariaDB without a config-only global switch.

It does not move any module by itself. JSON remains the global runtime source and
the immediate rollback driver.

## Private runtime flag

The flag is loaded from the existing private `_private_mgw/runtime.php` file:

```php
<?php
return [
    'database_runtime' => [
        'enabled' => false,
        'modules' => [
            'accounts' => false,
            'realtime' => false,
            'invites' => false,
            'notifications' => false,
            'economy' => false,
            'history' => false,
            'shop' => false,
            'payments' => false,
            'weekly_bonus' => false,
        ],
    ],
];
```

Do not enable a module until its DB read/write implementation and rollback test
exist in code.

## Hard guards

- routing can be enabled only in `staging` or `local`;
- the global `storage_driver` must remain `json`;
- the database config must be enabled;
- unknown module names fail boot;
- production routing is rejected even when a private flag is set;
- rollback driver is always reported as `json` during MVP-14.8.2.

## Health evidence

`/bot/health.php` exposes:

```text
runtime.database_runtime.enabled
runtime.database_runtime.default_driver
runtime.database_runtime.rollback_driver
runtime.database_runtime.enabled_modules
runtime.database_runtime.production_allowed
```

Expected initial staging state after deployment:

```text
enabled: false
default_driver: json
rollback_driver: json
enabled_modules: []
production_allowed: false
```

No Cron is required for this step.

## Next implementation steps

1. add DB-backed reads per module, starting with notifications/invites;
2. add DB-backed writes and parity checks for that module;
3. enable only that module in staging;
4. run live regression and disable the flag immediately on mismatch;
5. repeat for accounts, realtime, economy/history, shop/payments and weekly bonus.

A production global DB switch remains forbidden until MVP-14.8.2–14.8.5 are
closed and the user approves the production window.
