# Legacy realtime shadow sync

This CLI-only tool copies the current legacy JSON realtime sections into a server-only database shadow table.

Copied sections:

- `games`;
- `queue`;
- `invites`;
- `notifications`.

## Safety boundary

- JSON remains the authoritative product storage;
- no gameplay, matchmaking, invitation or notification read path uses the shadow table;
- the copy is exact canonical JSON plus SHA-256;
- records removed from JSON are pruned from the shadow table;
- duplicate source identities fail closed;
- production is blocked unless both private approval and `--allow-production` are present;
- the command refuses to run while database migrations are pending;
- a private process lock prevents overlapping runs.

## Commands

Status/preview without writes:

```bash
/usr/bin/php /absolute/path/public_html/ops/realtime/shadow-sync.php --status
```

Explicit dry-run:

```bash
/usr/bin/php /absolute/path/public_html/ops/realtime/shadow-sync.php --dry-run
```

Apply the shadow copy:

```bash
/usr/bin/php /absolute/path/public_html/ops/realtime/shadow-sync.php --run
```

Do not create a permanent Cron until the staging preview, first run, repeated no-op and deletion reconciliation have all been verified.
