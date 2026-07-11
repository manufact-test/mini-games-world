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

The runner may be called every hour. The service itself uses `Europe/Warsaw` by default and grants the weekly Match-room coin bonus only for the latest due Monday 12:00 cycle.

This avoids depending on the server operating-system timezone and remains correct across daylight-saving changes.

Safety rules:

- A player receives one welcome grant of `+50` coins to the Match-room balance on first real app activity. The cron does not create welcome grants for users who have not opened the app.
- Weekly bonus: `+50` coins to the Match-room balance by default.
- After the welcome grant, at least `3` completed games are required in the closed qualifying window.
- Any completed Mini Games World game counts: any game type, board size, and room.
- Matchmaking/search without a completed game does not count.
- Qualifying window: Monday 12:00 inclusive to the next Monday 12:00 exclusive.
- One weekly award per user and cycle key.
- Browser development users do not receive these grants.
- The first production weekly cycle starts at `2026-07-13 12:00:00 Europe/Warsaw` by default, so no older weeks are paid retroactively.
- Mini App requests also perform a per-user weekly catch-up, so a temporary cron failure does not permanently lose an earned bonus.

The HTTP fallback is protected by `setup_secret`:

```text
/bot/cron/weekly-match.php?key=YOUR_SETUP_SECRET
```

Prefer the CLI cron command. Do not expose the real secret in source control.
