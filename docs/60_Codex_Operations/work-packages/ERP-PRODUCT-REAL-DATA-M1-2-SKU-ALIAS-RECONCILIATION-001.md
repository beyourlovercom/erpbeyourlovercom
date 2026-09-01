---
id: ERP-PRODUCT-REAL-DATA-M1-2-SKU-ALIAS-RECONCILIATION-001
title: Product Real Data M1.2 — SKU Alias + Reconciliation Authority
status: OWNER_UAT_PASS / GIT_DELIVERY_AUTHORIZED
branch: feature/erp-product-real-data-m1-2-sku-alias-reconciliation-001
target_base: main@62dc05d8fc8ae89376e8df8ccf4227c60a1cce43
execution_provider: US_CLAUDE_CODE
implementation_authorization: OWNER_GRANTED_2026-09-01
owner_uat: PASS_2026-09-01
git_delivery: OWNER_GRANTED_2026-09-01
recovery_rebase: PASS_2026-09-01
rebase_base: main@6ac92fdd0e6e4f852cf1fd93225abd6bd143b933
merge: NOT_AUTHORIZED
production_deployment: NOT_AUTHORIZED
---

# Goal

Establish one Product-owned SKU identity authority so external/legacy SKU spellings can resolve to the existing canonical `products.id + products.sku` without creating parallel Product truth.

M1.2 converts the existing `/products/skus` and `/products/sku-aliases` placeholder navigation into real Product OS workspaces, adds a controlled Alias registry and a centralized unmatched-SKU reconciliation queue, and makes Inventory/Woo Order fallback resolution consume the same resolver.

Canonical SKU values are not rewritten automatically. Fuzzy matching and product-family normalization remain forbidden.
# Current baseline facts

- M1.1 Canonical Product Bootstrap is merged.
- `products.sku` is nullable + unique and remains the operational SKU authority.
- Product repository currently supports only exact canonical `findBySku()`.
- Inventory batch sync trims SKU only and resolves exact `products.sku`; unmatched rows are errors.
- Inventory already preserves per-row `raw_sku`, `sku`, `product_id` and validation evidence.
- Woo inbound Order resolution uses Woo Product ID first, then exact SKU fallback; unresolved rows retain raw SKU with `product_id=null`.
- KOL formal collaboration enforces `product_id + canonical SKU` equality and must remain canonical-only.
- `/products/skus` and `/products/sku-aliases` exist in navigation but are Shell placeholders using `prototype.view`; no Product-owned route/model/service exists yet.

# Canonical identity contract

`products.id` = permanent ERP Product identity.

`products.sku` = canonical operational SKU.

Aliases may resolve inbound/external SKU strings to a canonical Product, but an Alias is never stored as the canonical SKU of Inventory, Order or KOL business records after successful resolution.

Resolver precedence:
1. Strong external identity when the caller already has one (for example Woo Product ID).
2. Canonical Product SKU.
3. Product SKU Alias.
4. Unmatched -> reconciliation observation.
# Match-key rules

M1.2 introduces one deterministic SKU match-key function used by Alias persistence, collision checks and resolver lookup.

The match key:
- trims leading/trailing whitespace;
- is case-insensitive via UTF-8 uppercase/lowercase normalization;
- preserves internal whitespace;
- preserves punctuation, hyphens, suffixes and prefixes;
- does not remove color/size words;
- does not convert variant SKU to product-family code;
- returns null for blank input.

Examples:
- ` VM467A ` and `vm467a` share one match key.
- `VM467A DARK GREEN` is NOT normalized to `VM467A` unless Owner explicitly creates that Alias.
- `VX610A` is NOT automatically normalized to `VX610`.

No fuzzy/contains/AI similarity match may create or mutate an Alias automatically.
# Data model — `product_sku_aliases`

Add a Product-owned alias table. Proposed fields:
- `id`;
- `product_id` -> products, cascade on Product deletion;
- `alias_sku` varchar(128), preserved display value;
- `match_key` varchar(128), globally unique;
- `source_type` varchar(32), default `manual`;
- `source_reference` varchar(255), nullable;
- `notes` text, nullable;
- `created_by_user_id` nullable -> users, null on user deletion;
- timestamps.

Integrity rules:
- one match key may belong to only one canonical Product;
- an Alias may not collide with any canonical Product SKU match key;
- a canonical SKU create/update may not collide with an existing Alias match key;
- an Alias equal to its own Product canonical SKU is redundant and rejected;
- correcting a wrong Alias uses explicit delete + recreate, never silent reassignment;
- Alias create/delete is audited.
# Data model — `product_sku_reconciliations`

This table stores unresolved SKU observations only; it is not Product truth.

