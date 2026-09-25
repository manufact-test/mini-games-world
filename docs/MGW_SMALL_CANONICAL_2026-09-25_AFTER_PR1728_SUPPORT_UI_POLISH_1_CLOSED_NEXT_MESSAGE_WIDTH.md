# MGW SMALL CANONICAL — AFTER PR #1728 / ADMIN ACCEPTED / SUPPORT UI POLISH 1 CLOSED

**Date:** 2026-09-25  
**Repository:** `manufact-test/mini-games-world`  
**Authoritative staging:** `agent/mvp-13-2-staging`  
**Current staging SHA:** `f6139667465bc0a1236d7e64bea31e63e36c72c9`  
**Support repair PR:** #1723  
**Web Admin rework PR:** #1724  
**Admin manual-review polish PR:** #1725  
**Checkpoint correction PR:** #1727  
**Support UI polish 1 PR:** #1728  
**Web Admin:** ACCEPTED FOR NOW  
**MVP-22.1 Support:** FUNCTIONALLY WORKING / VISUAL POLISH CONTINUES  
**Next exact task:** Support UI polish 2 — «Ваше сообщение»

## Admin checkpoint

Current Admin foundation is accepted for now. Future corrections are allowed only for reproduced issues; do not regress the accepted structure.

### Telegram Admin

- Visible launch button: **«🌐 Открыть панель администратора»**.
- Legacy Admin callbacks/commands remain hidden as emergency fallback.
- Telegram dashboard keeps compact summary + commands.
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
- «Тесты» available only in staging;
- all eight mobile nav items reachable;
- **«← К обзору»** return path;
- mobile spacing/actions corrected;
- readable Admin Support attachment picker;
- existing backend owners preserved.

PR #1725 exact staging E2E run **36107527680**: SUCCESS.

## MVP-22.1 Support checkpoint

Existing manual ticket:

`SUP-260924-905F476B`

Already proven / implemented in PR #1723 and manual acceptance:

- ticket creation works;
- second message works;
- valid image below 2 MB sends successfully;
- attachment selection validates immediately;
- maximum **3 files**;
- maximum **2 MB per file**;
- oversized files are rejected before submit;
- selected files show filename + size;
- selected files can be removed with **×**;
- ticket/thread/history backend remains canonical.

Do not reopen attachment validation unless the defect reproduces again.

## Support UI polish item 1 — CLOSED

PR #1728 closed the first deferred visual issue:

**«Мои обращения» now matches the rest of the menu.**

Changes:

- removed one-off `support-hub` styling;
- removed its custom gradient/background/border treatment;
- restored the standard menu component geometry;
- restored standard ticket icon `🎫`;
- kept `supportTicketsBtn` behavior unchanged;
- Support backend/data untouched.

Focused Support CI passed, including Support MySQL 8.4.

Merged staging SHA:

`f6139667465bc0a1236d7e64bea31e63e36c72c9`

Staging E2E run **36109415151**:
- attempt 1 failed on an unrelated transient `/bot/leaderboard.php` 500;
- exact same SHA rerun, attempt 2: **SUCCESS**;
- exact Hostinger deployment, preflight and two-context browser test passed.

## Remaining deferred Support UI fixes

2. **«Ваше сообщение»** — user message block is visually too narrow; stretch it to the full available thread width while keeping admin/user distinction.
3. **Red ×** — remove unnecessary accent and optically center the selected-file remove icon while preserving a clear tap target.

After both are closed, resume the remaining MVP-22.1 manual acceptance from `SUP-260924-905F476B`.

Do not redesign Support backend or introduce a parallel ticket owner.
