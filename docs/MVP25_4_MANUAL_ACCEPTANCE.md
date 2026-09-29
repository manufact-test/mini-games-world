# MVP-25.4 — Manual acceptance

Date: 2026-09-29

Technical implementation checkpoint:
- staging SHA: `5c7db576fcfc173d90b6e40f3fdb25e21598380a`;
- exact canonical Staging Playwright E2E: `36627303146` — **SUCCESS**;
- canonical result: **7/7 passed**.

## Technical evidence available before the final human check

Cold-start:
- baseline navigation → first usable: about **9,179 ms**;
- accepted final navigation → first usable: about **4,506 ms**.

Reliability:
- transient offline failure was detected;
- recovery after connectivity restoration completed in about **930 ms**;
- app remained interactive after recovery.

Profile route probe:
- first route active mutation: **2.3 ms**;
- repeat route active mutation: **0.6 ms**;
- first animation frame: about **3.7–8.9 ms**;
- long tasks: **none**.

## Final real-device check

Requested sequence:
1. repeatedly switch `Home → Profile → Store → Profile → Arena → Profile`;
2. check the bottom Profile button;
3. check the top avatar/Profile identity;
4. compare perceived response with the other shell buttons.

## Product-owner result

**PASS FOR RELEASE PATH — ACCEPTED WITH KNOWN RESIDUAL**

The product owner reported that the final Profile-specific visual corrective did not feel meaningfully different on the real phone.

The residual is therefore recorded accurately:
- Profile may still feel slightly slower / show a short visual hitch on some real Telegram mobile WebView runs;
- the technical route itself is near-instant and shows no long main-thread task;
- the residual is not being treated as a blocker for the current release path.

Explicit product-owner instruction:
- leave the current implementation as-is;
- close the task;
- freeze MVP-25.4 and continue forward.

## Closure rule

Do **not** reopen MVP-25.4 merely because this known residual still exists.

Reopen only with a new reproducible regression, materially worse behavior, crash/stuck state, or explicit future optimization scope.

## Final status

**MVP-25.4 — CLOSED / MANUALLY ACCEPTED WITH KNOWN RESIDUAL / FROZEN**

**NEXT: MVP-25.5 — Security / resilience.**
