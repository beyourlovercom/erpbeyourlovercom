# ERP to WooCommerce Admin Order Link: R1–R4
Date: 2026-10-29
Scope: ERP customer order detail only; no order, shipment, or WordPress writes.

## R1 verified source and safety conditions
- Import normalizer persists Woo REST payload id as the string reference.
- Authoritative mapping: order_external_references with provider woocommerce and matching connection_key.
- ERP ID, display order ID, and Woo order number are NOT reliable URL identifiers.
- Only one Woo reference accepted; malformed IDs, wrong connection keys, multiple references, manual and archived records are rejected.
- WordPress target pinned to https://www.beyourlover.com and configured Woo base URL must match approved canonical host.
- No live database query against production order 1574 has been confirmed yet.

## R2 changes
- WooCommerceAdminOrderLink generates a read-only URL.
- Orders/Show displays a secondary action when a valid URL is returned.
- Opens new tab with noopener noreferrer; ERP page remains open.
- Config: WOO_ADMIN_ORDER_EDITOR_MODE=disabled (default). Enabled: hpos or legacy.
- Only enable after confirming the real www Woo editor mode; unknown config disables link.
- No database migration or Woo API write is added.

## R3 automated evidence
- Vue Show component suite: 71 passed.
- New PHP feature suite: 4 passed, 108 assertions.
- Existing OrderFulfillmentM1 feature suite: 17 passed, 209 assertions.
- Vite production build succeeded.

## R4 remaining acceptance gate
1. Read-only verify ERP order 1574 and actual provider, connection_key and numeric reference.
2. Read-only verify www (not Dev) uses HPOS or legacy Woo editor.
3. In approved UAT environment enable confirmed mode and verify target href uses reference (not 1574).
4. Validate linked Woo order, manual order and wrong/missing reference; confirm WordPress login and new-tab behavior.
5. Merge/main and production deployment require separate Owner pass; unconfigured environments fail closed.
