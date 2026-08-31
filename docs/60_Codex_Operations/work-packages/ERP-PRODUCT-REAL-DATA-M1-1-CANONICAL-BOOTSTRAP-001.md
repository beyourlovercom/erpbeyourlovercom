---
id: ERP-PRODUCT-REAL-DATA-M1-1-CANONICAL-BOOTSTRAP-001
title: Product Real Data M1.1 — Canonical Product Bootstrap
status: GIT_DELIVERED / PR_137_OPEN / MERGE_NOT_AUTHORIZED
branch: feature/erp-product-real-data-m1-1-canonical-bootstrap-001
target_base: main@1f60a7815c51b2b5ce9de6991e43f4dd52267cab
original_base: main@9496144f825301c110c4ae688b353cee5b4f868b
execution_provider: US_CODEX_CLI
implementation_authorization: OWNER_GRANTED_2026-08-31
owner_uat: PASS_2026-09-01
git_delivery: PR_137_OPEN
merge: NOT_AUTHORIZED
production_deployment: NOT_AUTHORIZED
---

# Goal

Turn the existing Product OS from an empty canonical Product table into a real WooCommerce-backed Product Master without creating duplicate product truth or weakening SKU authority.

This package implements the first controlled real-data bootstrap only. It does not add Product media sync, SKU aliases, logistics-weight evidence, WordPress Product Connector endpoints, or automatic external synchronization.

# M1.0 read-only audit evidence

Source audited: Windows Local WordPress clone `localdev.beyourlover.com`.

- Woo product/variation records: 317.
- Products: 249; variations: 68.
- Simple: 227; variable parents: 22; variations: 68.
- SKU-bearing records: 243; missing SKU: 74.
- Duplicate SKU groups: 0.
- Current SKU-bearing composition: 221 simple + 22 variable parent; all 68 variations currently have no independent SKU.
- Published SKU-bearing records: 229; non-published SKU-bearing records: 14.
- Woo weight coverage on SKU-bearing records: 25 present / 218 missing.
- Current canonical ERP local Product count observed during M1.0 audit: 0.
# Canonical identity contract

`products.id` remains the permanent ERP canonical Product ID.

`products.sku` remains the operational Product/SKU authority used by Inventory, Orders, KOL and Fulfillment.

`woocommerce_product_id` is an external Woo identity, not the ERP primary key.

A Woo row may create/update a canonical Product only when it has a nonblank SKU.

Current-data semantics:
- simple + SKU -> canonical Product candidate;
- variable parent + SKU -> canonical Product candidate;
- variation without independent SKU -> not a canonical Product;
- simple without SKU -> reconciliation item, never auto-create;
- a future variation with its own unique nonblank SKU may be a canonical Product candidate because it represents an independently addressable operational SKU.

No fuzzy SKU matching is allowed.
No product-family normalization may replace the stored canonical SKU.
No missing SKU may be fabricated.

# Woo status semantics

WooCommerce CSV `Published` is interpreted using Woo's exporter contract:
- `1` -> published -> `is_active=true`;
- `0` -> private -> `is_active=false`;
- `-1` or any non-published fallback -> `is_active=false`.

Non-published SKU-bearing products remain in Product Master so historical Order, Inventory, KOL and Logistics references are not broken.
# Import authority / preservation rules

Woo-owned/bootstrap fields:
- `woocommerce_product_id`;
- `source_type=woocommerce`;
- `product_type`;
- `sku` subject to conflict checks;
- `gtin` when the export supplies it;
- `name`;
- `sale_price`;
- `regular_price`;
- `is_active` from `Published`.

Reference enrichment allowed only when the ERP field is currently null:
- `weight_kg` from Woo `Weight (kg)`.

M1.1 MUST NOT overwrite these ERP-owned operational fields:
- `shipping_weight_kg`;
- `length_cm`, `width_cm`, `height_cm` unless a later authority contract explicitly changes this;
- `country_of_origin`, `hs_code`;
- `standard_production_cost`, `standard_packaging_cost`, `standard_purchase_cost`;
- `cost_currency`, `cost_note`, `notes`, `unit`.

