# MVP-14R3.0 / MVP-14R3.1 audit summary

## Confirmed rollback target

`3b1550d7d9e4464f00ad3d390fa808b3970979e4` has the same file tree as the previously accepted `d77fef20ab93b94377c1bf440f8b22f73d410206`.

It is retained as an emergency rollback target, not as the target architecture.

## Why immediate rollback is not selected

The accepted tree already contains the split launch contract:

- `/start` and Telegram menu select `/app/v110.php?v=110`;
- shared invite generation can select `/app/?v=85&invite=...`.

Rolling back would restore known notification and post-leave bugs without repairing the shared invite route.

## Current decision

- freeze the existing production runtime;
- do not add more fixes to the versioned graph;
- build `app/runtime` as an isolated staging runtime;
- do not connect the clean core to the legacy API;
- add the clean server bootstrap and one staging adapter in the next package;
- require two-account E2E before any production cutover.
