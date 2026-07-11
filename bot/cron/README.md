# Mini Games World cron jobs

## Weekly Match economy

Runner:

```text
bot/cron/weekly-match.php
```

Recommended Hostinger schedule:

```cron
0 * * * * php /FULL/PATH/TO/public_html/bot/cron/weekly-match.php
```

The runner may be called every hour. The service itself uses `Europe/Warsaw` by default and grants the weekly Match bonus only for the latest due Monday 12:00 cycle.

This avoids depending on the server operating-system timezone and remains correct across daylight-saving changes.

Safety rules:

- `+50` Match coins by default.
- At least `3` completed Match-room games in the closed qualifying window.
- Qualifying window: Monday 12:00 inclusive to the next Monday 12:00 exclusive.
- One award per user and cycle key.
- Gold-room games do not count.
- Browser development users do not receive the bonus.
- The first production cycle starts at `2026-07-13 12:00:00 Europe/Warsaw` by default, so no older weeks are paid retroactively.
- Mini App requests also perform a per-user catch-up, so a temporary cron failure does not permanently lose an earned bonus.

The HTTP fallback is protected by `setup_secret`:

```text
/bot/cron/weekly-match.php?key=YOUR_SETUP_SECRET
```

Prefer the CLI cron command. Do not expose the real secret in source control.
