# Legacy financial status migration

The archive stores both the untouched legacy status and a normalized query status.

## Payments

- `paid`, `applied`, `completed`, `success`, `succeeded` → `completed`
- `rejected`, `declined`, `failed` → `rejected`
- `cancelled`, `canceled` → `cancelled`
- `draft`, `pending`, `waiting`, `created` → `pending`
- every other value → `unknown`

## Shop orders

- `done`, `fulfilled`, `completed`, `delivered`, `issued` → `completed`
- `rejected`, `declined`, `failed` → `rejected`
- `cancelled`, `canceled`, `refunded` → `cancelled`
- `draft`, `pending`, `processing`, `created` → `pending`
- every other value → `unknown`

## Related transactions

Current legacy categories are covered explicitly: `payment_draft`, `payment_apply`, `payment_reject`, `shop_order`, `shop_order_done`, `shop_refund` and `shop_order_reject`. When `category` is empty, the same mapping falls back to `type`.

Raw values are never overwritten. `unknown` blocks silent interpretation but does not discard the original snapshot.
