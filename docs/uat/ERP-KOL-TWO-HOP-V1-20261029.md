# ERP KOL Two-Hop V1 — R1–R4 UAT & release gate (2026-10-29)

## Scope
- R1: KOL detail /kol/library/{kol} shows AffiliateWP admin account action and Copy URL from authoritative kol_affiliate_accounts.affiliate_id and the configured beyourlover.com connection. Never substitute wordpress_user_id. Hide when missing.
- Existing authorized Chrome history confirmed the edit URL shape: /wp-admin/admin.php?page=affiliate-wp-affiliates&affiliate_id=656&action=edit_affiliate
- R2: KOL order /kol/orders/{order} links to its accessible ERP KOL master record only if explicit orders.kol_id exists, or exactly one formal collaboration links the order. New staff-created KOL order requires an accessible KOL selection. Collaboration fulfillment persists explicit orders.kol_id.
- R3: /kol/orders/link-preview is a read-only KOL manager dashboard and JSON audit. Never infer KOL identity from name, email, address, SKU, or order number. No auto linking or historical backfill.
- R4: Automated tests, Dev UAT, separate Production release gate.

## Read-only Production baseline
- 888 KOL orders, 0 formal collaborations, 962 ERP KOL master records, 857 ERP KOLs with production AffiliateWP mappings, and 933 AffiliateWP account entries without ERP KOL linkage.
- No Production changes were made within this work package.

## Data and permission guardrails
- Nullable indexed orders.kol_id foreign key, ON DELETE RESTRICT, no historical migration backfill.
- kol.view staff creating a KOL order must select a visible KOL, scoped to the owner unless kol.views.manage. Legacy orders.create-only clients remain backward compatible without an ID, recorded as unlinked, never auto-assigned.
- KOL detail inherits existing kol.view owner scope. Historical review requires orders.view and kol.views.manage. Customer orders never show KOL detail link.
- WP action opens on www in a new tab with noopener noreferrer; cannot bypass WP authentication.

## UAT
1. Mapped KOL shows AffiliateWP account link and copy action with correct affiliate_id.
2. Unmapped KOL hides both buttons.
3. Staff new order requires KOL selection and rejects an out-of-scope ID.
4. Linked KOL order goes to correct KOL detail. Unlinked historical KOL order has no action.
5. Historical preview shows totals, disallowed auto-link, and recent 20 pending examples.
6. Customer order and existing Woo edit links remain unchanged.
7. MySQL migration and rollback checks; Production DDL only after separate Owner approval.

## Deployment gate
- Draft PR until Owner visual UAT; no Production change or migration authorized by R1–R4 Dev implementation.
- Production will require pre-migration DB backup, separate release approval, exact SHA deployment, and order/readback verification.
