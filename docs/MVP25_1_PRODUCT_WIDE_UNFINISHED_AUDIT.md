# MVP-25.1 — Product-wide unfinished-work audit

Date: 2026-09-29  
Base staging SHA: `00734202ef8b87d71db7a6eb6a46a1e4a662f74c`  
Base tree: `4e9b9f5c3478ee9a4885916c19302c3a61958d25`

## Purpose

MVP-25.1 is an inventory/classification pass before product-completion corrections.

Every identified unfinished/temporary/stale item is classified as one of:

- **LAUNCH BLOCKER**
- **POST-LAUNCH**
- **REMOVE**
- **INTENTIONAL KEEP**

No runtime behavior is changed by this audit document.

## Audit coverage

The audit covered:

- current Telegram launch owner;
- alternate public Mini App entrypoints;
- current v110 import/version owner;
- active player-facing Home / Store / Profile / game/result surfaces;
- rematch/invite ownership;
- top-level Bot API and Telegram command surfaces;
- staging diagnostics/test-only routes;
- legacy Admin archive compatibility;
- public Gold retirement state from MVP-24;
- repository-level versioned frontend residue.

Current canonical Telegram launch owner is:

`bot/helpers/WebAppLaunchUrl.php -> /app/v110.php`

The repository also exposes a separate default `/app/` directory entry through `app/.htaccess`.

---

# 1. LAUNCH BLOCKERS

## LB-01 — default /app/ still boots the v114 diagnostic entrypoint

Evidence:

- `app/.htaccess` currently declares:
  - `DirectoryIndex v114.php index.html`
- `app/v114.php` is not the canonical Telegram product owner.
- `app/v114.php` injects:
  - `reconnect-diagnostic-r5.js`
  - diagnostic build headers;
  - a visible staging reconnect diagnostic panel.
- current Telegram launch owner explicitly uses `/app/v110.php`.

Why this blocks product completion:

A directly opened `/app/` can execute a different product graph from the canonical Telegram launch graph and, on staging, can expose visible diagnostic UI.

Required correction before MVP-25 RC:

- make `/app/` converge to the canonical v110 entry;
- do not expose the reconnect diagnostic panel from an ordinary product entry;
- preserve a separate explicit diagnostic route only if still operationally useful.

---

## LB-02 — historical versioned app entrypoints still execute old runtime graphs

Observed directly executable historical wrappers:

- `app/v97.php`
- `app/v98.php`
- `app/v99.php`
- `app/v100.php`
- `app/v101.php`
- `app/v102.php`
- `app/v103.php`
- `app/v104.php`
- `app/v105.php`
- `app/v106.php`
- `app/v107.php`
- `app/v108.php`
- `app/v109.php`
- `app/v114.php`

These wrappers still build their historical frontend graphs instead of converging to the accepted current application.

`app/v120.php` is already the correct model: it is a compatibility tombstone that redirects stale launches to v110 while preserving invite context.

Why this blocks product completion:

Old saved Telegram links/bookmarks can still launch obsolete frontend/runtime owners. This violates the one-current-product-owner goal even when the bot now generates v110 links.

Required correction:

- preserve compatibility URLs;
- convert stale executable historical entrypoints to bounded redirects/tombstones to v110;
- preserve valid invite token propagation;
- do not delete URLs that old Telegram messages may still contain.

---

## LB-03 — v110 still contains acceptance/test/cache workaround ownership

`app/v110.php` contains multiple acceptance-era hooks that are still part of the canonical launch transformation.

Examples:

- account-data first-open manual-acceptance cache hook;
- final mobile Profile acceptance cache hook;
- mobile cold-first corrective cache hook;
- explicit **temporary staging-only Chess acceptance hook** with the instruction:
  `Remove immediately after acceptance`;
- Go manual-review cache hook;
- Domino staging cache hook;
- Battleship manual-review cache hook;
- direct corrective CSS injection outside the clean manifest owner;
- `X-MGW-Chess-Check-Test: staging-any-move-v1` response header.

The current accepted renderer behavior may already be correct; the blocker is that the launch owner still carries stale acceptance/test/workaround state.

Required correction:

- fold accepted owners/versions into the canonical version manifest;
- remove stale manual-review/test/cache labels from the canonical launch path;
- remove the Chess test header;
- keep only product-runtime behavior required by the accepted game implementations.

---

## LB-04 — Store exposes provider-disabled coin packages as “Скоро”

Evidence:

`app/assets/js/screens/store-screen.js` renders coin package cards with:

`<em>Скоро</em>`

This is visible product copy, not an internal marker.

Why this blocks MVP-25 completion:

MVP-25.6 requires a complete product while real-money providers are disabled. No screen may look unfinished merely because monetization is not connected yet.

Required correction before MVP-25.6 closure:

- hide/disable the provider-specific purchase surface cleanly until a provider is enabled, or
- present a finished provider-independent Store state that does not promise “coming soon”.

Do not implement real-money purchasing in MVP-25.

---

# 2. REMOVE

## RM-01 — dead Home “inviteFriend” placeholder action

Evidence:

`app/assets/js/screens/home-screen.js` still contains:

`if (target.id === 'inviteFriend') return toast('Приглашения друзей появятся позже.');`

No active `inviteFriend` element exists in the current source surface, while the actual Friends/invite system is already implemented elsewhere.

Classification:

**REMOVE**

Reason:

This is dead unfinished-product residue and stale copy.

---

## RM-02 — obsolete API request_rematch placeholder

Evidence:

`bot/api.php` still contains:

