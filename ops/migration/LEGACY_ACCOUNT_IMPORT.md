# Controlled legacy account import

This MVP-14.7 staging step creates provider-neutral `mgw_users` rows and reserves one opaque MGW-ID per legacy JSON user.

It intentionally uses the internal identity provider `legacy_import` first. The real `telegram` or `development` identity is attached in the next atomic ownership-mapping step, because opening balances and immutable ledger entries were already imported under `legacy:<id>` account references.

## Commands

```bash
php ops/migration/legacy-account-import.php --status
php ops/migration/legacy-account-import.php --dry-run
php ops/migration/legacy-account-import.php --run
```

`--status` and `--dry-run` only inspect the verified JSON/economy-shadow source and current database target.

## Imported fields

- opaque random MGW-ID;
- active account status;
- display name and username;
- external avatar reference when present;
- original registration and last-seen timestamps;
- internal `legacy_import` identity linking the old user ID to the MGW-ID.

## Verification sequence

1. Dry-run must return `ready=true`, zero conflicts and zero unmanaged legacy links.
2. First run creates the missing users and `legacy_import` links.
3. Repeat run creates nothing and reports all users unchanged.
4. The staging reconciliation report must remain healthy; only the real provider-identity gap remains expected.
5. Delete only the temporary account-import Cron.

## Safety

- JSON remains authoritative and is never written;
- existing Match/Gold balances and ledger hashes are not edited;
- real Telegram login mapping is not switched yet;
- sessions/devices are not created;
- production requires both private approval and `--allow-production`;
- `/app`, `/site`, games, payments and shop are untouched.
