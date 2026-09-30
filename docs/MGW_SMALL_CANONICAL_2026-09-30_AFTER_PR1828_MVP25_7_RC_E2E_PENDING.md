# MGW SMALL CANONICAL — 2026-09-30 — AFTER PR #1828 / MVP-25.7 RC ON STAGING / E2E PENDING

## Current authoritative point

Repository: `manufact-test/mini-games-world`

Canonical staging branch: `agent/mvp-13-2-staging`

Current staging SHA at checkpoint:
`60f855e4f10ed53afc03f433e056cd6e157ee88b`

Current staging tree:
`7c9247e3b6ca02f8fa0ffad7e2d314603ca34969`

Do not assume this SHA is still current in a later chat. First re-read staging head.

---

## MVP-25.5 — CLOSED / FROZEN

Final closure PR:
- PR #1825 — `MVP-25.5: close security and resilience audit`
- merge SHA: `a90168cb84bf89631b97526684c13c2cdc8c0536`

Post-merge exact-SHA staging proof:
- Staging Playwright E2E: SUCCESS
- TEST PLAYER A/B Linux route: SUCCESS
- final commit status: SUCCESS

Checkpoint:
- `backup/mvp25-5-security-resilience-closed-green-2026-09-30`

MVP-25.5 must not be reopened without a new reproducible security/resilience defect.

---

## MVP-25.6 — CLOSED / AUTOMATED + STAGING ACCEPTED / FROZEN

Implementation:
- PR #1826 — `MVP-25.6: complete Store with monetization disabled`
- implementation merge SHA: `822c45d7db2ccb327925fa66fd0376dd05ecdcc8`

Accepted implementation proof:
- focused MVP-25.6 workflow: SUCCESS
- disabled-billing Store runtime: SUCCESS
- completed Store client contract: SUCCESS
- accepted MVP-19 Store mechanics: SUCCESS
- final MVP-24 Gold retirement contract: SUCCESS
- v110 manifest normalization: SUCCESS
- frozen-owner guard: SUCCESS
- secret scan: SUCCESS
- post-merge Staging Playwright E2E run `36682313363`: SUCCESS
- TEST PLAYER A/B: SUCCESS

Checkpoint:
- `backup/mvp25-6-monetization-disabled-store-green-2026-09-30`

Formal closure:
- PR #1827 — `MVP-25.6: close monetization-disabled complete Store`
- merge SHA: `a96b4adec2c536442eb4a45d7e14549f1f8c5bec`
- authoritative doc: `docs/MVP25_6_MONETIZATION_DISABLED_COMPLETE_PRODUCT.md`
- status in doc: `CLOSED / AUTOMATED + STAGING ACCEPTED / FROZEN`

Accepted Store state for MVP-25:
- internal MGW coin balance remains active;
- Profile / Games / Bundles cosmetics remain active;
- real-money coin top-up is hidden;
- provider coin packages are not exposed to players;
- no player-facing `Скоро` monetization card;
- no legacy Gold/order UI restoration;
- real-money/provider integration is intentionally outside MVP-25.

Do not reintroduce payment/provider logic while closing MVP-25.

---

## MVP-25.7 — FINAL TELEGRAM PRODUCT RELEASE CANDIDATE

Proof-only RC PR:
- PR #1828 — `MVP-25.7: Telegram Product Release Candidate`
- head SHA: `27174ee0288e3c6f9ea683d5c0935517b9d0b8eb`
- merge SHA / current staging at checkpoint:
  `60f855e4f10ed53afc03f433e056cd6e157ee88b`

PR #1828 changed only proof/documentation owners:
- `.github/workflows/mvp25-7-telegram-product-rc.yml`
- `bot/tests/Mvp25_7TelegramProductRcContractTest.php`
- `docs/MVP25_7_TELEGRAM_PRODUCT_RELEASE_CANDIDATE.md`
- `docs/MVP25_7_MANUAL_ACCEPTANCE.md`

No runtime/UI/economy/payment/game/tournament/database/Cron feature change belongs to this RC PR.

Pre-merge RC proof:
- workflow `MVP-25.7 Telegram Product Release Candidate`
- run `36685443574`
- result: SUCCESS
- repository secret scan run `36685443727`
- result: SUCCESS

The RC aggregate re-proves the accepted MVP-25.1–25.6 product, eight games, Store/Profile/economy, Friends/invites/rematch/notifications, Arena/tournaments, Support/moderation/anti-fraud/Admin/account lifecycle, reconnect, Gold retirement, secret scan and critical MySQL persistence.

---

## Exact current unfinished item

Post-merge canonical staging E2E for exact RC SHA
`60f855e4f10ed53afc03f433e056cd6e157ee88b`:

- workflow: `Staging Playwright E2E`
- run: `36685706578`
- checkpoint status: IN PROGRESS
- Linux TEST PLAYER A/B job is on step 10:
  `Run two-context staging test on Linux route`

Already SUCCESS in this run:
- checkout exact staging commit;
- Hostinger deployment readiness;
- staging Telegram webhook reconciliation;
- managed migrations/projection diagnostics;
- Node setup;
- A/B preflight;
- pinned Playwright install.

Manual MVP-25.7 acceptance has NOT started yet.

Manual checklist owner:
`docs/MVP25_7_MANUAL_ACCEPTANCE.md`

Its status at checkpoint:
`NOT STARTED / WAIT FOR AUTOMATED RC + EXACT STAGING E2E`

---

## Manual acceptance expectations after green E2E

Final human sweep is intentionally broad but regression-only:
- cold launch and shell navigation;
- More / Settings / Rules / History / Support;
- Profile and account/moderation sheets;
- Store with balance + Profile / Games / Bundles only;
- no real-money top-up and no `Скоро` monetization card;
- Arena / rating / tournament surfaces;
- Friends / invites / notifications;
- visual smoke of all eight games;
- one real two-player path;
- one network-recovery spot-check.

Accepted known residual:
- slight Profile visual hitch already accepted under MVP-25.4 may remain;
- no materially worse delay, freeze, crash or stuck state is acceptable.

---

## Historical red workflows

Many old push workflows may show FAILURE on proof-only RC/closure commits because they own stale historical bounded-scope/version guards.

Do not treat those red jobs as new product regressions unless:
1. the current focused RC/closure contract fails, or
2. the canonical exact-SHA Staging Playwright E2E fails, or
3. a reproducible player-visible/runtime defect is found.

For the current RC, authoritative signals are:
- MVP-25.7 focused RC gate;
- tracked repository secret scan;
- exact merged-SHA Staging Playwright E2E;
- final human acceptance.

---

## Product boundary

MVP-25 is still OPEN only because MVP-25.7 final staging proof + human acceptance are not yet complete.

Real-money/provider integration is not part of MVP-25.

The MVP-25.7 document states Android work starts only after MVP-25 is closed.
