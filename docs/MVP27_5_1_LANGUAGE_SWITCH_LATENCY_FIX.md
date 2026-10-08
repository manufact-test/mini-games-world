# MVP-27.5.1 — Instant RU/EN switching (user-reported latency corrective)

User manual acceptance MVP-27.5: Android and linked Telegram Mini App share
canonical account language but tapping RU/EN locks both controls for 3–4 s
waiting for the full Profile V2 network response, looking frozen.

## Corrective

1. Close the language sheet and dispatch a visual-only locale change in the
   click turn, without waiting for the server and without writing the old
   device-local override or Telegram preference prematurely.
2. Save authenticated canonical account preference in the background through
   /bot/profile-v2.php. Serial queue prevents older slow responses from winning
   after a later RU/EN click. A stale first-boot profile read cannot overwrite
   the selection.
3. New explicitly opted-in single-locale-field response skips expensive,
   unrelated inventory/rating/history/JSON snapshots, **only after** the
   original authentication, ownership, moderation and locale validation.
   All other profile-v2 requests retain their accepted response shape.
4. If saving fails, restore the last confirmed profile language and show the
   network error; ignore failures from outdated selections.
5. Native Android OS app-language remains unchanged. No new APK required.

## Verification

Automated contract tests for immediate UI switch, modal dismissal, async
failure rollback, no disabled buttons, queue ownership, fast path guards, and
existing language/profile regression. Hostinger staging E2E after merge.
Manual gate: linked Android + Telegram, switch both directions and confirm the
whole interface responds instantly; reload to check persistence. Force network
error if feasible and confirm rollback.
