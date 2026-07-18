# Admin webhook storage contract fix

The admin payment callback now accepts `StorageTransactionInterface` instead of the legacy concrete `JsonDatabase` type. This keeps the webhook compatible with `JsonStorageAdapter` returned by `StorageFactory::createJson()`.

Covered methods:

- `setPendingPaymentReject()`
- `cancelPendingPaymentReject()`
- `processPaymentDecision()`

The change does not alter payment business rules or exactly-once protection.
