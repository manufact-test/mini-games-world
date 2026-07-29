# MVP-14R.2.3 — Invites and Matchmaking JSON Baseline

## Purpose

This part freezes the current JSON-era behavior of invitation and matchmaking workflows before DB parity comparison is introduced. It is a code-and-CI-only package and is not loaded by the production bootstrap.

The baseline is built on the deterministic fixture, normalizer and scenario-result contracts introduced in MVP-14R.2.1.

## Covered scenarios

### 1. Direct invitation: create → accept → start

The baseline freezes:

- direct invitation creation in `pending` state;
- received-invitation notification for the invited player;
- acceptance as stored `awaiting_start` and public `accepted`;
- the 90-second owner start window;
- owner-only start;
- active private match creation;
- Match balance debit of both players only when the match starts;
- two `game_entry` ledger records and one `game_start` record;
- both invitation notifications being marked read when the match starts.

### 2. Link invitation: draft → shared → opened → cancelled

The baseline freezes:

- link invitation creation in `draft` state;
- confirmation changing the invitation to `pending`;
- the first valid opponent binding to the token;
- the link-open notification being hidden and marked read;
- owner cancellation;
- cancellation notification for the bound opponent;
- no balance or ledger mutation before a match starts.

Telegram prepared-message creation is deliberately outside this fixture because it is a network side effect. The server-side share lifecycle and generated invitation state are covered.

### 3. Rematch: create → reuse → automatic start

The baseline freezes:

- rematch availability only from a finished human game;
- one open rematch per source game and participant pair;
- a second request from the invited participant reusing the existing invitation;
- rematch acceptance automatically starting the new match;
- `match_source=rematch` and source-game linkage;
- the original finished game remaining unchanged;
- one debit per player and one game-start ledger record.

### 4. Matchmaking: queue → cancel

The baseline freezes:

- one queue entry per user;
- Match room bet normalization to 10 coins;
- user status changing to `searching`;
- cancellation removing the owned queue entry;
- user status returning to `idle`;
- no balance or ledger mutation.

### 5. Matchmaking: exact human match

The baseline freezes:

- human matching by exact room, bet, board size and game type;
- an existing compatible human being preferred;
- queue consumption;
- both users changing to `playing`;
- both Match balances being debited by 10 coins;
- one active human game;
- two `game_entry` ledger records and one `game_start` record.

### 6. Matchmaking: bot fallback

The baseline freezes:

- bot fallback only in the Match room;
- the configured waiting threshold;
- one final human-opponent check before creating a bot game;
- queue consumption;
- only the human Match balance being debited;
- deterministic fixture bot identity, name and difficulty;
- one `game_entry` ledger record and one `game_start` record.

The deterministic bot fixture does not replace runtime randomness. It makes the baseline repeatable so DB and JSON results can be compared later.

## Captured domains

Every scenario records exact before/after snapshots for:

- users and balances;
- matchmaking queue;
- games;
- invitations;
- notifications;
- transaction and ledger records.

The scenario result also records public response payloads and explicit notification/event/ledger side effects.

## Determinism

Fixtures provide:

- a fixed UTC clock;
- bounded deterministic ID sequences;
- stable user, invitation, queue, game, notification and transaction identities;
- explicit volatile-value normalization rules;
- frozen SHA-256 scenario fingerprints.

Each workflow is executed twice from the same original fixture. The package fails if the response, final domains or side effects differ.

## Conflict boundaries

The source-contract test freezes these product rules:

- pending or accepted invitations block normal matchmaking;
- only the invited player may accept;
- only the inviter may start a normal accepted invitation;
- a pending invitee must decline rather than cancel;
- expired invites are not treated as open blockers;
- bots are unavailable in Gold matchmaking;
- an exact live human opponent is preferred before bot fallback.

## Latency

Latency remains intentionally unmeasured in MVP-14R.2.3:

```text
measured=false
samples=0
```

Cold/warm measurements and percentile evidence are reserved for the dedicated latency part of MVP-14R.2.

## Files

- `bot/baseline/JsonInviteMatchmakingBaselineScenario.php`
- `bot/baseline/JsonInviteMatchmakingInviteTrait.php`
- `bot/baseline/JsonInviteMatchmakingQueueTrait.php`
- `bot/baseline/JsonInviteMatchmakingProjectionTrait.php`
- `bot/tests/Mvp14r2InviteMatchmakingBaselineTest.php`
- `bot/tests/Mvp14r2InviteMatchmakingContractTest.php`
- six fixtures under `bot/tests/fixtures/mvp14r2/`
- `ops/checks/mvp14r2-invites-matchmaking-local.sh`

## Safety boundary

This package does not:

- load from the production bootstrap;
- contact production;
- connect to a database;
- call Telegram or any external service;
- write live JSON;
- change private configuration;
- change webhook registration or Cron;
- deploy code;
- update issue #174.
