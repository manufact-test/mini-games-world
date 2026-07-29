# MVP-14R.2.2 — Account and passive-flow JSON baseline

## Status

Code-and-CI baseline package for account and passive read behavior.

This part does not measure latency. Latency remains explicitly marked as `measured=false` until the dedicated latency phase.

## Frozen scenarios

### `account.bootstrap.idle`

Captures the passive account bootstrap projection:

- public user fields;
- Match and Gold balances;
- turnover-limited Gold shop availability;
- passive session state;
- unread notification count.

Frozen fingerprint:

`dd6b4b69b0549f2035d7445341572d635bfaa0e171e797ccd02fbfe4780bf725`

### `account.profile.finished-games`

Captures profile statistics recalculated from finished games owned by the user. Stale counters stored in `user.stats` are deliberately ignored.

Frozen result:

- games played: 3;
- wins: 1;
- losses: 1;
- draws: 1;
- Match games: 2;
- Gold games: 1.

Frozen fingerprint:

`78168a0acee8c635bac048905650642efae911ceda613438099e8d65deac24c6`

### `passive.session.secondary-lock`

Captures a passive read from a second device while another session owns an active game.

Frozen behavior:

- `locked=true`;
- the active owner session remains unchanged;
- the reviewed Russian lock message remains exact;
- no domain mutation occurs.

Frozen fingerprint:

`230f4682d8a4e9b7b00bf34d653f4ee52f2353fcba6940d93695dc0a1c23ce4c`

### `passive.notifications.visibility-order`

Captures notification read behavior:

- another user's notifications are excluded;
- hidden notifications are excluded;
- newest timestamp sorts first;
- equal timestamps use descending notification ID;
- unread count ignores read and hidden items.

Frozen fingerprint:

`5d80dba2af3fd8b7b860d47c4fe65cb75d1334e888278bdab4037db359958bf0`

## Read-only boundary

Every scenario records exact before/after domain snapshots for:

- account;
- user-owned games;
- user-owned notifications.

The runner fails closed if a passive projection changes any snapshot. It also repeats every scenario from the original fixture and fails if the public result or domain snapshot changes.

## Production isolation

The scenario runner is not loaded from the production bootstrap. The focused package contains no:

- production or database connection;
- network call;
- Telegram call;
- live JSON write;
- private configuration update;
- webhook or Cron operation;
- deployment command.

## Verification

Focused command on PHP 8.3:

```bash
bash ops/checks/mvp14r2-account-passive-local.sh
```

The full repository CI remains the authoritative final gate.