`case 'request_rematch': ... 'Реванш будет подключён следующим этапом.'`

Current rematch ownership is the invite/rematch flow in `game-invites-v110.js`, not this API placeholder.

Classification:

**REMOVE**

Reason:

Dead action + obsolete user-facing unfinished copy. Preserve current invite-based rematch behavior.

---

## RM-03 — stale manual-review identity labels in active assets

Example:

`app/assets/js/screens/store-screen-reversi-store-v1.js` still loads its stylesheet with
`mvp19_7=manual-review-corrective-v2`.

Classification:

**REMOVE** from the final canonical product URL identity when the owning asset version is normalized.

Reason:

No product behavior depends on a “manual-review” label. It is acceptance residue.

---

## RM-04 — fake future Achievements placeholder in Profile

Late audit finding after the first route/manifest correctives:

`app/assets/js/screens/profile-screen-v110.js` still renders three fake locked achievement cards using:

- `profile.achievements_note = "Места под будущие достижения и награды."`
- `profile.achievement_locked = "Закрыто"`
- `profile.achievement_soon = "Скоро"`

This is not the real tournament achievement system. Real permanent tournament achievements are already rendered by the tournament prestige showcase from authoritative reward data.

Classification:

**REMOVE**

Reason:

A finished product must not show dummy “future feature” cards when a real achievement/reward owner already exists elsewhere in the Profile.

---

# 3. POST-LAUNCH

## PL-01 — large inactive historical frontend asset population

Repository inventory at the audit base:

- total `app/assets/js/*.js`: **290**
- not directly owned by the current v110 manifest/entry transformation: **222**
- total `app/assets/css/*.css`: **175**
- not directly owned by the current v110 manifest/entry transformation: **161**

These include many superseded versioned/corrective files.

Classification:

**POST-LAUNCH**, as a grouped historical cleanup class.

Reason:

Wholesale deletion during product completion is unnecessarily risky. First converge all public entrypoints and prove the current import graph. Historical assets can then be archived/removed in a dedicated post-launch repository hygiene pass.

Exception:

Any inactive file proven to be accidentally exposed by a current public route becomes a launch blocker and must be handled earlier.

---

# 4. INTENTIONAL KEEP

## IK-01 — staging-only diagnostic/test infrastructure

Examples:

- `bot/staging-readiness.php`
- `bot/staging-projection-diagnostic.php`
- `bot/staging-test-auth.php`
- `bot/admin-test-coins.php`
- `bot/webhook-diagnostic.php`

These surfaces are guarded by staging environment checks and, where mutating/sensitive, GitHub OIDC/admin/test-identity controls.

Classification:

**INTENTIONAL KEEP**

Rule:

They must not become ordinary player UI and must remain inaccessible as production functionality.

---

## IK-02 — legacy commerce/archive Admin reads

Legacy Match/Gold/payment/order references still exist in Admin/archive code for historical read-only inspection.

Examples include:

- legacy payment/order archive labels;
- legacy Match/Gold snapshots;
- historical transaction/fee breakdowns;
- compatibility commands that terminate at the archive-only boundary.

Classification:

**INTENTIONAL KEEP**

Reason:

MVP-24 explicitly preserves required historical/read-only compatibility. Do not reinterpret these archive surfaces as active commerce.

---

## IK-03 — tournament active_temporary reward state

`profile-screen-v110.js` uses `active_temporary` for temporary tournament prestige rewards such as champion crown/frame/mark.

Classification:

**INTENTIONAL KEEP**

Reason:

This is domain semantics, not a temporary implementation placeholder.

---

## IK-04 — resilient loading/error placeholders

Examples:

- result economy “считаем… / итог пока недоступен”;
- Store empty states when a catalog family is genuinely unavailable;
- invite-link temporary network failure messages;
- tournament rules temporary-unavailable fallback.

Classification:

**INTENTIONAL KEEP** at MVP-25.1.

These strings still belong to the mandatory MVP-25.2 copy audit, but their existence is not itself unfinished functionality.

---

## IK-05 — v120 compatibility tombstone

`app/v120.php` explicitly redirects a failed historical v120 launch to v110 and preserves a valid invite token.

Classification:

**INTENTIONAL KEEP**

This is the preferred compatibility pattern for retired public app entrypoints.

---

# 5. FINDINGS THAT MOVE TO LATER MVP-25 SUBPOINTS

These are classified here but intentionally resolved under their owning completion slice:

- player-facing “Скоро” monetization surface -> **25.6**
- human wording of loading/error/internal states -> **25.2**
- final consistency of navigation/disabled/empty states -> **25.3**
- performance/reconnect lifecycle -> **25.4**
- auth/rate-limit/secret/resilience audit -> **25.5**

MVP-25.1 must not silently absorb those later specialist audits.

---

# 6. MVP-25.1 CORRECTIVE ORDER

After this inventory is recorded, corrections should be bounded in this order:

1. canonicalize all public Mini App entrypoints to v110 compatibility semantics;
2. remove visible/active diagnostic/default-entry leakage;
3. remove stale v110 acceptance/test/cache hooks by folding accepted owners into the canonical manifest;
4. remove dead `inviteFriend` placeholder action;
5. remove obsolete `request_rematch` API placeholder;
6. normalize stale manual-review asset identities;
7. preserve staging diagnostics, historical Admin archive data and tournament temporary-reward semantics;
8. run exact staging E2E and current product-wide regression;
9. only then advance to MVP-25.2.

No production/main/Cron/live-DB mutation belongs to this corrective chain.
