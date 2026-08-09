# Mini Games World — Android Shell Foundation

Status: isolated parallel workstream.

## Ownership boundary

This branch owns only:

```text
android-app/**
```

No file outside this directory may be changed by the Android Shell Foundation workstream.

## Parent snapshot at fork

- Repository: `manufact-test/mini-games-world`
- Base branch: `main`
- Exact base SHA: `e11bb4909d549c1c5262de6eaf18338388e7bcdb`
- Android branch: `agent/android-shell-foundation`
- Parent staging observed at A0: `881116d6b43c75e4e72e123711dd94706ba65ce5`
- Parent staging work remains independent and may continue moving.

## Forbidden ownership

This Android workstream must not modify or become an owner for:

- Mini Games World backend/API/storage/database;
- Telegram Mini App frontend/runtime;
- Phase B or Phase C runtime;
- authentication or provider-neutral identity;
- economy/ledger/settlement;
- matchmaking, bots, invites, rematches or game lifecycle;
- shared GitHub Actions workflows;
- staging or production deployment configuration.

If a future Android task appears to require a change outside `android-app/**`, stop that task and record it as a future integration dependency instead of changing the parent project.

## Integration status

```text
COMPLETED FOUNDATION WORK != INTEGRATED PRODUCT WORK
```

The branch must remain isolated and must not be merged into staging or main by this workstream. A future explicit integration gate will reconcile this foundation with the then-current global MGW architecture.
