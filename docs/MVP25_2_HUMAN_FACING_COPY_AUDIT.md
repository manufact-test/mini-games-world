# MVP-25.2 — Mandatory human-facing copy audit

Date: 2026-09-29  
Base staging SHA: `68162fb1f5cf7e2a909b4a2f4c8c76fbc6bc0574`  
Base tree: `4bfbf0526be5919d86258a59e7ab6b131e06bb09`

## Purpose

MVP-25.2 is the mandatory player-facing language audit from the active 30-MVP roadmap.

The rule is strict:

> Player-facing copy explains what happened and what the player can do next, not how Mini Games World is internally implemented.

Raw technical detail may remain in logs, staging diagnostics, Admin technical disclosure, source comments, internal exception text that is never surfaced, and cache/build identifiers.

This document records the audit before corrective runtime changes.

---

## Audit coverage

The audit followed the current Telegram launch graph:

`bot/helpers/WebAppLaunchUrl.php -> /app/v110.php -> app/runtime/client/version-manifest.php -> active v110 modules`

The current manifest publishes 58 active JavaScript targets. The audit covered their player-visible text/error boundaries, including:

- boot / fatal entry errors;
- Home;
- all game setup/live/result surfaces;
- matchmaking;
- invitations / rematch;
- Friends;
- Arena / rating;
- tournaments;
- Store;
- Profile;
- notifications;
- Support / reports / appeals;
- account data / deletion / export;
- weekly reward/status;
- reconnect / maintenance;
- current RU localization catalog;
- Telegram private `/start` welcome/invite message;
- active player API endpoint error boundaries.

Admin-only technical reports, staging-only diagnostics and source/cache labels were checked only to prove they are not ordinary player copy.

---

# 1. MUST FIX — INTERNAL IMPLEMENTATION LANGUAGE IS PLAYER-VISIBLE

## HF-01 — v110 fatal entry errors expose implementation details

`app/v110.php` can render plain-text fatal messages such as:

- `client version manifest is unavailable`;
- `accepted game owner is unavailable`;
- `accepted stylesheet is unavailable`;
- `source anchor is unavailable`;
- `transformed target is unavailable`;
- `must expose exactly one top-level module bootstrap`.

These are operational diagnostics, not player copy.

**Required correction**

- log the technical reason server-side;
- return one stable Russian player message with a clear next step;
- do not expose import keys, asset names, owner terminology, manifest terminology or bootstrap structure.

---

## HF-02 — generic client API fallback says “Ошибка API”

`app/assets/js/api/client.js` creates generic failures with:

`Ошибка API: <HTTP status>`

The same pattern exists in account-export failure handling.

**Required correction**

Use a human fallback that does not expose API/HTTP implementation details. Preserve structured error `code` for client behavior.

---

## HF-03 — tournament terminal copy leaks Admin/settlement/idempotency internals

`app/assets/js/screens/tournaments-screen-v1.js` currently shows ordinary players phrases including:

- `после Admin review канонический settlement...`;
- `не передана другому владельцу`;
- `Начисление выполняется идемпотентно...`.

These are direct violations of MVP-25.2.

**Required correction**

Explain only the user state:

- the result is recorded;
- a prize may be temporarily under review;
- the player does not need to repeat an action;
- the reward will appear automatically after processing/review;
- if a placement changes after review, the final result will be shown in the tournament.

No Admin/owner/canonical/settlement/idempotency terminology in player UI.

---

## HF-04 — Profile language description uses developer terminology

RU localization currently says:

`Текущая локализация приложения.`

**Required correction**

Use ordinary language such as:

`Текущий язык приложения.`

---

## HF-05 — raw backend exception text can cross into player UI

Current active client screens frequently render `error.message` directly.

Observed player-visible families include:

- Home Support/report flows;
- Friends lookup/actions/report/profile;
- Store purchase/equip/remove;
- Profile load/save/appeal/cosmetics;
- matchmaking start;
- invite/rematch actions;
- game action / leave;
- account-data operations;
- weekly status;
- notification loading.

Many expected backend messages are already valid human copy and should remain specific. The defect is the **unclassified raw exception boundary**: unexpected internal text can reach the same `error.message` channel.

Examples of server-side technical exceptions that currently exist behind public endpoints include:

- `Invite DB bridge requires a stable JSON snapshot capability.`;
- `Notification bridge requires exclusive JSON snapshots.`;
- `Cosmetic Store requires transactional runtime storage.`;
- tournament/internal persistence/identity exceptions in `bot/api.php`.

