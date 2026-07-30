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
- atomic file locking and publication;
- architecture and integration contract guards.

Isolation rules:

- no `production-v*` or `main-v*` imports;
- no legacy `/bot/api.php`;
- no legacy bootstrap, StorageFactory, RuntimeStorageRouter or DB bridges;
- no production JSON reads or writes;
- no MySQL adapter in this environment;
- invite tokens are not persisted by the staging bootstrap.

The default staging data directory is outside the repository under `_private_mgw/runtime_staging`. It can be overridden only with `MGW_CLEAN_RUNTIME_DATA_DIR`.

Not connected yet:

- Telegram authentication and canonical account identity;
- authoritative session and presence;
- match, invite and notification product contours.

Those are added only through clean modules in subsequent packages.
