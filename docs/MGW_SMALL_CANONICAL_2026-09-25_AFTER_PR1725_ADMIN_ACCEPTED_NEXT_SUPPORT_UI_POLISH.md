# MGW SMALL CANONICAL — AFTER PR #1725 / ADMIN ACCEPTED FOR NOW / SUPPORT UI NEXT

**Date:** 2026-09-25  
**Repository:** `manufact-test/mini-games-world`  
**Authoritative staging:** `agent/mvp-13-2-staging`  
**Support repair PR:** #1723  
**Web Admin rework PR:** #1724  
**Admin manual-review polish PR:** #1725  
**Accepted runtime SHA:** `27b6095a512f0f6306fad9d0291d0515635099b8`  
**Docs checkpoint base:** `b6270ff541c9c7eb79fa74f190581d0c5a1bb803`  
**Web Admin:** MANUAL ACCEPTED FOR NOW  
**MVP-22.1 Support:** FUNCTIONALLY PASSED IN MINI APP / 3 VISUAL POLISH ITEMS OPEN  
**Exact next:** Support UI polish item 1 — «Мои обращения»

## Admin checkpoint frozen for now

The current Admin foundation is accepted for now. Future fixes are allowed when a concrete issue is reproduced, but do not regress the accepted structure.

### Telegram Admin

- Visible launch button: **«🌐 Открыть панель администратора»**.
- Legacy callbacks/commands remain hidden as emergency fallback.
- Dashboard keeps compact summary + commands.
- **«Последние матчи»** and **«Последние операции»** are intentionally removed.

### Web Admin

Top-level sections remain exactly:

1. Обзор
2. Пользователи
3. Поддержка
4. Турниры и сезоны
5. Экономика
6. Уведомления
7. Система
8. Тесты

Accepted properties:

- title **«Панель администратора»**;
- Russian operator-facing copy;
- **«ТЕСТОВАЯ СРЕДА»** / **«РАБОЧАЯ СРЕДА»**;
- «Тесты» hidden in production;
- all eight mobile nav items remain reachable;
- **«← К обзору»** return path;
- corrected mobile spacing/actions;
- readable Admin Support reply file picker;
- existing backend owners preserved.

PR #1725 final candidate `3b673c3cab7f93db516cd5ae4ed5314c48473de3` passed all 8 PR checks.

Staging E2E run **36107527680** on `27b6095a…`: **SUCCESS**.

## MVP-22.1 Support state

Existing manual ticket:

`SUP-260924-905F476B`

PR #1723 already repaired the functional Support ticket UX before the Admin detour.

Already proven / implemented:

- ticket creation works;
- second message works;
- second image below 2 MB sent successfully;
- attachment picker validates on selection;
- maximum **3 files**;
- maximum **2 MB per file**;
- invalid oversized files are rejected before submit;
- accepted files display filename + size;
- selected files can be removed with **×**;
- ticket/thread/history backend remains canonical.

Therefore attachment validation is **not** the next task.

## Exactly three deferred Mini App visual fixes

These were explicitly parked before entering Web Admin work:

1. **«Мои обращения»** — make it match the rest of the menu in size, color and geometry.
2. **«Ваше сообщение»** — stretch the user message block to the full available width.
3. **Red ×** — remove the unnecessary visual accent and optically center the remove icon.

Work them in this order.

Do not redesign Support backend or replace the ticket owner while doing these visual corrections.

After the three fixes, resume the remaining MVP-22.1 manual acceptance from the existing ticket instead of creating a parallel implementation.
