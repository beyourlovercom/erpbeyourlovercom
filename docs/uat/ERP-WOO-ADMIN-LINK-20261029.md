# ERP to WooCommerce Admin Order Link: R1–R4
Date: 2026-10-29
Scope: ERP customer order detail only; no order, shipment, or WordPress writes.

## R1 verified source and safety conditions
- Import normalizer persists Woo REST payload id as the string reference.
- Authoritative mapping: order_external_references with provider woocommerce and matching connection_key.
- ERP ID, display order ID, and Woo order number are NOT reliable URL identifiers.
- Only one Woo reference accepted; malformed IDs, wrong connection keys, multiple references, manual and archived records are rejected.
- WordPress target pinned to https://beyourlover.com and configured Woo base URL must match approved canonical host.
- No live database query against production order 1574 has been confirmed yet.

## R2 changes
- WooCommerceAdminOrderLink generates a read-only URL.
- Orders/Show displays a secondary action when a valid URL is returned.
- Opens new tab with noopener noreferrer; ERP page remains open.
- Config: WOO_ADMIN_ORDER_EDITOR_MODE=hpos (default, user-provided production screenshot confirms HPOS order editor). Legacy remains an explicit override; disabled remains an explicit off switch.
- Screenshot confirms production order 41906 opens at https://beyourlover.com/wp-admin/admin.php?page=wc-orders&action=edit&id=41906. Unknown config disables link.
- No database migration or Woo API write is added.

## R3 automated evidence
- Vue Show component suite: 71 passed.
- New PHP feature suite: 4 passed, 108 assertions.
- Existing OrderFulfillmentM1 feature suite: 17 passed, 209 assertions.
- Vite production build succeeded.

## R4 remaining acceptance gate
1. Read-only verify ERP order 1574 and actual provider, connection_key and numeric reference.
2. HPOS path confirmed by owner screenshot for Woo order 41906; check admin permission and target site redirect in browser UAT.
3. In approved UAT environment verify the HPOS href uses persisted provider reference (not ERP ID 1574 or the external display number 28518-MF414A).
4. Validate linked Woo order, manual order and wrong/missing reference; confirm WordPress login and new-tab behavior.
5. Merge/main and production deployment require separate Owner pass; unconfigured environments fail closed.

## 2026-10-29 read-only evidence update
- China Mac authenticated ERP page /customers/orders/1574 reported source_type=woocommerce, erp_order_id=007-0918-c28518, external_order_number=28518-MF414A. That display number cannot safely be equated with the numeric Woo ID.
- Production WooCommerce screenshot shows Order #41906 at the HPOS URL with id=41906. This proves the editor route format and that specific ID, not every order ID.
- Actual order 1574 provider reference remains unverified; do not infer its value from either displayed number. No production change or real-order mutation has been performed.
