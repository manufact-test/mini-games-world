# MVP-25.2 — Human-facing copy closure

Date: 2026-09-29

Accepted staging before closure proof:
- SHA: `40b7808f98aeb26dc43e49533d8f1e30c25d35a7`
- tree: `9b9684c5e0f4c76cf75383124632a138e4cdaaae`

## Scope

MVP-25.2 required an audit of ordinary-player wording across the Russian Telegram Mini App and its player API/error paths.

Canonical principle:

> Player-facing copy explains what happened and what the player can do next, not how Mini Games World is internally implemented.

## Corrective chain

### PR #1798 — mandatory copy audit
Recorded the player-facing copy inventory and explicit must-fix / intentional-keep split.

### PR #1799 — public error boundary
Closed raw implementation-error leakage:
- one human v110 fatal-entry message;
- no `Ошибка API: <status>` client fallback;
- shared public technical-error sanitizer;
- direct public endpoint exception responses routed through that boundary;
- useful domain/player messages preserved.

Focused current contract: GREEN.

### PR #1800 — retired Store orders dependency
Exact staging E2E after #1799 exposed one real active dependency on deleted legacy `store-orders.js?v=36`.

Root cause was removed:
- Notification Center no longer imports/invokes the retired legacy Store orders UI;
- historical `store:orders` notification links converge to the current Store;
- legacy Gold/order UI was not restored.

Post-merge exact staging E2E `36581213022`:
- Linux route could not pass Hostinger readiness because of HTTP 403 and was correctly classified as network unavailable;
- macOS fallback verified the exact staging deployment and completed the full two-context Playwright suite successfully;
- final staging E2E result: SUCCESS.

### PR #1801 — tournament and Profile human copy
Removed player-visible implementation language including:
- `Admin review`;
- `канонический settlement`;
- `идемпотентно`;
- client synchronization wording;
- developer-style tournament restart/failure labels;
- Profile `локализация` wording.

Also:
- unknown tournament reward enums now use `Награда турнира`;
- real player-relevant `техническое поражение` wording is intentionally preserved;
- tournament state machine, settlement, economy and game rules were unchanged.

Post-merge exact staging E2E `36582812490`:
- exact Hostinger deployment: verified;
- migrations/projection diagnostics: GREEN;
- staging A/B preflight: GREEN;
- full two-context Playwright suite: GREEN on Linux;
- final staging E2E result: SUCCESS.

## Intentional keeps

MVP-25.2 does not remove valid product language merely because a word can also be technical.

Intentional examples:
- `техническое поражение` when it is the actual match/tournament outcome;
- `Идут технические работы` as a user-facing availability state;
- useful domain errors such as insufficient coins, unavailable invitation, nickname validation or moderation restrictions;
- Admin-only technical reports, staging diagnostics, logs, source comments and cache/build identities.

## Explicitly deferred

Provider-disabled Store coin-package copy/state identified by MVP-25.1 remains owned by **MVP-25.6 monetization-disabled complete-product mode**. It is not silently reclassified as a 25.2 defect.

## Final technical readiness correction

The PR #1802 proof established automated technical closure, but the roadmap requires a separate human review for MVP-25.2. Therefore green CI alone does **not** formally close this slice.

A final pre-manual audit found two additional ordinary-player wording boundaries:

- browser/network transport failures could still surface native `Failed to fetch` text through direct client/invite fetch paths;
- Account Data deletion confirmation still used developer-style `техническая история / финансовый аудит` wording.

Both are part of the same MVP-25.2 human-facing-copy contract and must be corrected before manual acceptance.

## Final manual acceptance

Final corrective PR #1803 was merged to staging as `2661c2b0c166c0dc767108de6df2ee0538a31a55`.

Exact post-merge staging verification:
- Staging Playwright E2E run `36595264108`: SUCCESS on Linux;
- exact Hostinger deployment readiness: GREEN;
- managed staging migrations / projection diagnostics: GREEN;
- staging A/B preflight: GREEN;
- full two-context Playwright suite: GREEN;
- current MVP-25.2 focused gates: GREEN.

On 2026-09-29 the product owner completed the requested manual review, reported the result as normal, and explicitly instructed that MVP-25.2 be closed.

## Current status

**CLOSED / MANUALLY ACCEPTED / FROZEN**

Closure statement:

**Ordinary Russian Telegram Mini App users receive product-language explanations and actions instead of MGW implementation terminology or raw technical/browser exceptions on the audited current product path.**

Next roadmap point: **MVP-25.3 — Final UX consistency.**
