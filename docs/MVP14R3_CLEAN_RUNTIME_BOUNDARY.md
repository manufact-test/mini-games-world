# MVP-14R3 clean runtime boundary

## Status

This document is authoritative for the `app/runtime` staging implementation.
The existing versioned application remains a frozen legacy release and is not a dependency of the clean runtime.

## Non-negotiable rules

1. `app/runtime` does not import `production-v*`, `main-v*` or versioned screens.
2. Each product contour has one owner and one explicit state machine.
3. DOM is a projection of store state, never the business-state source.
4. No global fetch wrapper, business MutationObserver, capture interceptor or hidden fallback.
5. One canonical WebApp URL handles standard launch and invite launch.
6. One storage adapter is selected per environment; JSON/MySQL runtime bridges are forbidden.
7. A failed implementation is replaced inside its complete contour, not covered by another layer.
8. Staging E2E with two Telegram accounts is required before production cutover.

## First clean package

The first package intentionally contains only:

- isolated no-store staging entrypoint;
- one module entry;
- canonical launch parser;
- one store;
- explicit router;
- controlled error boundary;
- architecture contract test.

It intentionally does not call the legacy `/bot/api.php` endpoint. A clean server bootstrap and a single staging repository adapter are the next package.

## Emergency rollback

The exact tree of accepted v110 is represented by:

`3b1550d7d9e4464f00ad3d390fa808b3970979e4`

This is an emergency rollback only. It restores known notification and post-leave defects and does not repair the old shared invite URL.
