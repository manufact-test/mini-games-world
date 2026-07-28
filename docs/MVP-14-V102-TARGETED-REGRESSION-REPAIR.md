# MVP-14 v102 — Targeted regression repair

## Scope

This release repairs only the production regressions reported after v101:

1. balance history delayed loading surface and mobile `API 200`;
2. match history delayed loading surface and mobile `API 200`;
3. black Mini App surface while returning from Telegram prepared-message sharing;
4. Battleship setup counters, immediate next-ship placement and repeated randomize latency;
5. voluntary surrender / exit responsiveness.

The accepted v101 speed layer, search cancellation, player picker, passive secondary-device lock, notification speed, icons, avatar, economy rules and unrelated game behavior remain unchanged.

## History repair

- a capture-phase v102 owner intercepts only `balanceHistoryBtn` and `matchHistoryBtn`;
- both views reuse one validated history snapshot;
- history is prefetched after app readiness and when the account menu starts opening;
- concurrent requests are deduplicated;
- the response is read as text, a possible UTF-8 BOM is removed, then JSON is parsed;
- an empty or malformed HTTP 200 response is not cached and is retried once;
- the previous `Загружаем историю…` and `Загружаем матчи…` sheets are never painted by v102;
- the existing menu remains visible until complete history content is ready.

## Telegram return repair

- the currently visible invitation conditions sheet is captured before `shareMessage`;
- Telegram `activated` and document visibility restoration reactivate that same surface before the share callback;
- successful send replaces it with `Приглашение отправлено`;
- cancellation restores the exact prior conditions surface and discards the draft;
- no airplane or application-side waiting sheet is introduced.

Telegram still owns its native final `Готово` state.

## Battleship repair

- only Battleship is routed through a v102 renderer wrapper;
- completed local ships update `my_board`, `my_fleet`, `fleet_placed` and `remaining_to_place` in one frame;
- the next legal ship can be selected immediately without waiting for the previous server response;
- randomize creates one complete valid client fleet and paints it immediately;
- repeated randomize clicks keep the running request and only the newest queued intention;
- the exact fleet is sent once to the server;
- before any mutation, the server strictly validates scalar integers, straight contiguous geometry and fleet composition `1×4, 2×3, 3×2, 4×1`;
- after validation, the existing authoritative `clear_fleet` and ten `place_ship` actions enforce overlap, adjacency and boundary rules inside the existing API transaction;
- malformed fleets never clear or partially mutate the current setup.

The new setup model is guarded by `v102-mvp14-targeted-regression-repair`; retained v100 and v101 launches continue using their previous model.

## Surrender / exit repair

- after confirming voluntary exit, polling stops immediately;
- a local technical-defeat result is shown before waiting for `leave_game`;
- result actions remain disabled until server confirmation;
- on success the authoritative game, balances and weekly progress are reconciled;
- on failure the result sheet closes, the authoritative active game is restored and polling resumes.

## Routing safety

- ordinary matchmaking uses `search-screen-v102.js` and enters `game-screen-v102-safe.js`;
- invitation and deep-link matches are published by the retained session transport and enter the same v102 owner;
- duplicate snapshots cannot reset a queued setup action or pending surrender;
- silent search cancellation is copied unchanged from v100;
- all non-Battleship rendering continues through the existing game router.

## Automated validation

Targeted executable tests cover:

- history empty-200 retry, ready-only rendering and shared snapshot;
- Telegram activated/cancel/success return surfaces;
- repeated random fleet generation, counts, geometry and no-touch rules;
- exact server fleet decomposition and invalid-input no-mutation behavior;
- v100 versus v102 optimistic build guard;
- surrender model and randomize queue coalescing;
- source-level owner routing and forbidden historical loaders.

Full repository CI and DB Primary Staging Entrypoint Selector Safety must both be green on the exact release head before the pull request leaves draft.

## Production

Production deployment remains code-only and requires explicit exact-head authorization plus manual Hostinger Redeploy. This release does not change database schema/data, private config, runtime JSON state/data, Cron, webhook, cutover, release, recovery, rollback or rearm controls.
