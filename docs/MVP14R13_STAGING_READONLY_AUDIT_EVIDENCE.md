# MVP-14R13.1 — live public evidence

## Probe attempt

- Pull request: `#270`
- Workflow: `Mini Games World CI #1328`
- Workflow run ID: `30757439694`
- Exact tested branch head: `930e15ed69322e1e3bc2025ac461c12fd1d3dcfe`
- GitHub pull-request merge ref tested by the runner: `9cdcfe45a164d36bef5dabbb09695a86e339e02f`
- Runner region: `eastus`
- Probe started: `2026-08-02T16:51:05Z`
- Request policy: unauthenticated public `GET` only; no cookies, tokens, request bodies or mutations.

## Result

All repository checks before the public probe passed:

- PHP syntax: `815 files`;
- PHP smoke tests: `345 files`;
- shell syntax: `44 files`;
- JSON validation: `30 files`;
- JavaScript/import/query-version validation: `201 files`;
- secret/private-file scan: `1284 tracked files`.

The runner then attempted nine public HTTPS reads:

### Staging

1. `/bot/health.php`
2. `/app/`
3. `/app/v110.php?v=1123`
4. `/app/runtime/api.php?action=health`
5. `/app/runtime/index.php`

### Production

1. `/bot/health.php`
2. `/app/v110.php?v=1123`
3. `/app/runtime/api.php?action=health`
4. `/app/runtime/index.php`

Every request failed before an HTTP response with curl error `28`:

```text
Failed to connect to <host> port 443 after approximately 10 seconds:
Timeout was reached
```

Exact aggregate result:

```text
audit_result: failed (9 network failures)
```

## Interpretation

This result does **not** prove that staging or production was unavailable to real
users. Production had just been used successfully through Telegram by the
product owner. It proves that the GitHub-hosted runner used by CI could not
establish an HTTPS connection to either Hostinger project from that network
path.

Because both independent Hostinger project hosts failed identically before an
HTTP response, the audit cannot extract live build, environment, storage or
redirect markers from GitHub-hosted Actions.

Possible causes include Hostinger/CDN/firewall/datacenter filtering or another
network-path restriction. The evidence does not distinguish between them, so no
specific cause is asserted.

## Consequence for R13

The original plan to run Playwright directly from the existing GitHub-hosted
runner is currently blocked. Before browser E2E can become mandatory, R13 must
provide one of these verified routes:

1. allow/whitelist the GitHub-hosted runner path to the staging host;
2. use an approved alternate hosted browser runner that can reach staging;
3. use an ephemeral self-hosted runner in an environment with staging access.

A persistent runner must not be installed in production shared hosting. Secrets
must remain in protected CI/environment storage and may not be committed.

## Next evidence gate

R13.2 may proceed with code-level parity, fail-closed environment guards and
staging configuration contracts. Before any mutating staging test or Player A/B
browser session, the product owner/manual infrastructure gate must confirm:

- exact Hostinger staging deployment branch/commit;
- staging public URL reachability from the selected E2E runner;
- BotFather Mini App/Web App URL;
- staging webhook URL;
- staging Cron schedule/target;
- staging environment, storage and database isolation summaries.

No production or staging source, config, data, webhook or Cron setting was
changed by this probe.
