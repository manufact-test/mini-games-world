# MGW accounts and identities

MVP-14.3 separates the internal Mini Games World account from provider IDs while the
current product still reads and writes its gameplay profile in JSON.

## Current behavior

- a signed Telegram login resolves `(provider=telegram, provider_subject=Telegram ID)`;
- the first login creates one opaque `MGW-...` account;
- repeated and concurrent logins reuse the same identity because the database has a
  unique `(provider, provider_subject)` constraint;
- browser development identities use a separate `development` provider and can never
  merge with Telegram merely because the subject text matches;
- session and device keys are stored only as SHA-256 hashes;
- a session hash cannot move from one MGW account to another;
- Telegram name, username and signed avatar reference are metadata only and never
  participate in account matching;
- Google and Apple login UI are intentionally outside this MVP.

## Compatibility and rollback

The legacy JSON user key remains the Telegram ID until the later database cutover.
`SessionService` remains the authority for the current search/game active-session
protection. The database account mapping is additive and does not delete or rewrite
legacy JSON profiles.

Rollback is disabling/removing the private database config. Existing identity rows are
kept so a later retry resolves the same MGW IDs.
