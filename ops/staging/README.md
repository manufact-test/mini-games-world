# Mini Games World staging deployment

This directory contains deployment-only templates. Real domains, tokens, passwords, paths and admin IDs stay outside GitHub.

## Required isolation

- a dedicated Hostinger subdomain and document root;
- a deployment copied from the selected Git branch, never the production folder;
- a private staging config with `environment=staging`;
- a dedicated `mgw_staging_data` directory;
- a separate Telegram test bot and token;
- the test token is verified against the configured staging bot username before webhook installation;
- the existing production administrators are copied only into the private staging `admin_ids` block;
- HTTP Basic Auth for all browser routes;
- the Telegram webhook is the only route exempt from Basic Auth;
- `X-Robots-Tag: noindex, nofollow, noarchive` is emitted for staging;
- Hostinger may serve its own root `robots.txt`, so Basic Auth and the HTTP header are the effective indexing barriers;
- all real payment modes remain disabled.

## Deployment order

1. Create the staging subdomain and a new empty document root.
2. Deploy the repository into that root.
3. Copy `.htaccess.example` to the staging root as `.htaccess`, then set the real absolute `.htpasswd` path.
4. Create `_private_mgw/config.php` next to `public_html` using `bot/config/config.staging.example.php` as a key map.
5. Create the dedicated `mgw_staging_data` directory next to `public_html` and make it writable by PHP.
6. Create a separate Telegram bot and put only its token in the private staging config.
7. Copy the current production `admin_ids` values into the private staging config without committing them.
8. Generate a separate random setup key of at least 20 characters and store it only in the private `staging_setup_key` value.
9. Open `/bot/tools/staging-webhook.php` through Basic Auth, enter the setup key and install the webhook.
10. Verify that the displayed bot username and webhook URL are the staging values.
11. Copy `bot/config/runtime.example.php` to `_private_mgw/runtime.php`. This file contains only operational flags and no secrets.

No production bot token or production token hash is required for staging setup. The production host in `environment_guard.production_hosts` is collision metadata only: staging never connects to it and never reads production data through that value.

## Runtime controls

The private `_private_mgw/runtime.php` file controls maintenance and feature availability without touching the main config, bot token, admin IDs or setup key.

- `maintenance_mode=true` blocks new matches, invitations, payment drafts, shop orders and admin financial writes;
- `financial_read_only=true` blocks new stake matches and financial writes while allowing active matches to finish and settle;
- `features.matchmaking`, `features.invitations`, `features.payments` and `features.shop` can be disabled separately;
- each released game can be disabled separately under `games`;
- disabling a game blocks new searches, bots and invitations for that game but does not break an already active match;
- deleting `runtime.php` returns all current product features to their safe defaults.

The safe status endpoint is `/bot/health.php`. It reports build, environment, storage readiness and public runtime flags. It never displays tokens, setup keys, admin IDs or filesystem paths.

## Required smoke test

- `/start` is answered by the staging bot;
- the bot opens only the staging Mini App;
- production users and data do not appear in staging;
- the admin panel opens only for configured administrators;
- all eight games open;
- one complete bot match works;
- one invitation between two test accounts works;
- `/bot/health.php` reports `ok=true`, `environment=staging` and the current build;
- disabling Domino blocks a new Domino match while another game still starts;
- a Domino match that was active before the flag change can still make moves and finish;
- maintenance blocks new operations but does not break `/start`, health or active-game actions;
- all runtime flags are restored to their normal values after the test;
- production bot and production Mini App remain unchanged.

## Rollback

1. Delete `_private_mgw/runtime.php` to restore safe runtime defaults.
2. Re-deploy the previous staging commit if code rollback is needed.
3. Remove the staging webhook/deployment/data only when the entire staging environment is being retired.

Production files, token, webhook and data are not touched by staging rollback.
