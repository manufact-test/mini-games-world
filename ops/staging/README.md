# Mini Games World staging deployment

This directory contains deployment-only templates. Real domains, tokens, passwords, paths and admin IDs stay outside GitHub.

## Required isolation

- a dedicated Hostinger subdomain and document root;
- a deployment copied from the selected Git branch, never the production folder;
- a private staging config with `environment=staging`;
- a dedicated `mgw_staging_data` directory;
- a separate Telegram test bot and token;
- HTTP Basic Auth for all browser routes;
- the Telegram webhook is the only route exempt from Basic Auth;
- `robots.txt` and `X-Robots-Tag` block indexing;
- all payment modes remain disabled.

## Deployment order

1. Create the staging subdomain and a new empty document root.
2. Deploy the repository into that root.
3. Copy `.htaccess.example` to the staging root as `.htaccess`, then set the real absolute `.htpasswd` path.
4. Create a private staging config outside the public root using `bot/config/config.staging.example.php` as a key map.
5. Point `MGW_CONFIG_FILE` to the private staging config if the default private-config location is not used.
6. Create the dedicated staging data directory and make it writable by PHP.
7. Create a separate Telegram bot and put only its token in the private staging config.
8. Generate a separate random setup key of at least 20 characters and store it only in the private `staging_setup_key` value.
9. Open `/bot/tools/staging-webhook.php` through Basic Auth, enter the setup key and install the webhook.
10. Verify that the displayed bot username and webhook URL are the staging values.

## Required smoke test

- `/start` is answered by the staging bot;
- the bot opens only the staging Mini App;
- production users and data do not appear in staging;
- all eight games open;
- one complete bot match works;
- one invitation between two test accounts works;
- production bot and production Mini App remain unchanged.

## Rollback

Remove the staging webhook, delete the staging deployment/document root and staging data directory. Production files, token, webhook and data are not touched.