Proposed fields:
- `id`;
- `match_key` varchar(128), unique;
- `raw_sku` varchar(255), representative latest display value;
- `product_name_hint` varchar(512), nullable;
- `occurrence_count` unsigned integer, default 1;
- `source_counts` JSON, nullable, e.g. `{inventory_batch:3,woocommerce_order:1}`;
- `last_source_type` varchar(32), nullable;
- `last_source_reference` varchar(255), nullable;
- `status` varchar(16): `open | resolved | ignored`;
- `resolved_product_id` nullable -> products;
- `resolved_alias_id` nullable -> product_sku_aliases;
- `resolution_note` text, nullable;
- `resolved_by_user_id` nullable -> users;
- `first_seen_at`, `last_seen_at`, `resolved_at`, timestamps.

Repeated unmatched observations with the same match key increment/update the same queue item instead of creating duplicates.
# Resolution workflow

`SkuIdentityResolver` is Product Application authority and returns a typed result:
- `canonical` -> Product matched by canonical SKU;
- `alias` -> Alias matched, with canonical Product + canonical SKU;
- `unmatched` -> no Product identity found.

On an unmatched result, callers may provide lightweight source context. M1.2 records or updates one reconciliation item using stable source keys such as `inventory_batch` and `woocommerce_order`.
Owner reconciliation action:
1. Open the unresolved SKU.
2. Search and select one existing canonical Product.
3. Confirm match + Alias creation.
4. In one transaction create the Alias and mark reconciliation `resolved`.
5. Future imports resolve automatically to the canonical Product.

Owner may also mark an item `ignored` and later `reopen` it. Ignoring never creates an Alias or Product.

Resolution must never modify historical Order, Inventory or KOL snapshots retroactively. The new Alias applies to future resolution only.
# Inventory integration

Inventory Batch Sync must stop calling canonical `findBySku()` directly and consume the Product SKU resolver.

For each incoming row:
- preserve incoming trimmed SKU in `raw_sku`;
- on canonical or Alias match, persist canonical Product ID and canonical Product SKU in normalized `sku`;
- on unmatched, keep `product_id=null`, retain the existing validation error, and record/update a Product reconciliation observation;
- Preview/Confirm remains the authority for inventory mutation; Alias resolution does not bypass any batch gate.

Duplicate/unique SKU statistics must be based on the resolved canonical Product/SKU where a Product is known, so canonical SKU + Alias rows referring to the same Product are recognized as one operational identity.

Existing Confirm behavior that aggregates valid rows by `product_id` remains unchanged.
# Woo Order integration

Woo inbound keeps its existing strong-identity precedence:
1. Woo Product ID lookup first;
2. Product SKU resolver only when strong identity did not resolve;
3. unmatched remains `product_id=null` and retains the incoming SKU snapshot.

When an Alias resolves an inbound line, the Order item stores the canonical Product ID and canonical SKU. M1.2 does not add a second raw-SKU column to `order_items`.

Unmatched Woo SKU fallback records/updates reconciliation context using the Woo order/reference identity available to OrderService.

# KOL compatibility

Formal KOL Collaboration remains canonical-only:
- UI/product search may find a Product through Alias search if useful;
- saved collaboration rows must still contain canonical `product_id`, canonical `sku_snapshot` and Product name snapshot;
- request payload validation must not accept an Alias as an alternative persistent SKU truth;
- existing `product_id + SKU` consistency gates remain in force.
# Product OS workspace

M1.2 makes both existing Product navigation entries real.

`/products/skus` (`products.skus`):
- canonical SKU registry;
- search by canonical SKU, Product name or Alias;
- columns: canonical SKU, Product name, status, source, Alias count;
- all links/actions resolve back to canonical Product;
- read permission: `products.view`.

`/products/sku-aliases` (`products.sku-aliases`):
- Tab 1: Alias registry;
- Tab 2: Reconciliation queue;
- Alias registry supports add + delete with explicit confirmation;
- Reconciliation supports resolve-to-existing-Product, ignore and reopen;
- mutation permission: `products.operate`.

Navigation permissions for the two existing menu items move from `prototype.view` to `products.view`.
# Audit contract

M1.2 records stable audit actions for identity mutations:
- `product.sku_alias.created`;
- `product.sku_alias.deleted`;
- `product.sku_reconciliation.resolved`;
- `product.sku_reconciliation.ignored`;
- `product.sku_reconciliation.reopened`.

Audit metadata may include Product ID, canonical SKU, Alias display value, source type and reconciliation ID. It must not contain full imported files or unrelated request payloads.

# Migration / risk classification

M1.2 requires new database schema and therefore is a high-risk migration gate.

