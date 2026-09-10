# ERP-WOO-ORDER-IDENTITY-CANONICAL-HOTFIX-001

STATUS: IMPLEMENTATION_ACTIVE
OWNER_AMENDMENT_DATE: 2026-09-10
BASELINE: origin/main@63c759dbaca9eec4dfc19b6acb7305a8411dd7fc
BRANCH: hotfix/erp-woo-order-identity-canonical-001

## Owner rule superseding the 2026-09-01 Woo customer identity rule

For newly imported WooCommerce customer orders, `erp_order_id` must be derived from the Woo business order number:

`{prefix}-{MMDD}-{Woo order number}`

Canonical example:

`Woo #40414` on `2026-09-10` with prefix `007` -> `007-0910-40414`.

Woo inbound retries preserve the existing ERP order row and identity. Manual ERP customer sequence and KOL identity rules remain unchanged in this hotfix.

## Existing-data correction gate

The hotfix provides a date-bounded dry-run command. Production correction is not authorized by code delivery alone.

Preview:
`php artisan orders:repair-woo-identities --date=2026-09-10 --json`

Apply requires separate Owner authorization and exact candidate count:
`php artisan orders:repair-woo-identities --date=2026-09-10 --apply --expect-change=N --json`

No Production apply before an explicit Owner gate.
