# MVP-25.5 — Security / resilience audit

**Status:** IN PROGRESS  
**Base staging:** `67bd8a30b2e41c2da36fc7f030bbefdd15c189d2`  
**Base tree:** `139516fd937b6f6e563b13a2279e62945b5afc5a`

## Classified findings — slice 1

### REAL DEFECT — Telegram webhook ingress trusted unauthenticated JSON
`bot/webhook.php` accepted arbitrary POST bodies without Telegram's webhook
`secret_token` header. Downstream admin guards checked configured admin IDs,
but the incoming `from.id` itself was not authenticated at the HTTP boundary.

Corrective:
- one `TelegramWebhookSecurity` owner;
- verify `X-Telegram-Bot-Api-Secret-Token` before decoding/handling updates;
- protect the staging diagnostic webhook the same way;
- configure Telegram `secret_token` through every webhook routing owner;
- preserve pending updates during reconciliation.

### REAL DEFECT — setup secret travelled in URL query
`setup-webhook.php` and `check-webhook.php` accepted `setup_secret` through
`?key=...`; setup was a state-changing GET and could drop pending updates.

Corrective:
- setup becomes POST-only;
- setup/check use Authorization bearer;
- query-carried secret is removed;
- setup preserves pending updates;
- public errors do not echo private credentials.

### ROLLOUT OWNERSHIP
Canonical staging Playwright reuses the existing GitHub Actions OIDC verifier to
reconcile the staging Telegram webhook after exact deployment readiness and before
normal projection/E2E work. No repository or client secret is introduced.

## Regression-suite compatibility
Two predecessor CI guards used historical PR-wide scope rules that reject any later
cross-cutting slice touching shared webhook/fingerprint files. Their product checks stay
active; only the stale scope ownership is delegated to the current MVP-25.5 focused gate.
A separate baseline-stale exact-copy assertion was aligned to the already accepted generic
human-facing API error; runtime copy was not changed.

## Explicitly unchanged
- game engines and accepted game rules;
- economy/ledger semantics;
- tournament state machine/settlement;
- production, production DB and production Cron.

## Remaining MVP-25.5 audit
Still open after this slice:
- remaining player API authorization/ownership checks;
- server-side rate limiting and abuse boundaries;
- idempotency/replay safety of sensitive writes;
- hidden/private information exposure;
- Admin isolation beyond the already verified initData boundary;
- staging/production separation outside this webhook slice;
- public error/log sanitization;
- repository/client secret exposure audit.

MVP-25.5 is **not closed** by this slice.
