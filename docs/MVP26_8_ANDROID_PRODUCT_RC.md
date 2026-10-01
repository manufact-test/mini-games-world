# MVP-26.8 — ANDROID PRODUCT RC

Status target: **CLOSED / PRODUCT RC ACCEPTED** after the dedicated PR gate passes and the PR is merged to `agent/mvp-13-2-staging`.

## Canonical closure statement

**Russian Android MGW is a complete app even if every external Google commercial/provider integration is still disabled.**

## Accepted input state

- staging base at RC start: `e20eb78977f8a78ed682a5c90d11961b72e43cbb`;
- MVP-26.1 through MVP-26.7 are already accepted prerequisites;
- accepted Android package: `com.minigamesworld.app.acceptance`;
- accepted native candidate remains versionCode `2615`, versionName `0.26.7.0-device-lifecycle-qa`;
- the Android binary is intentionally **not** changed only to rename/re-number the closure milestone;
- any future Android binary change must advance monotonically beyond versionCode 2615.

## Final corrective acceptance before RC

Merged before this RC:
- PR #1878 — defer standalone Android vibration off the critical click-dispatch path;
- PR #1879 — lazy Store bundle preview hydration + bounded preview fitting + active v110 cache-bust.

Manual real-device acceptance: PASS.

Acceptance note:
- no functional regression was found after the two corrective PRs;
- the Store bundle path is accepted;
- a residual responsiveness difference versus Telegram remains noticeable to the tester;
- that residual responsiveness difference is accepted as **non-blocking** for MVP-26.8 because the corrective did not materially change the subjective difference and no further concrete owner/regression was proven.

## Provider-independent product contract

MVP-26.8 closes Android with these provider boundaries unchanged:
- BillingAdapter — disabled;
- AdsAdapter — disabled;
- PushAdapter — disabled;
- IntegrityAdapter — disabled;
- AnalyticsAdapter — disabled/no-op;
- DeepLinkAdapter provider seam — disabled.

The canonical MGW account, gameplay, economy, Store, Profile, Arena/tournaments, friends/invites, notifications and Support flows must continue to work without those provider SDKs.

## RC proof

The dedicated MVP-26.8 gate must prove:
1. all predecessor Android verifiers remain PASS;
2. all 8 canonical game owners remain present;
3. current v110 runtime remains the launch owner;
4. PR #1879 Store performance cache identity remains active;
5. Android unit tests, lint and APK assembly pass;
6. accepted package/signing certificate remain unchanged;
7. provider SDKs remain absent;
8. API 26 / 30 / 36 lifecycle smoke remains PASS;
9. tracked secret scan remains PASS;
10. no gameplay/economy/rating/tournament engine mutation is introduced by the RC closure.

## Manual gate

No additional feature-by-feature phone pass is required for MVP-26.8: the user explicitly accepted the remaining Android performance difference and asked to close the slice and continue.

The APK emitted by the final RC workflow is the canonical internal Android Product RC artifact for this milestone.