`api_error()` already sanitizes several known technical patterns through `mgw_public_api_error()`, but the matcher is incomplete, and several endpoints return exception messages directly through `json_response()`.

**Required correction**

Create one public error-message boundary:

1. preserve known domain/player messages;
2. hide unexpected technical/backend text;
3. keep error codes/status for program behavior;
4. log the original technical exception server-side;
5. do not replace every useful error with one generic toast.

This is an error-boundary correction, not a change to gameplay/business semantics.

---

# 2. HUMANIZE — MEANING IS VALID, WORDING IS TOO TECHNICAL

## HF-06 — tournament technical-state labels

Tournament UI includes several state-machine-flavoured phrases such as:

- `Технический перезапуск через 1 минуту.`
- `Технический сбой повторился · матч закрыт без победителя.`

The underlying states are valid and must remain unchanged.

**Correction rule**

Where the word “technical” describes implementation rather than an actual sport/product outcome, rewrite to direct player language:

- `Матч перезапустится через 1 минуту.`
- `Матч не удалось продолжить. Он завершён без победителя.`

Do not change the accepted tournament outcome logic.

---

# 3. INTENTIONAL KEEP — USER-NEEDED PRODUCT LANGUAGE

## IK-01 — “техническое поражение” in game rules and leave confirmation

Examples exist in Reversi/Go rules and active-match leave confirmation.

**KEEP**

Here “техническое поражение” is a player-relevant game outcome, not an implementation detail. The player needs to know the consequence of leaving or timing out.

---

## IK-02 — human domain errors from services

Examples:

- `Недостаточно коинов...`
- `Игрок сейчас занят...`
- `Приглашение не найдено или уже недоступно.`
- nickname validation messages;
- moderation restriction messages;
- Store conflict/recovery messages written as user actions.

**KEEP**

These messages explain what happened and/or what the player can do next.

The corrective must not erase useful domain specificity.

---

## IK-03 — maintenance / temporary-unavailable product states

Examples:

- `Идут технические работы.`
- `Подбор соперников временно отключён.`
- `Турниры временно недоступны.`
- `Приглашения временно отключены.`

**KEEP**, provided custom maintenance text is authored as player-facing copy.

These are legitimate availability states, not unfinished-product placeholders.

---

## IK-04 — Telegram /start and invite welcome copy

Current private-user bot copy:

- `Нажмите кнопку ниже, чтобы начать играть.`
- `Откройте приглашение, проверьте условия и примите матч.`

**KEEP**

The text is concise, human and action-oriented.

---

## IK-05 — internal-only exception text and logs

Examples:

- `Invite entry failed: <status>` in the invite-link module is caught, logged and replaced by a human toast;
- invite reconciliation internal exception triggers refresh without exposing its text;
- Admin technical reports;
- staging diagnostics;
- build/cache/MVP labels in URLs, source comments and data attributes.

**KEEP**

MVP-25.2 governs what ordinary players can see, not internal diagnostics.

---

# 4. PASS — NO COPY CHANGE REQUIRED

The audit found no ordinary-player technical-language blocker in the accepted visual/game renderer families themselves.

Current game rules, game names, board labels, Store item names and standard result terms are product language. Asset query strings, source comments and CSS/JS data markers containing MVP/runtime/owner words are not player-visible copy.

No game mechanic, accepted renderer, economy rule, rating rule or tournament settlement rule needs to change for MVP-25.2.

---

# 5. CORRECTIVE ORDER

Use fresh exact staging for each bounded correction family.

1. **Public fatal/API error boundary**
   - `app/v110.php`;
   - `bot/helpers/response.php`;
   - direct public endpoint exception boundaries;
   - client generic fallback wording.

2. **Tournament human copy**
   - review-hold terminal copy;
   - pending reward copy;
   - technical-state labels where wording is implementation-flavoured.

3. **Profile/localized wording**
   - `profile.language_note`;
   - any directly adjacent copy found by the focused regression.

4. **Raw-error propagation regression**
   - prove known human domain messages stay specific;
   - prove representative internal exceptions are sanitized;
   - prove no player-facing `Ошибка API`, `Admin review`, `канонический settlement` or `идемпотентно` remains in the active product path.

5. exact staging E2E + focused copy contracts.

No `main`, production, production DB, production Cron, game mechanics, economy semantics, rating semantics or tournament settlement semantics belong to these corrections.
