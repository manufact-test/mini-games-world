# MGW SMALL CANONICAL — AFTER PR #1725 / MVP-22 ADMIN ACCEPTED FOR NOW

**Date:** 2026-09-25  
**Repository:** `manufact-test/mini-games-world`  
**Authoritative staging:** `agent/mvp-13-2-staging`  
**Support repair PR:** #1723  
**Web Admin rework PR:** #1724  
**Manual-review polish PR:** #1725  
**Current staging SHA:** `27b6095a512f0f6306fad9d0291d0515635099b8`  
**Admin UX:** MANUAL ACCEPTED FOR NOW  
**MVP-22.1 Support:** OPEN / manual acceptance resumes next  
**Next:** player-facing Support attachment UX

## What is now frozen for the Admin checkpoint

The current Web Admin is the accepted working foundation for now. Future visual corrections are allowed if new issues are noticed, but do not regress the structure below.

### Telegram Admin entry

- Visible Admin launch button is **«🌐 Открыть панель администратора»**.
- Legacy Admin callbacks/commands remain implemented as hidden emergency fallback.
- Telegram dashboard keeps the compact operational summary and command list.
- The long **«Последние матчи»** and **«Последние операции»** blocks are intentionally removed from the dashboard.

### Web Admin shell

Top-level sections remain exactly:

1. Обзор
2. Пользователи
3. Поддержка
4. Турниры и сезоны
5. Экономика
6. Уведомления
7. Система
8. Тесты

No extra parent section such as «Работа».

- Main title: **«Панель администратора»**.
- Environment is human-readable: **«ТЕСТОВАЯ СРЕДА»** / **«РАБОЧАЯ СРЕДА»**.
- «Тесты» is available only in staging and hidden in production.
- Mobile navigation shows all eight sections without clipping.
- Nested sections expose **«← К обзору»**.
- Operator-facing copy is Russian; technical API enum values stay internal.
- Existing backend owners remain authoritative; no second source of truth was introduced.

### Users / Support mobile polish

- Mobile card spacing and empty-state spacing were corrected.
- Primary actions expand to the available width; paired actions share the row and collapse to one column on very narrow screens.
- Support keeps queue → ticket → back flow.
- Admin reply attachment picker is custom and readable:
  - «Выбрать файлы»;
  - selected filename for one file;
  - «Выбрано файлов: N» for several files.
- Ticket/status/priority/owner/history mechanisms and Support backend data were not replaced.

### Tournament / rating / diagnostics presentation

Operator-facing English labels were removed from visible UI where practical:

- competition / preseason state presentation;
- rating metric labels;
- replay diagnostics labels;
- staging/test helper text;
- rollback wording;
- tournament helper copy.

Technical enum values and existing owners remain unchanged.

## Verification

PR #1725 final candidate:

`3b673c3cab7f93db516cd5ae4ed5314c48473de3`

All 8 PR checks passed, including:

- MVP-22 Admin rework contract;
- Support tickets;
- Support MySQL 8.4;
- economy config;
- unified balance;
- notification pipeline;
- event-log / replay contract;
- accepted Profile avatar preservation.

Merged staging:

`27b6095a512f0f6306fad9d0291d0515635099b8`

Staging E2E run **36107527680**: **SUCCESS**.

- exact Hostinger staging commit found;
- managed migrations / projection diagnostics — success;
- A/B preflight — success;
- pinned Playwright install — success;
- two-context staging browser test — success;
- final `staging-playwright-e2e` commit status — **SUCCESS**.

Some older push-wide safety/live-owner workflows can still report their known noisy red baseline on staging pushes. They are not the authoritative Admin deploy gate. The exact staging E2E above is green.

## MVP-22.1 Support checkpoint

Existing manual ticket:

`SUP-260924-905F476B`

Already proven during manual testing:

- ticket creation works;
- a second message works;
- an image below 2 MB was sent successfully;
- Support backend ticket/thread/history path exists.

The next unresolved product defect is **player-facing attachment selection UX**, not the Admin shell.

Required contract for the next fix:

- maximum **3 attachments**;
- maximum **2 MB per file**;
- validate immediately when a file is selected, not only on submit;
- an oversized file must be rejected immediately with a clear message;
- show each accepted file's name and size;
- every selected file must have a visible **×** removal action;
- preserve successful send of valid messages/images and existing ticket data.

Later manual-polish items already recorded, after the attachment fix:

- «Мои обращения» differs from the main menu in size/color/geometry;
- «Ваше сообщение» block is too narrow;
- red remove-cross alignment needs correction;
- re-check opening existing attachments in Web Admin during the resumed end-to-end acceptance.

Do not restart or redesign Support backend while fixing these UI issues.
