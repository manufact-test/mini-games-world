# MVP-14R13.1 — READ-ONLY STAGING AUDIT

Status: **COMPLETE WITH EXTERNAL CONNECTIVITY BLOCKER**  
Audit branch: `agent/mvp14r13-staging-readonly-audit`  
Production changes: **none**  
Staging changes: **none**

## 1. Exact repository state

| Item | Exact value |
|---|---|
| Accepted production branch | `main` |
| Accepted production commit | `591139b71d3042646f725b9f34f3638124faa578` |
| Last accepted production PR | `#269` |
| Historical staging branch | `agent/mvp-13-2-staging` |
| Historical staging branch head | `6e6bbcf7da3bfd5e517695e150d45f451a94b9e0` |
| Git relation | staging head is the merge base; `main` is 1270 commits ahead and 0 behind |
| Divergence | none |
| Safe Git operation available | non-forced fast-forward from staging head to an R13 integration commit based on current `main` |

The last repository evidence that names the deployed staging revision also names
`6e6bbcf7da3bfd5e517695e150d45f451a94b9e0`. The public audit attempted to
compare live Hostinger markers with that recorded revision, but the GitHub-hosted
runner could not establish an HTTPS connection to either staging or production.
Therefore `6e6bbcf7...` remains the documented last-known staging deployment, not
an independently verified live deployment.

Exact connectivity evidence is stored in
`docs/MVP14R13_STAGING_READONLY_AUDIT_EVIDENCE.md`.

## 2. Current topology map

### Production

```text
GitHub main
  -> manual Hostinger Redeploy
  -> lemonchiffon-gerbil-545102.hostingersite.com
  -> canonical Telegram route from WebAppLaunchUrl
  -> /app/v110.php?v=1123
  -> no-store v110 entry
  -> main-v110.js?v=1124
  -> current production application owners
  -> /bot/*.php canonical API entrypoints
  -> external private config + production storage/database
```

### Historical staging

```text
GitHub agent/mvp-13-2-staging @ 6e6bbcf7...
  -> historical manual Hostinger Redeploy
  -> seashell-okapi-889488.hostingersite.com
  -> private config base_url chooses the staging host
  -> UserWelcomeGuard builds /app/?v=85
  -> old application graph
  -> old /bot/*.php graph
  -> separate external staging config/data/database expected
```

The historical staging branch does **not** contain `app/runtime/index.php` or the
later clean runtime package.

### Clean runtime currently present in main

```text
/app/runtime/index.php
  -> isolated HTML/JS clean runtime
/app/runtime/api.php?action=health
  -> RuntimeConfig::fromEnvironment()
  -> JsonFileRuntimeStore
  -> default _private_mgw/runtime_staging/runtime-state-v3.json
  -> browser/Telegram staging identity
  -> session + presence + Tic Tac Toe only
```

This clean runtime is not a parity copy of the current product. Its own README
states that invitations, notifications and seven games are not connected.

## 3. Launch and Telegram routing

### Historical staging branch

`bot/helpers/UserWelcomeGuard.php` constructs the Web App URL from the private
`base_url` and appends `/app/?v=85`. Invite opens append `&invite=<token>`.
Therefore the staging bot/host relationship is external-config driven, while the
client graph is an old v85 graph.

### Current main

Current main uses the shared `WebAppLaunchUrl` canonical v110 route. The public
outer URL remains `/app/v110.php?v=1123`, while the no-store entry publishes the
current v1124 shell.

### External settings still requiring live evidence

Repository code cannot prove the current BotFather Web App URL, registered
webhook URL, Hostinger project branch selection or Cron schedule. The public
probe also could not reach either Hostinger project from GitHub-hosted Actions.
These values must be confirmed at the first external R13.2 gate; they are not
guessed in this document.

## 4. Config, storage and data boundaries

### Canonical application private config

`bot/core/bootstrap.php` resolves configuration in this order:

1. `MGW_CONFIG_FILE`, when configured;
2. external `_private_mgw/config.php` outside the repository;
3. legacy repository-local config only as fallback.