M1.1 never deletes Product records merely because a row is absent from an import file.
# Preview-first import contract

Real-data bootstrap requires Preview before mutation.

Preview must report at minimum:
- total source rows;
- canonical candidates;
- new / update / unchanged candidates;
- active / inactive candidates;
- skipped no-SKU variation rows;
- no-SKU non-variation reconciliation rows;
- invalid rows;
- duplicate SKU conflicts;
- Woo-ID/SKU identity conflicts.

Confirm is blocked when a duplicate/conflicting canonical identity would violate Product truth.

Conflict examples that must not auto-merge:
- existing manual Product has the same SKU but no Woo identity;
- same SKU is already owned by a different Woo Product ID;
- same Woo Product ID resolves to a different existing Product whose SKU conflicts;
- duplicate nonblank SKU appears more than once in canonical candidate rows.

Repeated import of the same unchanged source must be idempotent and report unchanged/skipped rather than create duplicates.
# Database / migration impact

M1.1 uses the existing `products` table and existing Product module boundaries.

- New migration: NO.
- Schema change: NO.
- Product deletion: NO automatic deletion.
- Production database mutation: NOT AUTHORIZED.
- Real import is first validated against an isolated/local UAT database.

# Required implementation surfaces

Expected bounded surfaces:
- `ProductService` import parsing/normalization;
- Product import result/preview DTO as needed;
- Product import controller flow;
- `/products/import` UI for Preview -> Confirm;
- focused Product import tests.

Do not redesign Product Create/Edit V2.
Do not change Product list/menu unless required only to expose truthful preview/result status.
Do not touch WordPress Connector active worktree.

# Acceptance gates

1. Existing Product CRUD and Product Edit V2 tests remain green.
2. Standard Woo CSV with SKU-bearing simple/variable rows produces canonical Product candidates.
3. No-SKU variations never create Product rows.
4. No-SKU non-variation rows surface as reconciliation, not silent loss.
5. Non-published SKU-bearing rows import as inactive, not discarded.
6. Preview performs zero Product mutation.
7. Confirm imports only conflict-free canonical candidates.
8. Repeat confirm/import is idempotent: no duplicate Product or Woo identity.
9. Woo import never overwrites ERP shipping weight, dimensions, customs or cost fields.
10. Existing Order inbound Product lookup remains compatible.
11. Existing Inventory Product/SKU matching remains compatible.
12. Existing KOL Product relations remain compatible.
13. Focused Product backend tests PASS.
14. Product frontend tests PASS.
15. Typecheck and production build PASS.
16. Architecture regression against exact main is zero.
17. `git diff --check` PASS and `public/hot` absent.
18. Owner UAT explicitly PASS before Git delivery.

Current real-source audit baseline suggests approximately 243 canonical candidates on the audited Local clone. This is evidence, not a hardcoded production count.

# Explicit out of scope

- SKU Alias persistence/resolver — Product Real Data M1.2.
- Logistics weight evidence/import — Product Real Data M1.3.
- Woo variation external-reference mapping — Product Real Data M1.4.
- Product360 closure — later Product OS package.
- Product description/category/featured-image/gallery sync — later WordPress Product/Media sync.
- WordPress Connector endpoint changes.
- Inventory quantity mutation.
- Order mutation or historical snapshot rewrite.
- Automatic Product family normalization.
- Production deployment, production import, tag or release.
# Execution / authorization state

- Implementation provider: US Codex CLI; provider exited before GPT bounded UAT remediation/review.
- Implementation: COMPLETE.
- Owner UAT: PASS on 2026-09-01.
- Git delivery: OWNER AUTHORIZED on 2026-09-01.
- Merge: NOT AUTHORIZED.
- Production import/deployment/tag/release: NOT AUTHORIZED.
- Branch was rebased before delivery from original base `9496144f825301c110c4ae688b353cee5b4f868b` onto latest `origin/main@1f60a7815c51b2b5ce9de6991e43f4dd52267cab`.

