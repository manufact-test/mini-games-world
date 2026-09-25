# MGW CURRENT SHORT ROADMAP — AFTER PR #1728 / NEXT SUPPORT UI POLISH 2

**Date:** 2026-09-25  
**Repository:** `manufact-test/mini-games-world`  
**Authoritative staging:** `agent/mvp-13-2-staging`  
**Current staging SHA:** `f6139667465bc0a1236d7e64bea31e63e36c72c9`  
**Web Admin:** ACCEPTED FOR NOW  
**Support backend/functionality:** WORKING  
**Support UI polish 1:** CLOSED  
**Exact next task:** Support UI polish 2 — «Ваше сообщение»

## Where we are

The Admin detour is complete enough to stay frozen for now.

MVP-22.1 Support functional flow already works:

- ticket creation;
- replies;
- attachments;
- immediate 3 × 2 MB validation;
- selected-file list and removal;
- existing ticket history;
- Admin Support thread.

Manual ticket remains:

`SUP-260924-905F476B`

## Closed now — Task 1

**«Мои обращения»** was visually inconsistent with the other menu items.

PR #1728 fixed it by:

- removing the custom `support-hub` class;
- removing custom Support-only gradient/icon CSS;
- returning the item to the shared standard menu component;
- using the standard ticket icon `🎫`;
- preserving the existing click behavior.

Focused Support CI passed, Support MySQL 8.4 passed.

Staging:
`f6139667465bc0a1236d7e64bea31e63e36c72c9`

Staging E2E run **36109415151**, attempt 2: **SUCCESS**.

## Task 2 — «Ваше сообщение»

Start here next.

Problem:

- the user's message card in the Support thread is intentionally constrained to about 88% width;
- visually it looks too narrow and detached from the rest of the thread.

Required result:

- user message card uses the full available thread width;
- preserve clear visual distinction between user and Support messages;
- no clipping on narrow Telegram WebView sizes;
- long text still wraps correctly;
- attachments inside the message remain usable;
- do not change ticket data, backend, message ownership or thread order.

After implementation:

1. focused Support regression;
2. merge to staging;
3. exact staging deploy;
4. exact staging E2E;
5. manual Telegram visual check.

## Task 3 — red ×

After Task 2 is accepted:

- remove unnecessary red/accented styling from selected-file remove control;
- optically center the ×;
- preserve clear remove affordance and tap target.

## Then

Resume the rest of MVP-22.1 manual acceptance from `SUP-260924-905F476B`.

If another concrete Support/Admin issue reproduces, fix only the reproduced issue. Do not rebuild already accepted owners.
