# ERP-PRODUCT-REAL-DATA-M1-4-WOO-EXTERNAL-REFERENCES-001

Status: IMPLEMENTATION_AUTHORIZED / IN_PROGRESS
Owner stage: Product Real Data M1.4
Frozen base: `main@ef5b62d6ce1c082ecccf2ffc4e30680a2af3d731`
Branch: `feature/erp-product-real-data-m1-4-woo-external-references-001`
Worktree: `/Users/a/AI/007/worktrees/erp-product-real-data-m1-4-woo-external-references-001`

## Goal

Establish provider-scoped WooCommerce Product/Variation external-reference authority without creating a second Product truth.

`products.id` remains the permanent canonical ERP Product ID.
`products.sku` remains the operational SKU authority used by Inventory, Orders, KOL and Fulfillment.
Woo Product IDs and Variation IDs are external identities only.

M1.4 closes the current gap where Woo order lines prefer `variation_id`, while Product Master currently stores only one global `woocommerce_product_id`.

## Canonical identity rule

The target identity path is:

`Woo connection + Product/Variation ID -> product_external_references -> products.id`

No external ID becomes an ERP primary key.
No variation without an independent operational SKU becomes a second Product row.
## Data model

Create `product_external_references` with:

- `id`
- `product_id` FK -> `products.id`
- `provider` string, M1.4 writes `woocommerce`
- `connection_key` string, stable logical Woo connection identity
- `reference` string, remote Product or Variation ID
- `reference_type`: `product | variation`
- timestamps

Uniqueness authority:

`UNIQUE(provider, connection_key, reference)`

A canonical Product may have multiple external references on one connection.
The same numeric Woo ID may exist on different connection keys without collision.
One remote reference on one connection may never point to two canonical Products.

Product deletion may cascade its external-reference mappings; historical Order item snapshots remain governed by the existing Order contract.

## Existing `products.woocommerce_product_id`

The existing column remains for backward compatibility during M1.4.
It is no longer sufficient as the provider-scoped inbound identity authority.
Do not create a second global Woo-ID column and do not change `products.id` or canonical SKU semantics.
## Bootstrap / mapping rules

Woo CSV Preview remains mandatory before mutation.
M1.4 consumes the standard Woo `Parent` column when variations are present.
Woo `Parent` may identify the parent by canonical SKU or `id:<Woo Product ID>`.

Rules:

- simple + nonblank SKU -> canonical Product candidate + `product` external reference.
- variable parent + nonblank SKU -> canonical Product candidate + `product` external reference.
- variation + unique nonblank SKU -> independently addressable canonical Product candidate + `variation` external reference.
- variation + blank SKU -> never create a Product; map its Variation ID to the proven parent canonical Product.
- blank-SKU variation with unresolved/missing Parent -> unresolved; no Product/reference mutation.
- Parent exact canonical SKU may resolve directly.
- Parent exact SKU Alias may resolve through the existing M1.2 authority.
- Parent `id:<ID>` may resolve only through a proven Woo product reference / compatible legacy Woo Product ID.

Forbidden:

- fuzzy SKU matching;
- product-family inference;
- variation-name inference;
- attribute-based parent guessing;
- copying a sibling mapping;
- auto-creating Product or Alias from an unresolved variation.

Preview itself is zero-mutation.
Confirm creates/updates only conflict-free external-reference mappings and canonical candidates already permitted by M1.1.
## Woo inbound Order resolution

Woo order normalization must preserve the active `connection_key` and whether the strong remote ID came from a Product or Variation.

Resolution order:

1. exact `product_external_references` match for `provider=woocommerce`, active `connection_key`, remote reference;
2. existing canonical SKU / SKU Alias resolver;
3. unresolved SKU observation under the existing M1.2 reconciliation contract.

A Variation external reference may resolve directly to its parent canonical Product when the variation has no independent SKU.
The persisted Order item `product_id` and `sku` must always use the resolved canonical Product truth.
Do not persist the variation's blank/raw SKU as canonical Product truth.

M1.4 must prevent cross-connection false matches when two Woo sites reuse the same numeric remote ID.
Historical Order rows are never rewritten by adding or changing Product external references.

