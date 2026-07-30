# Mini Games World clean runtime

This directory is the isolated staging runtime for MVP-14R3.

Open `index.php` directly in staging. It does not replace or import the legacy production application.

Current package scope:

- canonical standard/invite launch parsing;
- one application store and router;
- controlled error boundary;
- one clean API endpoint: `app/runtime/api.php`;
- one staging-only server bootstrap;
- one isolated JSON file repository adapter;
- Telegram initData signature and freshness verification;
- one canonical clean account identity;
- one clean session owner and one client presence owner;
- atomic file locking and publication;
- architecture and integration contract guards.

Isolation rules:

- no `production-v*` or `main-v*` imports;
- no legacy `/bot/api.php`;
- no legacy bootstrap, AuthService, SessionService, StorageFactory, RuntimeStorageRouter or DB bridges;
- no production JSON reads or writes;
- no MySQL adapter in this environment;
- raw Telegram initData, Telegram hashes, query ids and invite tokens are not persisted;
- invalid Telegram initData never falls back to a browser staging identity.

The default staging data directory is outside the repository under `_private_mgw/runtime_staging`. It can be overridden only with `MGW_CLEAN_RUNTIME_DATA_DIR`.

Clean Telegram verification reads a bot token only from `MGW_CLEAN_RUNTIME_BOT_TOKEN` or the environment fallback `MGW_BOT_TOKEN`. It does not load the legacy private config.

Not connected yet:

- matchmaking and active match lifecycle;
- invite and notification product contours;
- production Telegram launch cutover.

Those are added only through clean modules in subsequent packages.
