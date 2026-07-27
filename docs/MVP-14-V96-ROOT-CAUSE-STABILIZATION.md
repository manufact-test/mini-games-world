# MVP-14 v96 — root-cause stabilization

This patch replaces the broken parts of the v95 cross-game layer instead of adding another independent click interceptor.

## Confirmed production causes

- a single global browser session key was reused across Telegram accounts;
- v95 derived non-Tic-Tac-Toe ownership from the generic profile object instead of the authoritative API `me` identity;
- the optimistic renderer replaced live game callbacks with a no-op;
- checkers optimistic state ended every capture immediately and could not represent a forced continuation;
- legacy card rendering could erase an SVG while leaving the v95 icon marker behind;
- one optional profile/history/notification/opponent warm-up failure still failed the whole boot;
- older Telegram mobile clients retained several historical payment rejection ForceReply variants.

## v96 behavior

- device sessions are scoped to the current Telegram user and ownership collisions are rotated/retried once for safe reads;
- known account/session backend errors are returned to the UI in Russian;
- server-provided `me` owns all non-Tic-Tac-Toe optimistic actions;
- actions are serialized per match while the newest optimistic state remains visible;
- game surfaces rendered optimistically keep a real action callback;
- multi-capture checkers chains remain interactive without polling between captures;
- all eight card icons are restored whenever the actual SVG node is missing;
- optional first-click warmers degrade independently and cannot invalidate authenticated bootstrap;
- `/start` publishes a v96 Mini App URL and recognizes both old rejection prompt titles when Telegram sends a stale reply.

## Safety boundary

No database schema, production data, cutover, release, recovery, private configuration, JSON source, Cron or webhook changes are included.
