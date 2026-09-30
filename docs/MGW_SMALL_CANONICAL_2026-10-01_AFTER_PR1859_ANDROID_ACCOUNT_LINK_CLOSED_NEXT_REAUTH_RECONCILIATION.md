# MiniGamesWorld — SMALL CANONICAL CHECKPOINT

**Date:** 2026-10-01  
**Repository:** `manufact-test/mini-games-world`  
**Authoritative integration branch:** `agent/mvp-13-2-staging`

## Exact accepted checkpoint

- staging SHA: `746b2f69d24b2fd5d31cde16f723afd551333c5a`
- staging tree: `eec85989d0e55a707c2ba47c890a0945a8de1a9c`
- backup: `backup/mvp26-account-link-manually-accepted-2026-10-01`
- Telegram MVP-25 product: **CLOSED / ACCEPTED / FROZEN**
- Android foundation/device auth: **CLOSED / MANUALLY ACCEPTED / FROZEN**
- Android ↔ Telegram existing-account link workstream: **CLOSED / MANUALLY ACCEPTED / FROZEN**
- production / `main` / production DB / production Cron: **UNTOUCHED**

## What is accepted now

A real Android device was manually used to prove the complete existing-account convergence path.

Accepted behavior:
- Android can start as an internal provider-neutral device identity;
- Android can link to an existing Telegram MGW account;
- MGW remains the canonical account owner;
- the existing Telegram profile wins rather than creating a second long-lived player account;
- wallet/balance is correct immediately at startup after linking;
- Store/profile/invite read paths cannot overwrite the authoritative wallet with stale values;
- temporary Android starter coins are not merged into the existing Telegram wallet;
- linked Android + Telegram presence converges to **one** online account;
- ordinary Android rotation preserves the accepted Activity/WebView/session;
- first-run Android account-link onboarding is visible, centered, readable and manually accepted;
- after linking, the one-time onboarding no longer nags the linked account.

## Important PR closure chain

- #1852 — Store status made a unified-wallet non-owner after real-device startup tracing.
- #1853 — temporary balance diagnostics removed after manual balance acceptance.
- #1854 — old temporary Android presence owner retired immediately on successful link.
- #1855 — final centered Home onboarding presentation.
- #1856 / #1857 — temporary staging-only preview used only for visual acceptance.
- #1858 — fixed the real active Profile cache owner.
- #1859 — removed temporary preview and froze the accepted flow.

## Permanent cache-owner corrective

The active shell imports:

`./assets/js/screens/profile-screen-v110.js?v=1109`

Historically that exact specifier had no import-map owner, so Android WebView could keep serving an old raw Profile module even after source changes.

The accepted runtime now permanently maps the active `v=1109` specifier to a fresh canonical Profile target. Focused CI guards this relationship.

Do not remove that mapping or return to implicit raw-module cache ownership.

## Final staging proof

Post-cleanup run:
- workflow run: `36783608754`
- exact staging SHA: `746b2f69d24b2fd5d31cde16f723afd551333c5a`
- attempt 2: **SUCCESS**
- exact Hostinger deploy: PASS
- webhook reconciliation: PASS
- managed migrations/projection preflight: PASS
- Linux two-context A/B Playwright: PASS
- final `staging-playwright-e2e` status: PASS

Attempt 1 had a synthetic technical A/B Profile 400 + following preloader timeout. The same exact SHA passed on rerun with no product-code change, so it is preserved as non-reproduced test-state noise rather than an account-link product regression.

## Roadmap numbering reconciliation

The operational workstream was called **MVP-26.3 Existing-account linking / recovery** in the short roadmap.

In the authoritative master roadmap, however:
- master **26.3** = Native shell / settings;
- master **26.4** = Provider-neutral account architecture.

Therefore the accepted account-link work completed the core of **master 26.4**, while some master-26.3 shell/settings items remain to be formally audited.

Do not rewrite historical PR names. Use this checkpoint to reconcile future work.

## Exact next product gap

**Android account recovery / sensitive-action reauthentication finalization.**

Current gap:
- linked Android sessions can use the product normally;
- sensitive Account Data actions still require fresh Telegram `initData`;
- Android therefore cannot safely confirm export/delete/cancel-delete/download actions natively yet.

Security rule:
- do **not** weaken sensitive actions to ordinary Android session-cookie auth;
- do **not** expose the Android Keystore credential to JavaScript;
- add one bounded native/server reauth owner and keep MGW as canonical account owner.

After reauth closure:
1. finish master-26.3 native shell/settings audit;
2. master-26.5 disabled/no-op platform adapters;
3. master-26.6 Android product parity;
4. master-26.7 device/lifecycle QA;
5. master-26.8 Android Product RC.