## Legacy compatibility

Existing `products.woocommerce_product_id` lookup behavior may remain only as a bounded compatibility path where it cannot create cross-connection ambiguity.
The final inbound authority for new M1.4 evidence is `product_external_references`.

Existing M1.1 Product import, M1.2 SKU Alias, M1.3 Weight Evidence, Inventory, KOL and Fulfillment behavior must remain compatible.

## UI scope

Product Edit may show provider external references read-only as identity/provenance information.
M1.4 does not redesign Product Edit or Product360.
No manual external-reference mutation UI is required unless needed to resolve a frozen acceptance case.
## Acceptance gates

1. Existing Woo Product ID is not treated as ERP primary key.
2. One canonical Product can hold one Woo product ref plus multiple Woo variation refs.
3. Same remote reference on different connection keys may map independently.
4. Same provider + connection + reference cannot map to two Products.
5. No-SKU variation with Parent SKU maps to the proven canonical parent Product.
6. No-SKU variation with `Parent=id:<ID>` maps to the proven canonical parent Product.
7. No-SKU variation with missing/unresolved Parent creates no Product/ref truth.
8. SKU-bearing variation remains eligible for its own canonical Product and gets a `variation` ref.
9. CSV Preview reports mapping/new/update/unresolved/conflict statistics with zero mutation.
10. Confirm is idempotent and blocks identity conflicts.
11. Woo order Product ID resolves through the active connection external reference.
12. Woo order Variation ID resolves through the active connection external reference.
13. Variation ref mapped to parent yields parent canonical `product_id` and SKU in Order item.
14. Same numeric remote ID on two Woo connections never cross-resolves.
15. Unknown remote ID may fall through to exact SKU/Alias authority; no fuzzy resolution.
16. Product Edit external references are truthful/read-only if exposed.
17. Existing Product/Order/Woo/Inventory/KOL/Logistics focused regressions remain green relative to exact base.
18. Typecheck, production build and focused frontend tests pass.
19. Migration `up -> down -> up` is isolated and reversible.
20. Architecture regression against exact frozen/latest main is zero.
21. `git diff --check` passes and no runtime artifacts are tracked.
22. Owner UAT explicitly PASS before Git delivery.

## Owner UAT minimum scenarios

Use an isolated local UAT database and real Fortify auth.
1. Open a canonical Product with its Woo `product` external reference visible.
2. Preview CSV with parent + multiple blank-SKU variations; verify zero mutation and valid mappings.
3. Confirm; verify one Product owns parent Product ref plus all proven Variation refs.
4. Re-run the same confirm/import; verify idempotency and no duplicate refs.
5. Preview a variation whose Parent is an exact canonical SKU; verify correct parent resolution.
6. Preview a variation whose Parent is `id:<Woo Product ID>`; verify correct parent resolution.
7. Preview an unresolved Parent; verify no Product/ref creation.
8. Use two connection keys with the same numeric Woo ID; verify independent mappings.
9. Import/order-resolve a line with parent Product ID; verify canonical Product/sku.
10. Import/order-resolve a line with no-SKU Variation ID mapped to parent; verify canonical Product/sku.
11. Unknown remote ID + known SKU Alias resolves only through Alias authority and preserves canonical SKU.
12. Verify historical Order item snapshots remain unchanged after mapping changes.

## Explicit out of scope

- Product360 layout/closure — Product M1.5.
- WordPress Product/Media API inbound sync — Product M1.6/later connector package.
- Description, categories, featured image, gallery or attribute-content sync.
- Inventory quantity mutation.
- Historical Order rewrite.
- Automatic Product family normalization.
- Variation attribute inference.
- Woo product creation/update outbound writes.
- Production deployment/import/migration/tag/release.

## Authorization

Owner instruction `下一步，M1.4 — Woo Product / Variation External References` starts M1.4 implementation.
Authorized: worktree/branch setup, WP freeze, implementation, local verification and Owner UAT bootstrap/support.
Not authorized: git commit, push, PR, merge, deploy, Production migration, Production data mutation, release or tag.
