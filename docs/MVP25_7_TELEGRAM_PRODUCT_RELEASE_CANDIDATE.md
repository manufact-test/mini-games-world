# MVP-25.7 — Telegram Product Release Candidate

**Status:** RC CANDIDATE / AUTOMATED PROOF PENDING / HUMAN ACCEPTANCE PENDING  
**Base staging:** `a96b4adec2c536442eb4a45d7e14549f1f8c5bec`

## Purpose

MVP-25.7 is the final Release Candidate gate for the finished Russian Telegram Mini App.

It does not add product features. It proves that the product already accepted through
MVP-25.1–25.6 remains coherent as one release candidate before MVP-25 can close.

Real-money/provider integration is intentionally outside MVP-25. Android work starts only
after MVP-25 is closed.

## RC invariants

The candidate must preserve:

- canonical Telegram launch through `/app/v110.php`;
- all eight accepted games and frozen mechanics;
- matchmaking, rematch, Friends and invite lifecycle;
- unified MGW coin balance and cosmetic Store/Profile ownership;
- Arena rating, archives and official tournament lifecycle;
- Support, moderation, anti-fraud, Admin operations and account-data lifecycle;
- notification and reconnect behavior;
- MVP-24 final Gold retirement and archive-only legacy commerce;
- MVP-25.2 human-facing copy closure;
- MVP-25.3 final UX closure;
- MVP-25.4 accepted performance/reliability state and its documented known residual;
- MVP-25.5 security/resilience closure;
- MVP-25.6 monetization-disabled complete-product mode;
- tracked-secret and staging-isolation boundaries.

## Automated RC proof

The focused MVP-25.7 gate runs:

1. every MVP-25.1 corrective contract;
2. final MVP-25.2 through MVP-25.6 closure contracts;
3. eight-game frozen mechanics regression;
4. unified economy / inventory / Store / Profile regression;
5. Friends / invites / rematch / notifications regression;
6. Arena rating and full official tournament release proof;
7. Support / moderation / anti-fraud / Admin / account-data / incident regression;
8. reconnect / current E2E owner regression;
9. final MVP-24 Gold-removal regression boundary;
10. tracked repository secret scan;
11. critical MySQL persistence proofs for archive, support, compensation, moderation,
    anti-fraud, incident recovery and tournaments.

The RC branch is proof-only. Runtime, UI, economy, payments, games, tournaments, database
migrations and Cron are frozen by scope guard.

## Staging acceptance

After the proof-only PR merges, the exact merged staging SHA must pass the canonical
`Staging Playwright E2E` route:

- Hostinger deployment readiness;
- Telegram webhook reconciliation;
- managed migrations and projections;
- TEST PLAYER A/B two-context browser flow;
- final commit status.

Only after that exact staging proof does the candidate proceed to the human checklist in
`docs/MVP25_7_MANUAL_ACCEPTANCE.md`.

## Closure rule

MVP-25.7 closes only when:

- the automated RC gate is green;
- the exact merged staging SHA has green canonical E2E;
- the product owner completes the final human regression and explicitly accepts the RC.

Until the human result is recorded, MVP-25 remains open.
