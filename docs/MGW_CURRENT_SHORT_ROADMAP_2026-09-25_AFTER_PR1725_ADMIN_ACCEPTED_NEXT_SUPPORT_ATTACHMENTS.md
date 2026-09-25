# MGW CURRENT SHORT ROADMAP — ADMIN ACCEPTED / RESUME MVP-22.1 SUPPORT

**Date:** 2026-09-25  
**Repository:** `manufact-test/mini-games-world`  
**Authoritative staging:** `agent/mvp-13-2-staging`  
**Current staging SHA:** `27b6095a512f0f6306fad9d0291d0515635099b8`  
**PR #1723:** Support repair merged  
**PR #1724:** Web Admin rework merged  
**PR #1725:** Admin manual-review polish merged  
**Web Admin:** ACCEPTED FOR NOW  
**MVP-22.1 Support:** OPEN  
**Exact next task:** player-facing attachment UX

## Where we are

The Web Admin detour is complete enough to continue MVP-22.1 Support.

Accepted current Admin baseline:

- Russian launch button and Russian Admin title;
- eight top-level sections;
- complete mobile nav;
- explicit test/production environment labels;
- «← К обзору» escape path;
- corrected mobile spacing/actions;
- readable Support reply file picker;
- Russian operator-facing rating/tournament/diagnostic copy;
- Telegram dashboard no longer shows recent matches or recent operations.

Staging deployment and two-context E2E are green on `27b6095a…`.

## Task 1 — fix player-facing Support attachments

This is the first task to start now.

Problem reproduced in manual acceptance:

- a file over 2 MB can be selected;
- the user learns it is invalid only when trying to send;
- selected attachments do not provide enough immediate feedback/control.

Required result:

- limit is visibly stated as **up to 3 files, 2 MB each**;
- validate file size immediately on selection;
- reject >2 MB before submit and show a clear error immediately;
- never silently keep the rejected file in selection;
- show filename + human-readable size for each accepted attachment;
- add a visible **×** per file to remove it before sending;
- enforce the 3-file limit immediately;
- keep valid image/message sending unchanged;
- do not alter Support backend ownership or existing ticket `SUP-260924-905F476B`.

After implementation:

1. automated focused regression;
2. merge to staging;
3. exact staging E2E;
4. manual Telegram check:
   - one valid file;
   - multiple valid files;
   - fourth file;
   - >2 MB file;
   - remove one file with ×;
   - send remaining files;
   - verify thread and Admin attachment opening.

## Task 2 — remaining Support UI polish

Only after Task 1 is accepted:

- align **«Мои обращения»** with the main menu in size/color/geometry;
- widen **«Ваше сообщение»** block;
- correct red × optical centering if still reproduced after Task 1;
- finish any attachment-open issue reproduced in Web Admin.

## Then

Resume the rest of MVP-22.1 manual acceptance from the existing ticket rather than creating a parallel Support implementation.

Do not move to a new MVP until this Support acceptance slice is closed or explicitly deferred.