# Owner UAT final receipt

- Real source: Windows Local `localdev.beyourlover.com`; source was read only and exported into a temporary UAT CSV.
- Source evidence: 317 rows; 243 SKU-bearing rows; 68 no-SKU variations; 14 non-published SKU-bearing rows.
- Preview: 242 canonical candidates; 229 active; 13 inactive; 68 no-SKU variations skipped; 6 no-SKU non-variations surfaced for reconciliation; one invalid row (`RJ238A`, Woo ID `34246`, blank Name); zero duplicate-SKU and identity conflicts.
- Preview performed zero Product mutation.
- First isolated Confirm: 242 inserted; 229 active / 13 inactive; `RJ238A` remained absent.
- Repeat Preview: 0 new / 0 update / 242 unchanged. Repeat Confirm kept Product count at 242. Idempotency PASS.
- Standard UTF-8 BOM Woo CSV compatibility was fixed after real UAT exposed the header edge case; regression coverage added.
- Owner-requested template download is served by authenticated backend route with forced filename `product-import-csv-template.csv`, UTF-8 BOM, and the standard 9-column Woo bootstrap contract. Owner retest PASS.
- UAT runtime, temporary CSV, isolated SQLite database and dedicated browser session were cleaned after PASS.

# Final delivery verification

Against rebased target `origin/main@1f60a7815c51b2b5ce9de6991e43f4dd52267cab`:

- Product/Order/Woo/Inventory/KOL compatibility: 108 tests / 445 assertions PASS.
- Product Import frontend: 6/6 PASS.
- Full Products frontend: 59 PASS / 2 existing Product Edit V2 danger-zone failures. `Edit.vue` and `Edit.test.ts` are byte-identical to latest `origin/main` and are not touched by M1.1.
- Typecheck: PASS.
- Production build: PASS.
- `git diff --check`: PASS.
- `public/hot`: ABSENT.
- Migration/schema diff: NONE.
- Current Architecture: 1 APP-001 baseline failure / 1 risky / 6 pass / 477 assertions.
- Exact latest-main Architecture using an isolated physical vendor copy: the same 1 APP-001 baseline failure / 1 risky / 6 pass / 477 assertions. M1.1 Architecture regression = 0.
- New Product template route is registered in the Architecture compatibility contract; M1.1 introduces no COMPAT-001 route drift.

# Product Real Data roadmap

```text
M1.0 Read-only Woo audit + SKU reconciliation        COMPLETE
M1.1 Canonical Product Bootstrap                     OWNER_UAT_PASS / GIT_DELIVERY_AUTHORIZED
M1.2 SKU Alias + Reconciliation Authority            WAITING
M1.3 Logistics Weight Enrichment / Evidence          WAITING
M1.4 Woo Product/Variation External References       WAITING
Product360 Closure                                   LATER
WordPress Product + Media Inbound Sync               LATER
```

Support S-M1 remains deliberately deferred because it is not required for the current Tencent Docs replacement data gate.

# Remaining gates

Git delivery may commit, push and open the PR under the Owner authorization recorded above.
Merge remains separately gated and MUST NOT occur without explicit Owner authorization.
Production import/deployment/tag/release remain separately gated and are NOT AUTHORIZED.
# Git delivery receipt — 2026-09-01

- Business commit: `b11d2259e319d5e1bd4d20d689f1d9167152b6a8` — `feat(products): bootstrap canonical Woo product import`.
- Branch pushed: `feature/erp-product-real-data-m1-1-canonical-bootstrap-001`.
- Pull request: `#137` — `Product Real Data M1.1 — Canonical Product Bootstrap`.
- Target: `main`.
- Git delivery: COMPLETE.
- Merge: NOT AUTHORIZED.
- Production import/deployment/tag/release: NOT AUTHORIZED.