Runtime overlay:

- sibling `runtime.php`;
- sibling `cutover-rehearsal.json` only for staging/local freeze states.

Database config:

1. `MGW_DATABASE_CONFIG_FILE`, when configured;
2. sibling private `database.php` beside the application config.

Application data directory:

1. explicit `data_dir` from private config;
2. external `mgw_data` directory;
3. repository-local `bot/data` only as fallback.

This architecture can isolate production and staging, but isolation depends on
the two Hostinger projects having different external files, database credentials
and data directories. Repository history proves the intended boundary; live
private-file identity cannot be read through GitHub and must be proven through
safe summaries/health output, never by exposing secrets.

### Existing canonical environment guard

`ConfigValidator` already requires staging to declare itself explicitly and
provides these protections:

- explicit staging host allowlist;
- protected production-host metadata;
- staging bot username and token isolation;
- production bot-token hash comparison;
- staging data directory may not equal production;
- staging database fingerprint may not equal production;
- live payment services are forbidden outside production;
- forced browser development users are forbidden in production.

These guards should be retained and extended, not replaced.

### Clean runtime boundary

The clean runtime defaults to `_private_mgw/runtime_staging` and never imports the
legacy production bootstrap or database bridges. This is good data isolation,
but it is not the target parity runtime.

## 5. Existing staging-only authentication audit

Current clean auth behaves as follows:

- valid Telegram initData is verified;
- missing initData in a Telegram context fails closed;
- ordinary browser access is allowed by default;
- browser account identity is derived only from a client-generated
  `installationId`;
- no arbitrary account ID query parameter is accepted.

Security/architecture conclusion:

- it is safer than accepting `?user_id=...`;
- it is **not** adequate for R13 test players because it requires no secret,
  signed token, TTL or replay protection;
- `RuntimeConfig::fromEnvironment()` hard-codes `environment: staging` and
  defaults `MGW_CLEAN_ALLOW_BROWSER_IDENTITY` to enabled;
- `app/runtime/index.php` has no production-host/environment gate.

Therefore the current clean browser identity must not become the final E2E auth.
Production must receive a fail-closed guard before protected test auth is added.

## 6. Product parity diff

The historical staging branch is not a small environment variant. Current main
is 1270 commits ahead and contains substantial production/runtime work absent
from staging, including:

- v99–v120 investigation/rollback assets;
- current v110 production shell and owners;
- current invitation, notification, presence and search lifecycle code;
- DB-primary production routing/recovery packages;
- current tests and safety workflows;
- the later isolated clean runtime.

A manual cherry-pick or selective copy is rejected. The only safe parity base is
an R13 integration branch created from the accepted `main`.

## 7. Components to keep

- current canonical v110 application runtime from accepted `main`;
- canonical `/bot/*.php` API contracts and game rules;
- current DB-primary/storage safety architecture;
- existing `ConfigValidator` isolation rules;
- external private-config pattern;
- existing staging Hostinger project;
- existing staging Telegram Mini App/bot;
- historical staging branch as an immutable rollback source;
- existing CI and DB safety tests;
- clean runtime tests as historical/reference evidence until explicit removal.

## 8. Components not to use as the target runtime

- historical staging `/app/?v=85` graph;
- historical staging code as a source for new product changes;
- browser identity based only on random installation ID;
- clean runtime as the main parity application;
- any staging-to-production data synchronization;
- any shared production/staging config, DB, JSON directory or bot token;
- destructive E2E against production.

The clean runtime may remain physically present during R13 only behind a
fail-closed environment/host guard. It must not be the user-facing staging route.

## 9. Exact synchronization plan for R13.2

1. Create immutable rollback ref from staging head
   `6e6bbcf7da3bfd5e517695e150d45f451a94b9e0`.
2. Create an R13 integration branch from accepted `main`
   `591139b71d3042646f725b9f34f3638124faa578`.
3. Add fail-closed environment guards before any test authentication:
   - explicit staging environment assertion;
   - explicit permitted staging host/base URL assertion;
   - production rejection contract;
   - disable or tombstone unguarded clean browser auth.
