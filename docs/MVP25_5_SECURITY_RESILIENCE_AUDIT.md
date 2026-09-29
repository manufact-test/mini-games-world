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

## Classified findings — subsequent slices

### REAL DEFECT — repository secret scanner existed but was not enforced
The repository already contained a tracked-file secret scanner, but no inspected
staging/release workflow executed it. PR #1821 added a dedicated PR + staging-push
gate and expanded checks for webhook, staging test-auth and website-hook secrets.

### REAL DEFECT — authenticated write amplification had no server-side throttle
Support ticket creation, Support user replies and player report submission could
create durable DB/storage rows and outbound notification load without a server-side
abuse limit. PR #1822 added one `UserActionRateLimiter` owner using canonical
existing tables, bounded private overrides, HTTP 429 and `Retry-After`.

### REAL DEFECT — exact sensitive-write replay created duplicate durable objects
Even with rate limits, replaying the same authenticated request created a second
Support ticket/message/report and could repeat Telegram Support notifications.

Corrective in this slice:
- exact recent duplicate detection is server-side and requires no new client field;
- Support create/reply and player report writes serialize by authenticated user
  inside the same DB transaction before replay lookup + insert;
- MySQL uses `FOR UPDATE`; SQLite retains the same transactional contract;
- Support replay identity includes canonical attachment bytes;
- replayed Support writes suppress Telegram admin notifications;
- changed payloads remain independent writes.

### REAL DEFECT — browser bootstrap and Telegram delivery logs exposed unnecessary diagnostics
Most HTTP endpoints include `core/bootstrap.php` before their local try/catch. Browser
`display_errors` was disabled only later by `helpers/response.php`, after private config
discovery and validation. A sufficiently early failure could therefore expose PHP path/
diagnostic output if the host enabled display errors.

Telegram delivery failure logs also embedded raw admin/user chat IDs and invite recipient IDs.

Corrective:
- browser `display_errors` and `html_errors` are disabled at the first bootstrap boundary,
  before private config discovery, while CLI diagnostics remain unchanged;
- Telegram delivery logs no longer include raw chat/recipient IDs;
- bot tokens are defensively redacted from delivery exception text;
- existing staging-only raw exception diagnostics remain available only to authenticated
  staging test users.

### NO DEFECT — Web Admin authorization isolation
All inspected `bot/admin-*.php` HTTP owners authorize through `AdminWebAuth`. The owner
requires Telegram-signed initData no older than 15 minutes and then checks the verified
Telegram subject against private `admin_ids`. Admin actor references are derived from that
verified subject; the public `app/admin.php` shell contains endpoint names but no admin IDs
or private credentials and ships with no-store, no-referrer and a restrictive CSP.

### NO DEFECT — public social profile projection
The Friends `player_profile` action uses `SocialPlayerProfileReader`, not the full account
profile reader. Its public projection is limited to canonical nickname/avatar, public MGW ID,
membership date and game stats; provider usernames/display names and last-seen data are not
exposed.

## Explicitly unchanged
- game engines and accepted game rules;
- economy/ledger semantics;
- tournament state machine/settlement;
- production, production DB and production Cron.

## Remaining MVP-25.5 audit
Still open after this slice:
- finish remaining player API authorization/ownership classification;
- staging/production separation outside the completed webhook slice;
- confirm repository/client secret-exposure closure evidence.

MVP-25.5 is **not closed** by this slice.
