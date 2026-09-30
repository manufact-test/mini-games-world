# MVP-26.1 — Android foundation integration

Parent: exact manually accepted MVP-25 staging `60f855e4f10ed53afc03f433e056cd6e157ee88b`.

## Decision

Do **not** merge any historical Android branch.

The historical branches diverged from a much older parent and are thousands of commits behind current staging. MVP-26 starts by transplanting only their isolated Android technical assets onto the current accepted product checkpoint.

## Scope

- add cleaned `android-app/**`;
- preserve the hardened three-owner Java shell;
- preserve accepted Android branding resources;
- remove stale historical Android checkpoint docs from the integrated snapshot;
- establish an MVP-26-specific debug package/version;
- add one PR CI build/test/lint gate.

## Non-goals

No runtime product behavior, authentication, payments, provider integrations, games, economy, tournaments, DB, production or Cron changes.

## Exit gate

MVP-26.1 is eligible to merge only when:
1. static foundation contract passes;
2. unit tests pass;
3. Android lint passes;
4. debug APK assembles;
5. tracked-secret scan remains clean;
6. PR diff stays inside `android-app/**` plus the dedicated MVP-26.1 workflow.

This APK is not yet the final authenticated Android product.
