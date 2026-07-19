# MVP-14.8.4a — staging freeze and drain rehearsal

This substep introduces the reversible stop-new-match window required before a final delta or storage switch rehearsal.

It does not switch production, restore live data, modify database rows, or enable missing DB runtime modules.

## What freeze changes

The CLI writes one private control file outside `public_html`:

```text
_private_mgw/cutover-rehearsal.json
```

On the next request, `RuntimeConfigLoader` applies only these temporary overrides:

- new matchmaking is disabled;
- new invitations and rematches are disabled;
- active game state, moves, reconnect, settlement and history remain available;
- payments and shop are not changed by this substep.

The freeze command also clears the current matchmaking queue and resets only users with `status=searching` back to `idle`. It never edits an active game.

## Commands on staging

Status only:

```bash
/usr/bin/php /home/u235811320/domains/seashell-okapi-889488.hostingersite.com/public_html/ops/deploy/freeze-drain-rehearsal.php --status
```

Activate freeze:

```bash
/usr/bin/php /home/u235811320/domains/seashell-okapi-889488.hostingersite.com/public_html/ops/deploy/freeze-drain-rehearsal.php --freeze
```

Require a fully drained state. This exits with code `2` while active games or queue/search state remain:

```bash
/usr/bin/php /home/u235811320/domains/seashell-okapi-889488.hostingersite.com/public_html/ops/deploy/freeze-drain-rehearsal.php --status --require-drained
```

Release freeze:

```bash
/usr/bin/php /home/u235811320/domains/seashell-okapi-889488.hostingersite.com/public_html/ops/deploy/freeze-drain-rehearsal.php --release --reason="staging rehearsal completed"
```

Hostinger may run each command through one temporary five-minute Cron. Delete every temporary rehearsal Cron after its single output. Permanent backup and managed-migration Cron jobs must not be changed.

## Required freeze result

- `environment: staging`;
- `freeze.active: true`;
- `freeze.new_matchmaking_blocked: true`;
- `freeze.new_invitations_blocked: true`;
- `freeze.active_game_actions_allowed: true`;
- `drain.queue_entries: 0`;
- `drain.searching_users: 0`;
- existing active games remain counted until they finish;
- `production_changed: false`;
- `sensitive_identifiers_exposed: false`.

The drain is ready only when:

- active games are zero;
- queue entries are zero;
- searching users are zero;
- freeze remains active.

Open invitations are reported but do not block the drain because the freeze prevents them from starting a new match. Users may still decline or cancel them.

## Current full-switch blocker

The staging DB router currently covers accounts, realtime, invitations and notifications. A complete product storage switch rehearsal also requires DB runtime paths for:

- economy;
- history;
- shop;
- payments;
- weekly bonus.

The CLI reports these under `database_runtime.missing_modules` and keeps `switch_rehearsal.ready=false`. Do not bypass this guard and do not call the freeze/drain result a completed DB cutover rehearsal until those paths exist and pass regression.

## Recovery

The release command marks the private control state as `released`. The next request again uses the normal private `runtime.php` flags.

If a rehearsal process is interrupted, run `--release` first and confirm with `--status`:

- `freeze.active: false`;
- matchmaking and invitations use their normal runtime settings;
- global and rollback storage remain `json`.

Do not delete DB data or migrations during release. Keep the private history file for audit:

```text
_private_mgw/cutover-rehearsal-history.jsonl
```
