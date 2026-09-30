# MiniGamesWorld — CURRENT SHORT ROADMAP

**Date:** 2026-10-01  
**Integration authority:** `agent/mvp-13-2-staging`

## Accepted baseline

- SHA: `746b2f69d24b2fd5d31cde16f723afd551333c5a`
- tree: `eec85989d0e55a707c2ba47c890a0945a8de1a9c`
- backup: `backup/mvp26-account-link-manually-accepted-2026-10-01`

Closed/frozen:
- MVP-25 Telegram product;
- Android foundation;
- Android device auth;
- Android ↔ Telegram existing-account linking/convergence;
- post-link wallet ownership;
- post-link presence convergence;
- Android account-link onboarding;
- active Profile module cache ownership.

## Manual acceptance completed

Product owner manually confirmed on real Android:
- same Telegram profile after link;
- correct balance immediately on open;
- one online presence for linked Telegram + Android;
- rotation/session behavior;
- final onboarding presentation.

No repeat manual check is needed for these frozen items unless a new reproducible defect appears.

---

# NEXT — Android Account/Reauth Closure

This is the remaining recovery/reauth part of authoritative master **26.4 Provider-neutral account architecture**.

## Current defect / gap

Sensitive Account Data actions use `AccountReauthGuard`.

Today that guard accepts only fresh Telegram `initData`:
- create data export;
- download export;
- schedule account deletion;
- cancel account deletion.

A linked Android user therefore receives a Telegram-specific reauth instruction instead of being able to confirm the action inside Android.

## Required result

Android must have a fresh, explicit proof-of-user-presence flow for sensitive actions without weakening current Telegram security.

Constraints:
1. ordinary Android session cookie alone is **not** sufficient reauth;
2. Android device credential remains encrypted by Android Keystore;
3. raw device credential never enters JS/WebView;
4. no privileged general-purpose `addJavascriptInterface`;
5. server binds reauth to the existing Android session + same canonical MGW account;
6. reauth has a short expiry, comparable to current Telegram 5-minute freshness;
7. retry/idempotency remains safe;
8. production remains fail-closed while internal Android provider is staging-only;
9. no second account/session/wallet owner;
10. no production/main/production DB/Cron changes.

## Implementation sequence

1. Audit exact Android auth/session ownership.
2. Define one short-lived Android reauth proof bound to the existing session.
3. Add native user-presence confirmation.
4. Add a narrow native HTTPS reauth endpoint.
5. Extend `AccountReauthGuard` to accept recent Android reauth only for the same authenticated session.
6. Add a narrow JS↔native success/failure handoff without exposing secrets.
7. Retry the original sensitive action once after successful reauth.
8. Cover SQLite + MySQL migration, PHP regressions, Android unit/static checks, lint/build and secret scan.
9. Merge only after focused CI.
10. Exact Hostinger deploy + staging proof.
11. Then ask for one real-device Android manual acceptance.

## After reauth

Reconcile and close remaining master **26.3 Native shell/settings** items:
- Android Back;
- external links;
- keyboard;
- safe-area;
- orientation;
- accessibility basics;
- any genuinely native Profile/settings ownership.

Then continue:
- 26.5 no-op adapters;
- 26.6 product parity;
- 26.7 lifecycle/device matrix;
- 26.8 Android Product RC.
