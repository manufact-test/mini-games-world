# MGW CURRENT SHORT ROADMAP — MVP-21.10 CLOSED / NEXT MVP-21.11

**Date:** 2026-09-22  
**Repository:** `manufact-test/mini-games-world`  
**Authoritative staging branch:** `agent/mvp-13-2-staging`  
**Runtime PR merged:** **#1680**  
**Runtime staging SHA:** `e71d6c5bc968cbd0f6895816e74c6d3839ec4da2`  
**MVP-21.1–21.10:** IMPLEMENTED  
**MVP-21.11:** OPEN  
**MVP-21 overall:** OPEN

## Current implementation state

MVP-21.1 through MVP-21.10 are implemented in staging.

The latest completed slice, MVP-21.10, adds the canonical prize-path anti-fraud layer:

- serious-signal review only for top-3 or explicitly flagged tournament matches;
- no automatic hold just because a player is top-3;
- provisional hold only on the affected prize path;
- durable pending/released/disqualified review state;
- durable audit trail;
- Tournament Admin actions to flag, release and disqualify;
- deterministic placement shift after disqualification;
- terminal player UX for review hold / disqualification;
- exactly-once reward release through the existing `TournamentSettlementService`;
- no second payout/reward/Golden-Ticket writer;
- late signal after settlement starts fails closed instead of introducing clawback.

### Ownership that must not be changed in MVP-21.11

- `TournamentRoundProgressionService`: bracket/round/draw/technical progression.
- `TournamentSettlementService`: final tournament result, entry consumption, payout, reward entitlements and Golden Ticket writer.
- `TournamentPrizeReviewService`: anti-fraud review state and audit only.
- `TournamentCancellationService`: cancellation/emergency/refund/annulment owner.
- `TournamentRewardProjectionService`: read-only reward projection.
- Existing eight game engines remain frozen.

## Verification checkpoint

PR #1680 final head:

`188c54eff59bc0908a381af07b0afb48818e99`

Correction: authoritative final PR head is:

`188c54eff59bc0908a381af7110e92a086a91dfc`

Merged staging:

`e71d6c5bc968cbd0f6895816e74c6d3839ec4da2`

The final PR candidate passed the focused MVP-21.10 SQLite and MySQL suites plus preserved MVP-21.5–21.9, rating/Profile/Hall and all-games regression workflows.

Staging E2E run **35778351182**:

- exact Hostinger deployment — success;
- managed migration `20260922_0060_create_tournament_prize_review` — success;
- preflight — success;
- first attempt browser suite reached the core lifecycle but GitHub OIDC returned HTTP 503;
- Linux job rerun then completed the full two-context browser body successfully;
- dependent final-status publisher kept the original first-attempt output and therefore left the aggregate run/status red.

Treat this as a CI rerun-output quirk, not as product acceptance. MVP-21.11 must include a clean release-proof workflow and leave an unambiguous green final gate.

## MVP-21.11 — exact next task

Build one explicit tournament release-proof layer that verifies the already implemented owners rather than inventing a new tournament state machine.

### Capacity matrix

Prove complete lifecycle for:

- 8 players;
- 16 players;
- 32 players;
- 64 players;
- 128 players.

For each capacity prove:

- exact first-round pair count;
- automatic propagation through every elimination round;
- final + third-place match are created;
- tournament reaches terminal state automatically;
- terminal placements are complete;
- settlement creates exactly one durable result per registered participant;
- all reservations leave active state;
- repeated settlement does not duplicate ledger entries, entitlements or Golden Ticket ownership;
- no direct/manual DB repair is required during the simulated lifecycle.

### Eight-game matrix

Prove tournament integration preserves all accepted games:

- tictactoe;
- four_in_a_row;
- battleship;
- checkers;
- reversi;
- chess;
- go;
- domino.

Do not rewrite their mechanics. Use the existing frozen all-game fixtures/contracts as the game-specific behavioral authority and prove tournament metadata/lifecycle accepts every game type.

### Required branch scenarios

The 21.11 suite must also cover:

- draw → 1-minute replay → side swap → no repeat entry fee;
- one-sided no-show/preparation timeout;
- one disconnect → 60-second return window;
- both disconnect → 3-minute return window;
- manual leave;
- both absent;
- restartable server/game failure;
- exhausted technical restart → cancel-required path;
- normal cancellation with double confirmation;
- emergency stop with mandatory reason;
- full refund + result annulment + durable audit;
- serious anti-fraud signal + hold + release;
- serious anti-fraud signal + disqualification + placement shift;
- synthetic staging participants remain reward-ineligible;
- date/time/localization contracts;
- duplicate settlement/retry safety.

### CI/release-proof shape

Add a dedicated MVP-21.11 workflow that:

- runs focused SQLite release-proof;
- runs the large-capacity owner on MySQL 8.4;
- preserves MVP-21.1–21.10 focused suites;
- preserves frozen eight-game mechanics;
- guards against changes to game engines / independent ledger writers;
- provides one clear final pass/fail gate.

## After MVP-21.11

Only after MVP-21.11 is merged and green, perform one consolidated manual Telegram acceptance of the whole MVP-21.

Manual pass should cover the full real UX: registration/reservation, rules, scheduling/reminders, Hall, bracket, Ready/countdowns, round breaks, draw replay, technical outcomes, final/third, terminal rewards, Profile/Hall projections, cancellation/emergency and anti-fraud Admin review.

Any reproduced problem is fixed after that integrated pass. Then MVP-21 can be marked CLOSED.
