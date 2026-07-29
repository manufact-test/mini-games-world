# MVP-14R.2 — JSON behavior and latency baseline

## Status

MVP-14R.2 is in progress. This package begins **MVP-14R.2.1 — Baseline foundation**.

The authoritative historical behavior source is commit:

`4295f42c84d28b02eae25fb9aa069ed186bde5ac`

The latest functional commit beneath it is `c1f51e1188af12a18bd72a94cc289429f7d4960a`; the two commits differ by zero files. Historical code is used only as the behavior reference. Historical user data is never installed over current data.

## Foundation contract

MVP-14R.2.1 adds test-only building blocks:

- strict deterministic fixture loading from `bot/tests/fixtures/mvp14r2`;
- fixed UTC clock and deterministic ID sequences;
- explicit aliases plus deterministic generated aliases for approved volatile fields;
- canonical JSON with stable object-key ordering;
- SHA-256 scenario fingerprints;
- a frozen scenario-result schema covering input, public result, before/after state, side effects, retry, conflict and latency metadata;
- a focused local check proving the package is isolated.

Aliases may replace only paths explicitly listed in a fixture. Balance, bet, status, game state, winner, turn owner, unread count and event type remain exact business data.

## Safety boundary

This is code and CI only, with no production runtime change. The baseline classes:

- are not loaded by `bot/core/bootstrap.php`;
- do not use production configuration or secrets;
- do not open database connections;
- do not call Telegram, payment or other network services;
- do not write repository fixtures;
- do not change live JSON, webhook registration or Cron.

Production remains on the accepted DB-primary runtime. Deploy, merge and issue changes require separate authorization.

## Focused verification

Run:

```bash
bash ops/checks/mvp14r2-json-baseline-local.sh
```

Expected first marker:

```text
MGW_MVP14R2_BASELINE_FOUNDATION=PASSED
```

The complete repository CI still discovers every `bot/tests/*Test.php` file and validates every tracked PHP, shell, JSON and JavaScript file.

## Remaining MVP-14R.2 sequence

1. **MVP-14R.2.2:** account/bootstrap/session/presence, notifications and passive reads.
2. **MVP-14R.2.3:** direct/link/rematch invitations, retries, matchmaking and bot fallback.
3. **MVP-14R.2.4:** deterministic lifecycle coverage for all eight games.
4. **MVP-14R.2.5:** economy, history, shop, payment fixtures and weekly bonus.
5. **MVP-14R.2.6:** cold/warm latency samples, aggregate report and product-owner acceptance.

MVP-14R.3 cannot begin until the complete JSON behavior baseline, all eight games, latency report and product-owner acceptance are finished.
