# MVP-14R.2.4 — deterministic games JSON baseline

## Purpose

This part freezes the current JSON behavior of all eight released game engines before later migration or optimization work changes their storage path. It is a code-and-CI-only harness and is not loaded by the production bootstrap.

## Covered engines

The package contains one deterministic scenario per game:

1. Tic-tac-toe — an invalid out-of-turn action, the final legal move, draw settlement, retry after completion and rematch projection.
2. Four in a Row — an invalid column, a vertical four-disc win, winning-cell projection, settlement retry and rematch projection.
3. Battleship — a repeated-shot rejection, the final ship hit, sunk-result projection, settlement retry and rematch projection.
4. Checkers — mandatory-capture rejection, the final capture, captured-cell projection, settlement retry and rematch projection.
5. Reversi — illegal placement rejection, the final legal flip, result by piece count, settlement retry and rematch projection.
6. Chess — an out-of-turn rejection, a legal move, deterministic timer observation, timeout settlement, retry and rematch projection.
7. Go — occupied-point rejection, a capture, two consecutive passes, final score, settlement retry and rematch projection.
8. Domino — unavailable-tile rejection, an empty-hand winning play, final points, settlement retry and rematch projection.

## Fixture strategy

Complex games start from a valid stored position close to a decisive action. This is intentional: the baseline verifies the authoritative rule boundary, public result and settlement without turning CI into a full-match simulator. The source-contract test separately binds every modelled rule to its current production owner.

Every fixture uses:

- a fixed UTC clock;
- deterministic IDs for transactions and other generated records;
- a fixed input state;
- an expected rejected action that must not mutate any domain;
- a legal action or timeout that produces the result;
- a repeated post-result action that must not settle twice;
- a human-game rematch projection;
- a frozen SHA-256 fingerprint.

## Shared settlement contract

The scenarios freeze these cross-game rules:

- `payout_done` and finished status prevent duplicate settlement;
- a draw refunds each player’s stake and charges no commission;
- a winner receives the bank minus `ceil(bank × commission_rate)`;
- Match or Gold balances change exactly once;
- players return to `idle` and `current_game_id` becomes `null`;
- statistics change exactly once;
- one `game_finish` transaction is emitted;
- rematch is available only for a finished two-player human game.

## Timer contract

Timer evidence in this part is deterministic functional behavior, not latency benchmarking. Public `time_left` is derived from the fixed fixture clock and `turn_started_at`; timeout settlement occurs only after the configured move timeout.

The result therefore keeps:

- `latency.measured = false`;
- `samples = 0`;
- `cold_ms = null`;
- `warm_ms = null`.

Latency measurements belong to MVP-14R.2.6.

## Safety boundary

The package does not:

- load from the production bootstrap;
- contact production, a database, Telegram or any network service;
- write live JSON or private configuration;
- change webhook registration or Cron;
- deploy anything;
- update issue #174.
