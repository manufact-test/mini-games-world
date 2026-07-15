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
- `robots.txt` and `X-Robots-Tag` block indexing;
- all payment modes remain disabled.

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

No production bot token or production token hash is required for staging setup. The production host in `environment_guard.production_hosts` is collision metadata only: staging never connects to it and never reads production data through that value.

## Required smoke test

- `/start` is answered by the staging bot;
- the bot opens only the staging Mini App;
- production users and data do not appear in staging;
- the admin panel opens only for configured administrators;
- all eight games open;
- one complete bot match works;
- one invitation between two test accounts works;
- production bot and production Mini App remain unchanged.

## Rollback

Remove the staging webhook, delete the staging deployment/document root and staging data directory. Production files, token, webhook and data are not touched.
