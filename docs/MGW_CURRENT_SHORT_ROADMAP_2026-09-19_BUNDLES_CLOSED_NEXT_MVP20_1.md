# MGW CURRENT SHORT ROADMAP — 2026-09-19 — BUNDLES CLOSED → NEXT MVP-20.1

## Safe stop

- Branch: `agent/mvp-13-2-staging`
- Accepted runtime SHA: `1ad6b70e29b098a4d632db710178a667743139f0`
- Accepted runtime tree: `8830d1516295537cbe8d712e6d3cb6219dd2c056`
- PR #1570 merged.
- Final staging Playwright E2E: SUCCESS.
- User manually accepted the complete 8-game Bundles system.
- MVP-19.13 «Наборы»: CLOSED / FROZEN.

## Product decision before next work

Seasonal collections are deferred from first launch to **section 23 — post-launch recurring operational/content work**.

The old launch requirement «минимум одна full seasonal collection» is superseded.
Seasonal ownership semantics stay unchanged: limited-time sale may end, purchased ownership remains forever.

## Immediate next step

1. User updates and returns the full canonical master with this delta merged.
2. Re-read the refreshed master and verify exact staging/runtime before any code mutation.
3. Start **MVP-20.1 — Per-game visible rating** only after that verification.

## MVP-20 competition activation rule

MVP-20 is built now, but official public competition is **not** activated automatically at launch.

Canonical states:
- `OFF`
- `PRESEASON`
- `ACTIVE`

Launch/default target: `PRESEASON`.

During PRESEASON the rating/MMR/season foundation may run and collect evidence, but there are no official seasonal places, no seasonal badge/frame grants, no yearly medal parts and no Hall-of-Fame season result.

When the authoritative production user-count metric first reaches **500 users**:
- create a durable one-time admin alert;
- do **not** auto-activate competition;
- Admin must later present a clear action **«Запустить официальные рейтинговые сезоны»** with a full explanation of consequences;
- confirmed activation changes `PRESEASON → ACTIVE`, starts the first official season boundary and sends one global user notification;
- no retroactive preseason awards;
- activation and announcement must be idempotent and audit-recorded.

Final admin UI/alert/action belongs to **MVP-22.5 System status / feature flags / admin messages**. MVP-20 must provide compatible state semantics from the start.


## Season-end preparation and reminders

Clarification: official rating seasons are **quarterly**: Jan–Mar, Apr–Jun, Jul–Sep, Oct–Dec.

After competition reaches `ACTIVE`:
- T-21 days before each season end: durable Admin reminder to prepare the next-season reward package;
- if incomplete: repeat/escalate at T-14 and T-7;
- Admin checklist covers next-season dates, MVP-20.5 award assets, top-3 frames if applicable, yearly-medal quarter/fragments when due, localization/copy, preview/validation and explicit `READY`;
- yearly medal design is annual; each season unlocks its quarter/fragment rather than requiring a brand-new four-part medal every quarter;
- if assets are READY, season close, eligible rewards and next-season start run automatically and idempotently;
- if assets are missing at the boundary, keep results safe in `FINALIZING / ASSETS_REQUIRED`, issue a high-priority admin alert and resume after readiness without duplicate grants.

Implementation ownership:
- MVP-20.4 = calendar/finalizing;
- MVP-20.5/20.6 = reward and medal semantics;
- MVP-20.8 = close/recovery rehearsal;
- MVP-22.5 = Admin alerts/messages;
- MVP-22.7 = recurring season-preparation task/checklist.

## MVP-20.1 target

- Separate visible seasonal rating points for each of the 8 games.
- Normal human win: `+1`.
- Tournament played win: `+2`.
- Technical result / bot game / draw / loss: `0`.
- One match result must never grant rating twice.
- Visible rating is separate from hidden skill/MMR.
- Establish the authoritative result source and anti-duplicate rule before implementing UI.

## Order of work

1. Audit current match-result owners and season-related schema/API.
2. Define one authoritative per-game rating write path and idempotency key.
3. Implement storage/migration and result-grant semantics.
4. Expose current per-game visible rating through the canonical API/profile surfaces required by MVP-20.1.
5. Add focused tests for all result types and duplicate delivery.
6. Deploy exact staging SHA and perform manual acceptance.
7. Verify MVP-20.1 respects OFF/PRESEASON/ACTIVE semantics and cannot create official season awards by itself.
8. Only after acceptance move to MVP-20.2 Hidden skill model.

## Frozen / do not touch

- Accepted 8 games and mechanics.
- Store/Profile/LIVE cosmetic visuals.
- Bundles pricing, composition and purchase semantics.
- `main`, production and Cron without separate explicit approval.
- Seasonal collections are not to be started before post-launch stabilization.
