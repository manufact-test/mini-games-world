# MGW SMALL CANONICAL — 2026-09-19 — AFTER PR #1576 — MVP-20.2 CLOSED → NEXT MVP-20.3

## Safe stop

- Canonical branch: `agent/mvp-13-2-staging`
- Accepted staging SHA: `28bef588a7161efddf5d88e3a6a41de85d9bcc22`
- Accepted staging tree: `f30ba90828ae88d8bd86f30bdc2df674cfe87b19`
- PR #1576 merged.
- Final rerun of Staging Playwright E2E: SUCCESS.
- `staging-playwright-e2e`: SUCCESS.
- No `main`, production or Cron changes.

## MVP-20 status

- MVP-20.1 — per-game visible rating: implemented on staging.
- MVP-20.2 — hidden skill model: implemented, merged, staging-green.
- Official competition remains PRESEASON by launch policy until explicit future activation.
- Visible rating and hidden skill remain separate systems.

## MVP-20.2 frozen semantics

- Hidden skill is server-only and never user-visible.
- Separate hidden skill per each of the 8 games.
- Only real human-vs-human results affect hidden skill.
- Bot and technical results do not change hidden skill.
- Match result processing is idempotent by match.
- Matchmaking receives server-assigned skill bands; client cannot choose them.
- Existing human-priority search remains:
  - 8 s human-priority gate;
  - widening every 2 s;
  - hard maximum distance 3 bands.
- Old `95% close / 5% random` scheme is superseded.
- Season transition supports soft hidden-skill adjustment.
- Realtime queue DB parity now persists `skill_band`.
- Match-quality telemetry records exact/widened band usage and wait/gap metrics.

## Known CI note

The first post-merge staging browser run saw a transient `/bot/health.php` 503 in Store smoke.
The exact same staging SHA was rerun and passed fully. Final commit status is SUCCESS.

## NEXT

**MVP-20.3 — Leaderboards и anti-farming.**

Do not change accepted game mechanics, Store/Profile cosmetics, Bundles semantics, visible-rating rules or hidden-skill rules without a reproducible defect.
