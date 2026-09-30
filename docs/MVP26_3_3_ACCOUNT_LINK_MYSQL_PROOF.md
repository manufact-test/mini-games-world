# MVP-26.3.3 — account-link MySQL full-flow proof

Base staging: `065e664e71a46e8441307767feec689de98eb86e`.

This is a proof-only slice. It changes no runtime source.

The dedicated integration test runs the complete MVP-26.3 account-link lifecycle against a clean **MySQL 8.4** database with the full current migration set:

1. create an existing Telegram-backed MGW account with 5000 MGW coins;
2. create a fresh Android device account with the canonical 1000 starter coins;
3. create a one-time link challenge;
4. claim it using the existing Telegram identity;
5. explicitly confirm from the same Telegram identity;
6. finalize from the original Android session;
7. verify the Android identity, device session and runtime owner now resolve the existing Telegram MGW account;
8. verify the Telegram target balance remains exactly 5000;
9. verify the temporary Android 1000 is retired to zero through one append-only `account_link_retirement` ledger entry;
10. verify the temporary source account becomes `linked_retired`, its active ownership locator is removed, and active JSON runtime state is retired;
11. repeat finalize and prove idempotence.

The existing SQLite 26.3.1 regression is run in the same gate.

No main / production runtime / production DB / production Cron changes.
