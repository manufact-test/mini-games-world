# Mini Games World clean runtime

This directory is the isolated staging runtime for MVP-14R3.

Open `index.php` directly in staging. It does not replace or import the legacy production application.

Current package scope:

- canonical standard/invite launch parsing;
- one application store and router;
- controlled error boundary;
- one clean API endpoint: `app/runtime/api.php`;
- one staging-only application service;
- one isolated JSON state store adapter;
- Telegram initData signature and freshness verification;
- one canonical clean account identity;
- one clean session owner and one client presence owner;
- one authoritative Tic Tac Toe match service;
- one client match owner for search, moves, surrender, result and immediate new search;
- independent command and background-poll request lanes inside that one match owner;
- player commands abort active background polling instead of waiting behind it;
- normal win, loss and draw keep the result screen;
- manual surrender enters an explicit local `surrendering` transition and returns to home immediately;
- a play request made during surrender is queued inside the same match owner and starts only after authoritative release and result dismissal;
- command idempotency and atomic settlement;
- monotonic server revision application on the client;
- read-only shared-lock match sync plus atomic mutation publication;
- exact client/server build markers for deployment verification;
- architecture and two-player integration guards.

Isolation rules:

- no `production-v*` or `main-v*` imports;
- no legacy `/bot/api.php`;
- no legacy bootstrap, AuthService, SessionService, GameService, StorageFactory, RuntimeStorageRouter or DB bridges;
- no production JSON reads or writes;
- no MySQL adapter in this environment;
- no old clean v1/v2 repository or bootstrap owner remains active;
- raw Telegram initData, Telegram hashes, query ids and invite tokens are not persisted;
- invalid Telegram initData never falls back to a browser staging identity.

The default staging data directory is outside the repository under `_private_mgw/runtime_staging`. It can be overridden only with `MGW_CLEAN_RUNTIME_DATA_DIR`.

The current clean schema is v3 and publishes only `runtime-state-v3.json`. The previous staging files are not read or migrated into this contour.

Clean Telegram verification reads a bot token only from `MGW_CLEAN_RUNTIME_BOT_TOKEN` or the environment fallback `MGW_BOT_TOKEN`. It does not load the legacy private config.

Not connected yet:

- invite and notification product contours;
- the remaining seven games;
- production Telegram launch cutover;
- production storage migration.

Those are added only through clean modules in subsequent packages.
