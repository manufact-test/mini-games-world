# MVP-26.5 — Disabled / no-op Android platform adapters

Status: candidate implementation for authoritative master MVP-26.5.

## Goal

Create provider-neutral Android seams for future commercial/platform integrations
without making any external Google/provider service a dependency of the complete
MGW product.

Prepared interfaces:

- `BillingAdapter`
- `AdsAdapter`
- `PushAdapter`
- `IntegrityAdapter`
- `AnalyticsAdapter`
- `DeepLinkAdapter`

## Accepted default before MVP-29

`PlatformAdapters.disabled()` is the Android runtime default.

Every adapter reports `Mode.DISABLED`.

Disabled behavior:

- billing purchase launch -> `UNAVAILABLE`;
- rewarded ad presentation -> `UNAVAILABLE`;
- push registration -> `UNAVAILABLE`;
- integrity token -> empty;
- analytics -> no-op;
- provider-specific deep-link resolution -> empty.

No provider SDK is linked.

## Ownership boundaries

These adapters are transport/provider seams only.

They MUST NOT own:

- MGW balance;
- ledger;
- inventory;
- Store entitlement;
- tournament rewards;
- ratings;
- matchmaking;
- game state;
- canonical account identity.

In particular, Billing and Ads intentionally expose no method that can directly
credit MGW balance or inventory.

Canonical economy/account/product state remains server-owned.

## Deep-link boundary

The disabled `DeepLinkAdapter` is reserved for future provider-specific
attribution/deep-link resolution.

Existing safe same-origin Android navigation remains owned by
`NavigationPolicy` / `MainActivity` and must continue working while the
provider adapter is disabled.

## Push boundary

Android platform push may be disabled. Existing in-app MGW notifications remain
a shared product feature and do not depend on `PushAdapter`.

## Integrity boundary

Before the later provider-integration roadmap, unavailable integrity proof must
not block ordinary MGW product use.

## Analytics boundary

Analytics is observational only. Product behavior may not branch on whether
analytics is available.

## Current candidate

- versionCode: `2611`
- versionName: `0.26.5.1-disabled-platform-adapters`
- package/signing: unchanged from accepted Android baseline.

No visible product change is intended in this slice.

## Acceptance

Automated acceptance requires:

1. all six interfaces exist;
2. default registry is disabled;
3. disabled unit tests pass;
4. no Google Billing / Firebase / Ads / Play Integrity provider dependency is linked;
5. package/signing continuity is preserved;
6. no gameplay/economy/account backend path is changed;
7. Android build/lint/unit passes;
8. staging regression remains green.

Manual phone verification is not required unless the slice unexpectedly changes
visible behavior.
