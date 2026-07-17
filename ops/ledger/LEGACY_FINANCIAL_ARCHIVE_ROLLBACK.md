# Rollback for the legacy financial archive schema

Migration `20260717_0006_create_legacy_financial_archive` is expand-only and inactive.

Before any archive import, rollback is simply deploying the previous application release; the unused tables may remain in place.

After an archive import, do not delete or rewrite archive rows. A retry must use a clean test database or a restored database backup. Production import is not allowed in this step.
