# MVP-25.2 — Manual acceptance checklist

Date: 2026-09-29

Final status:

**CLOSED / MANUALLY ACCEPTED / FROZEN**

This check is intentionally short. It validates only ordinary-player wording and visible error language; it does not reopen accepted game mechanics, economy, tournament logic or MVP-24.

## Manual acceptance checklist

1. **Normal Mini App launch**
   - fully close and reopen the staging Mini App;
   - Home must open normally;
   - no manifest / bootstrap / owner / API / HTTP / DB / runtime text may appear.

2. **Profile language copy**
   - open Profile;
   - the language note must read **«Текущий язык приложения.»**;
   - there must be no word **«локализация»** in ordinary player copy.

3. **Data and account**
   - open More → **«Данные и аккаунт»**;
   - open the delete-account confirmation;
   - the retention explanation must use ordinary product language about matches, game-coin operations, security and legal requirements;
   - there must be no **«техническая история»** or **«финансовый аудит»** wording.

4. **Arena → Tournaments**
   - open the current tournament surface and any reachable Hall/result/reward state;
   - no **Admin review**, **канонический settlement**, **идемпотентно**, owner/runtime/projection wording;
   - real outcome wording **«техническое поражение»** is allowed where it is genuinely the match result.

5. **Network-error wording**
   - on any safe read-only screen such as Arena rating/Profile, temporarily disable network and trigger a refresh/open;
   - expected wording is human, e.g. **«Не удалось связаться с сервером. Проверьте интернет и попробуйте ещё раз.»** or another product-specific human error;
   - native **Failed to fetch**, API/HTTP status, SQL/DB or stack/exception text must never be visible;
   - restore network and confirm the screen recovers normally.

6. **Invites**
   - open the normal invite flow;
   - if network is unavailable during an invite request, the user must see the same human network message, not browser exception text;
   - after network returns, normal invite flow must still work.

## Acceptance result

Accepted by the product owner on 2026-09-29 after the final staging build `2661c2b0c166c0dc767108de6df2ee0538a31a55`.

The product owner reported the manual review as normal and explicitly instructed closure.

Result: **PASS**

MVP-25.2 is **CLOSED / MANUALLY ACCEPTED / FROZEN**.

Next roadmap point: **MVP-25.3 — Final UX consistency.**
