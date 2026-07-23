# MVP-14.10a — production primary coordinator foundation

This layer prepares the production DB-primary activation contract without routing any live request to the database.

## What it verifies

`ProductionPrimaryRuntimeActivationContract` accepts only an exact, private and internally consistent production cutover state:

- environment is exactly `production`;
- global storage remains `json` as the rollback source;
- production database configuration is enabled and has a valid identity fingerprint;
- runtime activation is bound to build `v103-mvp14-production-cutover`;
- activation plan/source fingerprints are exact lowercase SHA-256 values;
- all nine approved runtime modules are enabled with exact boolean values and no unknown modules;
- private cutover state and runtime backup exist outside the deployment with mode `0600`;
- cutover state and runtime markers use the same build and fingerprints;
- `awaiting_release` remains protected by maintenance, financial read-only and the exact JSON write block;
- `completed` has maintenance released and no JSON write block.

The inspector is read-only. It does not connect to MySQL, write files, mutate configuration or change application entrypoints.

## Coordinator boundary

`ProductionPrimaryRuntimeCoordinator` exposes the versioned future API and webhook contract, but execution is deliberately disabled:

- `EXECUTION_ENABLED = false`;
- `prepareEntrypointPlan()` returns only a read-only plan after the activation contract passes;
- `executeApiRequest()` and `executeWebhookMutation()` fail closed;
- `bot/api.php`, `bot/webhook.php`, `WebhookHandler.php`, `StorageFactory.php` and bootstrap are unchanged by this sub-MVP.

## What this does not authorize

This foundation does not authorize:

- merging the release candidate into `main`;
- deploying production;
- changing the private production config;
- starting the JSON-to-DB cutover;
- publishing DB routing;
- running API or webhook mutations against DB-primary storage;
- releasing maintenance.

The next sub-MVP must independently implement and test the actual API/webhook entrypoint wiring. A later controlled cutover still requires a fresh explicit production approval.
