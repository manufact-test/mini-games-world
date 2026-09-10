# MGW SMALL CANONICAL DELTA — 2026-09-10

## Closed
- MVP-19.3 Profile cosmetics is formally CLOSED after final reconciliation.
- MVP-19.4 Common game-cosmetics framework + Tic Tac Toe pilot is formally CLOSED after factual reconciliation.

## Durable MVP-19.3 state
- Active client route remains Telegram `v110` through the canonical version manifest/import graph.
- Profile cosmetics present in the active graph: avatars, name colors, badges, frames, backgrounds, reactions, profile transition, Entry Effects and Victory Effects.
- Accepted Entry Effects are frozen unless a reproducible defect is found.
- Accepted Victory Effects are frozen unless a reproducible defect is found.
- Victory tiers: Firework Salvo / `profile-victory-effect-02` / 5,000 / ~2.9 s; Spark Burst / `profile-victory-effect-01` / 8,500 / ~2.2 s; Victory Nova / `profile-victory-effect-03` / 12,500 / ~3.5 s.
- Purchase never auto-equips; explicit equip/unequip remains required.
- Store = discovery/purchase; Profile = collection/equip; `ProductInventoryService` = permanent ownership/equipment owner.

## Durable MVP-19.4 state
- Existing merged foundation is authoritative; do not rebuild it.
- Merged implementation chain: PR #1083 framework/pilot, PR #1084 visual catalogue corrective, PR #1085 polish/staging test coins.
- Pilot catalogue: 4 Tic Tac Toe fields, 4 mark sets, 3 cosmetic effects, premium bundle 34,000.
- Equipped game cosmetics are projected per player and rendered as owner-specific presentation.
- Cosmetic renderer must not become a game-rules/action/timer/winner owner.
- MVP-19.4 pilot closure does not imply MVP-19.10 Tic Tac Toe final cosmetics closure.

## Staging gate note
- Latest exact staging base audited: `2afaf7a0cdb7d453040175725d342496a1396dda`, tree `dd1b8558d3e063867208f301a7bb6125adc485e8`.
- Exact deployment readiness/fingerprint passed, but Staging Playwright records `/bot/health.php` HTTP 503 as application failure.
- Do not mask the 503; treat it as a separate staging health-gate defect unless root-cause evidence ties it to the current feature slice.

## Next
- NEXT = MVP-19.5 Chess cosmetics.
- Boards: wood / dark tournament / marble / neon.
- Pieces: wood / marble / metal / neon.
- Effects: move / capture / check.
- Use common pricing and 34,000 premium bundle; support all sizes/states, low-end and reduced motion.
- Do not modify accepted game rules/actions/timers/winner semantics, `main`, production, Cron or live DB.
