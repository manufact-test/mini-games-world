# MVP-14R.2.6 — latency report and product acceptance

## Purpose

This package closes the final evidence gap in MVP-14R.2. Parts 2.1–2.5 froze behavior. Part 2.6 measures the isolated fixture runners and prepares the product-owner review.

The resulting timing is **не является production latency**. It is reproducible local/CI evidence for the baseline implementation and a future relational parity harness.

## Frozen index

The committed scenario index contains:

- one foundation contract fixture that is not timed;
- 4 account/passive scenarios;
- 6 invite/matchmaking scenarios;
- 8 game scenarios;
- 8 economy/supporting scenarios.

Total: 27 indexed entries, 26 timed scenarios.

Each timed entry freezes:

- fixture ID;
- scenario ID;
- group;
- allowed runner class;
- expected behavior fingerprint.

## Cold and warm definitions

Cold sample:

- load the fixture from disk;
- construct the allowed runner;
- execute one complete deterministic scenario;
- verify the frozen result fingerprint.

Warm sample:

- keep the same loaded fixture and runner;
- perform one unmeasured warmup;
- reset deterministic ID sequences;
- execute and verify repeated samples.

The report stores count, min, median, p95, max and mean. Percentiles use nearest-rank.

## Guardrails

The CI limits are intentionally broad catastrophic-regression guards:

- per-scenario cold max: 2000 ms;
- per-scenario warm p95: 500 ms;
- aggregate warm p95: 500 ms.

They detect hangs or severe regressions. They are not a user-facing SLO and must not be used to claim production performance.

## Execution

```bash
bash ops/checks/mvp14r2-latency-acceptance-local.sh
```

Standalone report:

```bash
php ops/checks/mvp14r2-latency-report.php \
  --cold=5 \
  --warm=30 \
  --output=/safe/local/path/mvp14r2-latency-report.json
```

## Safety boundary

The package has no network client, production credential, database connection, Telegram call, payment-provider call, deploy step or runtime switch.

Production measurement, load testing and deployment require отдельного разрешения пользователя.

## Product acceptance

Automated timing cannot prove perceived Mini App responsiveness. After CI succeeds, the product owner follows `MVP-14R-2-PRODUCT-ACCEPTANCE-CHECKLIST.md` and reports only deviations.

MVP-14R.2 closes only after:

- all 26 timed scenarios are present;
- cold/warm report is valid;
- behavior fingerprints remain exact;
- full repository CI passes;
- production remains unchanged;
- product-owner acceptance is confirmed;
- master roadmap is updated.
