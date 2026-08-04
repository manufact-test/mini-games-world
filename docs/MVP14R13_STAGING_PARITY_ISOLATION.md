# MVP-14R13.2 — STAGING PARITY + DATA ISOLATION

Status: **CODE READY FOR CI AND STAGING DEPLOYMENT VALIDATION**

Target branch after validation: `agent/mvp-13-2-staging`  
Integration branch: `agent/mvp14r13-staging-parity-isolation`  
Immutable pre-R13 rollback: `backup/staging-pre-r13-2026-08-02` at
`6e6bbcf7da3bfd5e517695e150d45f451a94b9e0`

## 1. Target topology

```text
accepted main application graph
  -> R13 staging integration branch
  -> staging-only private config
  -> existing Hostinger staging project
  -> existing staging Telegram bot/Mini App
  -> isolated staging data directory and database identity
```

The staging application uses the same canonical `/app/v110.php?v=1123` product
runtime as accepted production. The old `/app/?v=85` staging graph and the
isolated `app/runtime` prototype are not the parity target.

## 2. Source parity rule

Application source must be identical to the accepted main source at the R13.2
base, except for R13 staging safety/evidence files in this integration branch.
Environment differences belong only in external private config and protected
infrastructure settings.

No production private file, database, JSON state or bot token is copied into
staging.

## 3. Required staging private config contract

The external staging config must declare all of the following. Secret values are
read from protected Hostinger environment/private storage and never written into
this repository.

```php
return [
    'environment' => 'staging',
    'base_url' => 'https://seashell-okapi-889488.hostingersite.com',
    'allowed_hosts' => [
        'seashell-okapi-889488.hostingersite.com',
    ],

    'bot_token' => (string)getenv('MGW_STAGING_BOT_TOKEN'),
    'staging_bot_username' => (string)getenv('MGW_STAGING_BOT_USERNAME'),

    'data_dir' => (string)getenv('MGW_STAGING_DATA_DIR'),
    'storage_driver' => 'json',

    'database' => [
        'enabled' => false, // keep current cutover state unless separately approved
        'driver' => 'mysql',
        'host' => (string)getenv('MGW_STAGING_DB_HOST'),
        'port' => 3306,
        'name' => (string)getenv('MGW_STAGING_DB_NAME'),
        'user' => (string)getenv('MGW_STAGING_DB_USER'),
        'password' => (string)getenv('MGW_STAGING_DB_PASSWORD'),
        'charset' => 'utf8mb4',
    ],

    'environment_guard' => [
        'production_hosts' => [
            'lemonchiffon-gerbil-545102.hostingersite.com',
        ],
        'production_data_dir' => (string)getenv('MGW_PROTECTED_PRODUCTION_DATA_DIR'),
        'production_database_sha256' => (string)getenv('MGW_PROTECTED_PRODUCTION_DB_SHA256'),
        'production_bot_token_sha256' => (string)getenv('MGW_PROTECTED_PRODUCTION_BOT_SHA256'),
    ],

    'external_payments_enabled' => false,
    'payment_mode' => 'sandbox',
];
```

The protected production values are comparison metadata only. They must never
be returned by public endpoints or committed.

## 4. Canonical staging readiness endpoint

After staging Redeploy, this public GET-only endpoint must return HTTP 200:

```text
https://seashell-okapi-889488.hostingersite.com/bot/staging-readiness.php
```

Expected safe fields:

- `ok: true`;
- `service: mini-games-world-staging-readiness`;
- `environment: staging`;
- `build: mgw-staging-parity-r13.2-v1`;
- 64-character `source_fingerprint_sha256`;
- exact public staging `base_host`;
- exact staging `allowed_hosts`;
- storage driver and safe database summary;
- hashed data/database identities only;
- every `isolation` flag is `true`.

Forbidden output:

- filesystem paths;
- bot tokens or token hashes;
- DB host/name/user/password/DSN;
- protected production directories or fingerprints;
- Telegram initData;
- session or user data.

On production or any non-staging environment, the endpoint returns HTTP 404.
POST and other mutating methods return HTTP 405.

## 5. Clean runtime disposition

`app/runtime` remains only as historical/reference code. It now fails closed
unless all of these are explicitly configured:

```text
MGW_CLEAN_RUNTIME_ENV=staging
MGW_CLEAN_ALLOWED_HOSTS=<exact staging hosts>
```

Browser identity is disabled by default and requires the explicit flag:

```text
MGW_CLEAN_ALLOW_BROWSER_IDENTITY=1
```

That flag is not the final Player A/B authentication and must remain disabled
until R13.3 replaces it with protected signed staging-only test auth.

Without explicit staging environment and allowlist, both clean runtime document
and API return HTTP 404. A production host is rejected even when staging is set.

## 6. Staging deployment sequence

1. Verify the R13.2 PR exact head and required CI success.
2. Merge the exact tested head into `agent/mvp-13-2-staging`, not `main`.
3. Preserve `backup/staging-pre-r13-2026-08-02` unchanged.
4. Verify/update the external staging private config against section 3.
5. Take a staging-only data/config backup.
6. Redeploy the staging Hostinger project from `agent/mvp-13-2-staging`.
7. Do not redeploy production.
8. Open `/bot/staging-readiness.php` from a network path that reaches Hostinger.
9. Save the JSON evidence without adding secrets.
10. Confirm production returns 404 for `/bot/staging-readiness.php` after the
    same safety code is eventually accepted and deployed there.
11. Confirm staging Telegram Web App, webhook and Cron all target the staging
    host and staging private config.
12. Run non-destructive bootstrap/profile/presence smoke.

## 7. Acceptance gate

R13.2 is accepted only when all are true:

- staging branch contains the exact tested integration commit;
- Hostinger staging deploy reports the expected readiness build;
- source fingerprint is captured;
- staging data and database identities are present only as hashes;
- every isolation flag is true;
- staging app opens through the current canonical v110 graph;
- staging bot/Web App URL, webhook and Cron point only to staging;
- no production data, bot or payment service is used;
- a chosen browser runner can establish HTTPS access to staging.

Player A/B auth and mutating E2E remain forbidden until this gate passes.

## 8. Rollback

If staging fails after Redeploy:

```text
redeploy backup/staging-pre-r13-2026-08-02
restore only the staging private config/data backup
restore the previous staging BotFather/webhook/Cron routing if changed
verify the historical staging health/build
```

Do not change `main`, production config, production data or production Telegram
settings during this rollback.