4. Add safe staging build/environment projection without secrets.
5. Validate staging private config expectations through public safe summaries:
   - environment = staging;
   - base URL = staging host;
   - separate storage identity/fingerprint;
   - separate DB identity fingerprint;
   - no production identity match.
6. Do not copy production private files or data.
7. Run full CI and all DB staging safety workflows on the exact integration head.
8. Fast-forward `agent/mvp-13-2-staging` to the exact accepted integration head;
   do not force-update before the rollback ref exists.
9. Product owner performs one manual Hostinger staging Redeploy.
10. Verify exact build ID, health, storage/DB isolation and staging Telegram route
    through a reachable manual or alternate-runner path.
11. Confirm a browser/E2E runner can reach staging before creating Player A/B.
12. Run non-destructive smoke before protected test authentication is enabled.

## 10. Rollback plan

Code rollback:

```text
R13 staging deployment fails
  -> do not modify main
  -> redeploy immutable rollback ref based on 6e6bbcf7...
  -> restore historical staging bot/WebApp route if it was changed
  -> verify old health/build marker
```

Data rollback:

- staging data is never replaced by production data;
- take a staging-only private backup before schema/config changes;
- any R13 test data uses a separate namespace/run ID;
- rollback restores only staging data/config;
- no production export/import is part of R13.

Production rollback is out of scope because R13.1/R13.2 do not deploy production.

## 11. Webhook and Cron audit boundary

Repository entrypoints exist for Telegram webhook handling and Weekly Match Cron.
Their current external registration/schedule cannot be proven from GitHub code.
R13.2 must verify in the staging Hostinger/BotFather configuration that:

- staging bot webhook targets only the staging host;
- staging Cron targets only the staging project and private config;
- production webhook/Cron remain unchanged;
- staging jobs cannot load production config/database credentials.

No webhook registration or Cron schedule is changed during R13.1.

## 12. Public live probe result

Audit script:

`scripts/audit/mvp14r13-staging-public-probe.sh`

The script performs unauthenticated GET requests only. It sends no cookie, auth
header, token, request body or mutating request.

On CI run `#1328`, all normal repository checks passed. The runner then attempted
nine public reads across both Hostinger projects. Every request timed out before
an HTTP response while connecting to port 443.

Therefore:

```text
LIVE BUILD MARKERS: NOT CAPTURED
LIVE ENVIRONMENT SUMMARY: NOT CAPTURED
LIVE STORAGE/DB SUMMARY: NOT CAPTURED
GITHUB-HOSTED RUNNER REACHABILITY: BLOCKED
```

The failure is recorded as evidence, not interpreted as proof that the sites are
down. The next version of the probe reports a complete two-host connectivity
block as `external_network_blocked` without invalidating the repository suite.
A partial network failure still fails CI.

This changes the R13 execution topology: browser E2E cannot be declared ready on
the existing GitHub-hosted runner until reachability is restored or an approved
alternate/ephemeral runner is used.

## 13. Final R13.1 DoD state

- [x] staging branch/head known;
- [x] production/staging Git relationship known;
- [x] historical staging launch code known;
- [x] canonical/current launch code known;
- [x] code-level storage/config boundaries mapped;
- [x] historical clean runtime presence/absence mapped;
- [x] existing staging browser auth audited;
- [x] exact synchronization plan written;
- [x] exact rollback plan written;
- [x] public Hostinger probe executed and exact connectivity failure recorded;
- [x] inability of the current GitHub-hosted runner to reach staging documented;
- [x] external BotFather/Hostinger webhook, WebApp URL and Cron verification
      assigned to the first manual/alternate-runner gate of R13.2;
- [ ] live Hostinger build/environment/storage markers — blocked externally and
      intentionally carried into R13.2 before any mutating staging test.

R13.1 is complete as a read-only audit. It found a real infrastructure blocker
instead of silently assuming that the future Playwright runner could reach
staging. R13.2 may begin with code-level parity and fail-closed guards, but no
Player A/B browser E2E may be accepted until a reachable runner path is proven.