Expected migration scope:
- create `product_sku_aliases`;
- create `product_sku_reconciliations`;
- no destructive Product migration;
- no rewrite/backfill of `products.sku`;
- no production migration execution without a separate explicit authorization.

Migration rollback must remove only the two new M1.2 tables and must not touch `products`, Orders, Inventory or KOL data.
# Acceptance gates

1. Canonical `products.id + products.sku` authority is unchanged.
2. Alias match-key behavior is deterministic across SQLite UAT and primary database semantics.
3. Duplicate Alias match keys are rejected.
4. Alias cannot collide with any canonical Product SKU match key.
5. Product create/update cannot introduce a canonical SKU that collides with an Alias.
6. Resolver precedence is external identity -> canonical SKU -> Alias -> unmatched.
7. Resolver never fuzzy-matches or auto-creates Product/Alias.
8. Inventory canonical SKU input behaves exactly as before.
9. Inventory Alias input resolves to canonical Product ID/SKU while preserving incoming SKU evidence.
10. Inventory unresolved SKU remains blocked and appears in the reconciliation queue.
11. Inventory canonical+Alias rows for one Product share one canonical identity for preview statistics/aggregation.
12. Woo inbound canonical SKU behavior remains compatible.
13. Woo inbound Alias fallback resolves to canonical Product ID/SKU.
14. Woo inbound unresolved SKU remains `product_id=null` and produces reconciliation evidence.
15. KOL formal collaboration stores canonical Product identity only.
16. Alias/reconciliation mutation requires `products.operate`; read requires `products.view`.
17. Both Product SKU navigation entries are real Product pages, not Shell placeholders.
18. Alias/reconciliation mutations write Audit evidence.
19. Migration up/down tests PASS with no unrelated schema drift.
20. Product + Inventory + Woo Order + KOL focused regressions PASS.
21. Product frontend tests, typecheck, production build and `git diff --check` PASS.
22. Architecture regression against exact target main = 0.
23. Owner UAT PASS before Git delivery.
# Explicit out of scope

- Product family / normalized family code.
- Automatic fuzzy/Semantic/AI SKU matching.
- Auto-creation of Products from unmatched SKU.
- Retroactive rewrite of historical Order/KOL snapshots.
- Logistics weight enrichment — M1.3.
- Woo Product/Variation external-reference mapping — M1.4.
- Product360 implementation — M1.5; its visual baseline is already Owner-approved separately.
- WordPress Product/Media inbound sync — later M1.6.
- Support OS.
- Production data import/migration/deployment/tag/release.

# Implementation provider

This package introduces two Product-owned identity tables and changes Product/Inventory/Orders resolution behavior across module boundaries. Under the current execution-provider governance, implementation is routed to `US_CLAUDE_CODE` because it is materially interdependent schema/data-model work.

Claude must implement only this frozen contract in this dedicated worktree and must stop if a new identity/business rule is required.

Git delivery, merge, production migration and deployment remain separately gated.

# Product OS roadmap

```text
M1.0 Read-only Woo audit + SKU reconciliation        COMPLETE
M1.1 Canonical Product Bootstrap                     CLOSED / MERGED
M1.2 SKU Alias + Reconciliation Authority            CURRENT / SCOPE_FROZEN
M1.3 Logistics Weight Enrichment / Evidence          WAITING
M1.4 Woo Product / Variation External References     WAITING
M1.5 Product360 Detail Workspace                     VISUAL_BASELINE_APPROVED / WAITING
M1.6 WordPress Product + Media Inbound Sync          LATER
```
# Owner UAT plan

Use isolated US-local UAT database only.

Required scenario:
1. Seed/import a canonical Product such as `VM467A`.
2. Submit Inventory Preview with external SKU `VM467A DARK GREEN` before an Alias exists -> row is unmatched and reconciliation queue receives one open item.
3. In `/products/sku-aliases`, resolve that queue item to canonical Product `VM467A`; confirm Alias creation.
4. Re-run the same Inventory Preview -> row resolves to the canonical Product ID/SKU while `raw_sku` preserves `VM467A DARK GREEN`.
5. Repeat the same input -> no duplicate Alias or reconciliation row; observation/identity behavior is idempotent.
6. Exercise Woo inbound with the Alias and verify Order item stores canonical Product ID/SKU.
7. Exercise a truly unknown SKU and verify it remains unresolved with no fabricated Product/Alias.
8. Verify KOL collaboration still rejects a payload whose persistent SKU does not equal the selected Product canonical SKU.
9. Verify view-only user cannot mutate Alias/reconciliation state.

Owner must explicitly PASS this behavior before Git delivery.
