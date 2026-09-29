# MVP-25.4 — Performance / reliability

Date: 2026-09-29

Starting frozen staging checkpoint:
- SHA: `3959308f16c374951292d9442ec27bd98ff5c118`
- MVP-25.3: **CLOSED / MANUALLY ACCEPTED / FROZEN**

Final accepted implementation before formal closure:
- SHA: `5c7db576fcfc173d90b6e40f3fdb25e21598380a`
- exact canonical Staging Playwright E2E: `36627303146` — **SUCCESS**
- canonical Playwright result: **7/7 passed**

## Goal

Measure and improve startup/reliability behavior of the accepted Telegram Mini App without changing accepted product behavior, game mechanics, economy, tournaments or cosmetics.

## Baseline and classification

The first reliable canonical baseline showed:
- navigation → first usable: about **9,179 ms**;
- bootstrap response: about **3,766 ms**;
- app-ready → first usable: about **5,253 ms**;
- blocking `POST /bot/profile-v2.php` read: about **5,146 ms**;
- first Profile open: about **442 ms**;
- first Store open: about **137 ms**;
- first Arena open: about **142 ms**.

The main cold-start defect was not general rendering cost. The v110 handoff shell awaited a Profile warm read in `primeMobileProfileFirstPresentation()` even though Profile already had an asynchronous warm owner.

## Corrective chain

### PR #1811 — isolated baseline diagnostics
Created an isolated performance baseline route. Its generic GitHub OIDC path was not authorized by staging test-auth, so the run was diagnostic only and did not change runtime behavior.

### PR #1812 — canonical baseline
Moved the baseline through the canonical Staging Playwright path and established trustworthy exact-staging measurements.

### PR #1813 — startup unblock
Removed the awaited `api.profileV2()` from the preloader-critical Profile preparation path while preserving hidden Profile preparation/raster warm-up.

Result:
- navigation → first usable improved from about **9.18 s** to about **4.60 s**;
- accepted first-open Store/Arena behavior remained intact;
- Profile remained functionally correct.

The remaining ~1.4–1.5 s app-ready → usable interval is intentional accepted mobile cold-surface protection inherited from MVP-23. It was not shortened merely to improve a score.

### PR #1814 — reliability proof
Added focused staging proof for transient failure/recovery and verified existing lifecycle ownership rather than adding new polling.

Measured proof:
- temporary offline failure surfaced in about **19 ms**;
- recovery after restored connectivity completed in about **930 ms**;
- app remained interactive after recovery.

Existing reliability ownership retained:
- visible/background/page lifecycle handling;
- Telegram activated/deactivated handling;
- bounded retry while visible;
- authoritative presence readiness event;
- reconnect re-read of authoritative game state;
- duplicate resume collapse.

### PR #1815 — Profile route phase probe
Added a canonical route-phase probe because the real phone still perceived Profile as slower than Home/Store/Arena.

The probe showed that actual Profile routing was already effectively immediate:
- active mutation: roughly **1–3 ms**;
- first RAF: roughly **7–8 ms**;
- no long tasks.

This classified the remaining phone symptom as visual compositor/raster work rather than route/network/main-thread blocking.

### PR #1816 — mobile Profile compositor corrective
Applied bounded real-WebView-oriented changes:
- mobile Profile cards keep their translucent glass appearance but no longer use per-card `backdrop-filter` / `-webkit-backdrop-filter`;
- desktop/full Profile blur remains;
- Profile cosmetic animation resume is batched instead of waking the entire graph in one frame.

Canonical staging remained green.

### PR #1817 — obsolete-base attempt
A first follow-up branch was closed **without merge** after staging advanced. It is historical only.

### PR #1818 — final Profile tap path
Final bounded follow-up:
- top Profile identity now uses the same direct shell navigation owner instead of falling through the older Home event bridge;
- Entry Effects and Victory Effects Profile decoration/snapshot work is deferred until after first paint and only runs if Profile is still active;
- Store behavior remains unchanged;
- canonical router remains synchronous;
- old router-wide deferred Profile transition was **not** restored;
- frozen game-engine paths were not touched.

Final implementation SHA:
`5c7db576fcfc173d90b6e40f3fdb25e21598380a`

Final canonical staging proof:
- Staging Playwright E2E `36627303146` — **SUCCESS**;
- **7/7 passed**;
- navigation → first usable: about **4,506 ms**;
- Profile first active: about **411 ms**;
- Store: about **137 ms**;
- Arena: about **125 ms**.

Final Profile phase probe:
- first active mutation: **2.3 ms**;
- repeat active mutation: **0.6 ms**;
- first RAF: about **3.7–8.9 ms**;
- long tasks: **none**.

## Manual acceptance and accepted residual

The product owner performed the requested real-phone Telegram check after the final corrective.

Observed result:
- the final Profile-specific visual corrective did **not** produce a clearly perceptible improvement on the real phone;
- Profile can still feel slightly slower / show a short visual hitch compared with the other shell buttons;
- no functional navigation stall, network wait or main-thread long task was reproduced by the canonical probe.

Product-owner decision on 2026-09-29:
- **leave the residual as-is**;
- **close MVP-25.4**;
- do not continue spending time on this residual in the current release path.

This is an **explicitly accepted known residual**, not a forgotten open defect.

Reopen this area only for:
- a new reproducible functional regression;
- a materially worse real-device delay;
- a crash/stuck state;
- or an explicit future optimization task.

## Explicit non-goals preserved

MVP-25.4 did not:
- change accepted game engines/rules;
- change economy or settlement;
- change tournament logic;
- change production/main;
- change production DB or Cron;
- add a second lifecycle/presence owner;
- regress accepted cache/runtime identities merely to satisfy stale predecessor diagnostics.

## Final status

**CLOSED / MANUALLY ACCEPTED WITH KNOWN RESIDUAL / FROZEN**

MVP-25.4 is complete for the current release path.

**NEXT: MVP-25.5 — Security / resilience.**
