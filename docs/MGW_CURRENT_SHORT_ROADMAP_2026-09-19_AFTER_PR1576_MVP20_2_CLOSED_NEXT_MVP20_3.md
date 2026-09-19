# MGW CURRENT SHORT ROADMAP — 2026-09-19 — AFTER PR #1576 — NEXT MVP-20.3

## Current checkpoint

- Staging: `28bef588a7161efddf5d88e3a6a41de85d9bcc22`
- Tree: `f30ba90828ae88d8bd86f30bdc2df674cfe87b19`
- MVP-20.1 visible per-game rating: done on staging.
- MVP-20.2 hidden per-game skill/MMR: done on staging.
- Final staging E2E rerun: green.
- Competition launch state remains PRESEASON.
- No production activation.

## Immediate next milestone

### MVP-20.3 — Leaderboards и anti-farming

Build 8 separate seasonal leaderboard tables, one per game.

Canonical eligibility:
- minimum 5 rated matches;
- minimum 1 win.

Canonical anti-farming:
- maximum 3 rated wins against the same opponent per day;
- the 4th and later win still keeps the normal match result/economy result,
  but gives no visible rating credit.

Leaderboard work must define and test:
- deterministic ordering;
- tie-break rules;
- eligibility filtering;
- same-opponent/day counting;
- no duplicate rating credit;
- no bot/technical-result pollution;
- correct PRESEASON behavior without creating official seasonal awards.

## Order of work

1. Re-read exact MVP-20.3 master scope and current MVP-20.1 rating owner.
2. Audit rating history/outcome rows and canonical player/opponent identities.
3. Add the anti-farming gate at the authoritative visible-rating award path.
4. Add durable leaderboard query/storage semantics for all 8 games.
5. Define deterministic tie-break ordering and eligibility.
6. Expose leaderboard data through the canonical API/UI surface required by MVP-20.3.
7. Add focused tests for 5-match/1-win eligibility, three-win daily cap, fourth-win no-rating case and duplicate delivery.
8. Run visible-rating, matchmaking and all-games regressions.
9. Merge to staging only after targeted CI is green.
10. Verify exact staging deployment/E2E before moving to MVP-20.4.

## After MVP-20.3

- MVP-20.4 — automatic quarterly season calendar / FINALIZING.
- MVP-20.5 — seasonal awards.
- MVP-20.6 — yearly four-part medal.
- MVP-20.7 — rating profile, archives and Hall of Fame.
- MVP-20.8 — rating admin, metrics and regression.

## Frozen / do not touch

- Accepted mechanics of all 8 games.
- Store/Profile/LIVE cosmetics and Bundles.
- MVP-20.1 visible-rating point rules unless MVP-20.3 anti-farming explicitly gates a credit.
- MVP-20.2 hidden-skill values and matchmaking are not user-visible.
- Seasonal collections remain deferred to post-launch work.
- `main`, production, Cron and live DB manual edits require separate explicit approval.
