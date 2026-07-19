# MVP-14.8.5 — production preflight

This step is read-only. It does not enable DB routing, freeze traffic, change runtime flags, migrate data or switch production.

## Automatic execution without a new Cron

While build `v102-mvp14-production-preflight` is deployed, the existing production backup command runs the preflight immediately after a successful primary and external backup. The existing production backup Cron command and schedule are not changed.

The backup JSON keeps its normal top-level `ok`, `backup_id`, checksum and external-copy fields. A new nested `production_preflight` object contains the read-only report.

## Manual CLI fallback

```bash
php ops/deploy/production-preflight.php --run
```

The command is CLI-only and production-only. It refuses staging/local execution and refuses every build except the exact preflight build.

## Checks

- environment is exactly `production`;
- global storage remains JSON;
- production DB routing is still disabled;
- DB connection and migration status are healthy;
- JSON data and private runtime files are readable/restorable;
- no stale cutover control or JSON write barrier exists;
- latest primary and external backups verify by checksum;
- both copies have the same backup ID and snapshot SHA-256;
- both backups belong to production and are fresh;
- active games, queue, invitations, searching/playing users are drained;
- pending or unknown payment/shop states are absent;
- restore and verify utilities are present;
- report contains only aggregates and fingerprints.

## Result contract

A clean report has:

- `ok: true`;
- `technical_ready_for_window: true`;
- `rollback_checklist.ok: true`;
- `blockers: []`;
- a stable `cutover_plan_fingerprint`;
- `production_switch_allowed: false`;
- `production_switch_performed: false`;
- `production_changed: false`.

Even a clean report does not authorize cutover. A separate exact maintenance-window approval is required later.

## Backup age

The default maximum backup age is 108000 seconds (30 hours). It can be overridden privately:

```php
'production_preflight' => [
    'max_backup_age_seconds' => 108000,
],
```

## Rollback

Revert the preflight release. No data rollback is required because this step does not modify product data or runtime routing.
