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
7. Only after acceptance move to MVP-20.2 Hidden skill model.

## Frozen / do not touch

- Accepted 8 games and mechanics.
- Store/Profile/LIVE cosmetic visuals.
- Bundles pricing, composition and purchase semantics.
- `main`, production and Cron without separate explicit approval.
- Seasonal collections are not to be started before post-launch stabilization.
