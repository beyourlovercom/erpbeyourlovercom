---
id: ERP-PRODUCT-REAL-DATA-M1-3-LOGISTICS-WEIGHT-EVIDENCE-001
title: Product Real Data M1.3 — Logistics Weight Enrichment / Evidence
status: IMPLEMENTATION_AUTHORIZED / IN_PROGRESS
branch: feature/erp-product-real-data-m1-3-logistics-weight-evidence-001
target_base: main@4f2bc7fb51030e7acc231173d1b2d66bd28eca79
execution_provider: US_CLAUDE_CODE
implementation_authorization: OWNER_GRANTED_2026-09-01
owner_uat: REQUIRED
git_delivery: NOT_AUTHORIZED
merge: NOT_AUTHORIZED
production_deployment: NOT_AUTHORIZED
---

# Goal

Add truthful provenance and controlled review/adoption for Product weight data without creating a second Product truth or implementing downstream shipping calculations.

M1.3 enriches the existing Product-owned weight fields with evidence. It does not add a third weight field.

# Existing authority

`products.weight_kg` = product/net/reference weight.

`products.shipping_weight_kg` = Product-level shipping reference weight.

Neither field represents one Shipment's actual package weight. Shipment truth remains owned by Logistics.

Existing dimensions remain `length_cm`, `width_cm`, `height_cm`. M1.3 does not change their authority.

M1.1 real-source audit observed 243 SKU-bearing rows, with Woo weight present for 25 and missing for 218. That is evidence of poor source coverage, not a reason to infer missing values.

# Truth rules

1. Evidence never mutates Product weight on creation.
2. Only an explicit adoption action may update `weight_kg` or `shipping_weight_kg`.
3. No fuzzy SKU matching, family inference, proportional estimation, AI estimation or sibling-SKU copying.
4. No evidence may auto-create Product or Alias.
5. Alias resolution may identify the canonical Product, but Alias text never becomes Product truth.
6. Historical evidence never rewrites historical Order, Inventory, KOL or Shipment snapshots.
7. Existing non-null Product weights with no evidence pointer remain valid legacy/unattributed current values; M1.3 must not fabricate provenance for them.
8. New weight mutations after M1.3 must preserve provenance.

# Evidence sources

Supported source types:
- `woocommerce_reference` — provider/reference weight from Woo;
- `manual_entry` — operator-entered Product value without a stronger measurement claim;
- `manual_measurement` — directly measured by an operator;
- `supplier_document` — supplier/factory documented value;
- `warehouse_measurement` — warehouse measured reference;
- `historical_logistics` — documented logistics evidence, never automatically adopted;
- `other_documented` — explicit documented source with required note/reference.

# Data model — `product_weight_evidences`

Add one Product-owned evidence table. Required fields:
- `id`;
- `product_id` FK -> products, cascade on Product deletion;
- `weight_kind` string: `product_weight | shipping_reference_weight`;
- `observed_weight_kg` decimal(10,4), strictly greater than zero;
- `raw_sku` varchar(255), nullable, preserving imported/source SKU text;
- `source_type` varchar(32);
- `source_reference` varchar(255), nullable;
- `source_note` text, nullable;
- `observed_at` datetime, nullable;
- `status` string: `pending | adopted | rejected`;
- `created_by_user_id` nullable FK -> users;
- `reviewed_by_user_id` nullable FK -> users;
- `reviewed_at` datetime, nullable;
- `rejection_note` text, nullable;
- timestamps.

Evidence source facts are immutable after creation. Correction is reject + create new evidence, not silent editing.

Add provenance pointers to `products`:
- `weight_evidence_id` nullable FK -> product_weight_evidences;
- `shipping_weight_evidence_id` nullable FK -> product_weight_evidences.

These pointers explain the current Product value; they are not a second weight truth.

# Adoption contract

Adopting `product_weight` evidence:
- updates only `products.weight_kg`;
- sets `products.weight_evidence_id` to the adopted evidence;
- never changes `shipping_weight_kg`.

Adopting `shipping_reference_weight` evidence:
- updates only `products.shipping_weight_kg`;
- sets `products.shipping_weight_evidence_id` to the adopted evidence;
- never changes `weight_kg`.

Adoption is transactional and requires explicit confirmation when replacing a non-null current value.

A previously adopted evidence row remains historical evidence if a later evidence becomes current. Current provenance is determined by the Product pointer, not by rewriting old evidence.

Rejecting evidence performs zero Product mutation.

# Existing Product mutation compatibility

After M1.3, Product Create/Edit must not bypass provenance:
- a new or changed weight submitted through Product UI creates and adopts `manual_entry` evidence in the same transaction;
- unchanged weight creates no evidence;
- clearing an existing weight is explicit, audited, and clears the corresponding evidence pointer;
- existing legacy/unattributed values are not retroactively assigned fabricated evidence.

Future M1.1-style Woo fill-null behavior must create/adopt `woocommerce_reference` evidence when it newly fills `weight_kg`; Woo must still never silently overwrite `shipping_weight_kg`.

# Batch evidence import

M1.3 must provide Preview -> Confirm for practical enrichment of missing weights.

Accepted CSV contract:
- `SKU`;
- `Weight (kg)`;
- `Weight Kind` (`product_weight` or `shipping_reference_weight`);
- `Source Type`;
- optional `Source Reference`;
- optional `Observed At`;
- optional `Note`.

SKU resolution must use the M1.2 canonical Product resolver. Canonical SKU and Alias may resolve to one Product; `raw_sku` preserves the source value.

Preview performs zero Product/evidence mutation and reports:
- source rows;
- valid resolved evidence rows;
- unmatched SKU rows;
- invalid/zero/negative weight rows;
- duplicate rows within the file;
- rows that would replace a non-null current Product value;
- rows that would fill a null value;
- rows that create evidence only without adoption.

Confirm creates evidence only. Batch import does not automatically adopt or overwrite Product weight.

Unknown SKU blocks that row and never fabricates Product/Alias. Identity correction occurs through the existing M1.2 Alias/Reconciliation workflow, then the file is previewed again.

# Product OS workspace

Add `/products/weight-evidence` as the Product-owned review workspace.

Minimum UI:
- coverage summary for `weight_kg` and `shipping_weight_kg`;
- evidence list with Product/SKU, kind, observed kg, source, reference, observed time, status;
- filters for status, source type, kind, Product/SKU;
- add one evidence manually;
- Preview/Confirm CSV evidence import;
- adopt evidence with explicit before/after confirmation;
- reject evidence with note;
- clear indication of whether an evidence row is the current provenance pointer.

Product Edit keeps its existing two writable weight fields, but must show current provenance state: evidence source when linked, otherwise `Legacy / unattributed` for an existing value without a pointer.

# Permissions

Read workspace/evidence: `products.view`.

Create/import/adopt/reject/clear weight: `products.operate`.

View-only users may see evidence and provenance but may not mutate any evidence or Product weight.

# Audit contract

Stable actions:
- `product.weight_evidence.created`;
- `product.weight_evidence.adopted`;
- `product.weight_evidence.rejected`;
- `product.weight.cleared`.

Adoption/clear Audit must include Product ID, canonical SKU, target field, before value, after value, evidence ID and source type where applicable.

# Migration / risk

M1.3 is a high-risk schema gate because it adds provenance persistence.

Expected migration:
- create `product_weight_evidences`;
- add two nullable evidence FK pointers to `products`;
- no rewrite/backfill of existing Product weight values;
- no Product deletion;
- rollback removes only M1.3 pointers/table and does not change existing `weight_kg`, `shipping_weight_kg`, dimensions, Orders, Inventory, KOL or Shipments.

No production migration without separate explicit authorization.

# Explicit out of scope

- New third Product weight field.
- Automatic weight estimation from related/similar SKU.
- AI/fuzzy weight inference.
- Product-family normalization.
- Automatic adoption of imported evidence.
- Dimensional/volumetric weight calculation.
- Chargeable-weight calculation.
- Carrier/service volumetric divisor configuration.
- Shipping rate lookup or cost estimator.
- Express-vs-ocean decision engine.
- Actual Shipment package-weight truth.
- Warehouse/WMS API connector changes.
- Production data import/migration/deployment/tag/release.

# Acceptance gates

1. Existing `weight_kg` / `shipping_weight_kg` semantics remain unchanged.
2. Evidence creation performs zero Product weight mutation.
3. Adopting product-weight evidence changes only `weight_kg` and its provenance pointer.
4. Adopting shipping-reference evidence changes only `shipping_weight_kg` and its provenance pointer.
5. Replacing a non-null value requires explicit confirmation and Audit before/after evidence.
6. Rejecting evidence performs zero Product mutation.
7. Previously adopted evidence remains historical after a later adoption.
8. Existing non-null legacy values remain unchanged and display as unattributed until new evidence is explicitly adopted.
9. Product Create/Edit weight changes create provenance rather than bypassing evidence.
10. Woo fill-null reference weight creates provenance and never overwrites shipping reference weight.
11. Batch Preview performs zero evidence/Product mutation.
12. Batch Confirm creates evidence only; it does not auto-adopt.
13. Canonical and Alias SKU inputs resolve to the same canonical Product while raw source SKU is preserved.
14. Unknown SKU never creates Product/Alias or weight truth.
15. Zero and negative weights are rejected.
16. `products.view` can read; `products.operate` is required for every mutation.
17. Evidence/adoption/rejection/clear actions write stable Audit evidence.
18. Migration up/down/up is isolated and leaves unrelated schema intact.
19. Product/M1.2/Order/Inventory/KOL/Logistics compatibility regressions PASS.
20. Frontend tests, typecheck, production build and `git diff --check` PASS.
21. Architecture regression against exact target main = 0.
22. Owner UAT explicitly PASS before Git delivery.

# Owner UAT plan

Use isolated US-local UAT database only.

Required scenarios:
1. Open a Product with no weights and verify both current values/provenance states are truthful.
2. Create pending `product_weight` evidence; verify Product value remains unchanged.
3. Adopt it; verify only `weight_kg` changes and current provenance points to that evidence.
4. Create a second product-weight evidence and adopt it over the non-null value with explicit confirmation; verify before/after Audit and first evidence remains historical.
5. Create and adopt `shipping_reference_weight` evidence; verify only `shipping_weight_kg` changes.
6. Reject another evidence; verify zero Product mutation.
7. Edit a weight through Product Edit; verify `manual_entry` provenance is created/adopted automatically.
8. Preview a CSV containing canonical SKU, Alias SKU, unknown SKU and invalid weight; verify preview statistics and zero mutation.
9. Confirm valid CSV evidence; verify evidence rows are created but Product weights are not auto-adopted.
10. Verify Alias row resolves to canonical Product and preserves `raw_sku`.
11. Verify unknown SKU creates no Product/Alias/weight truth.
12. Verify view-only user can inspect evidence but every mutation returns 403.

Owner must explicitly PASS before Git delivery.

# Product OS roadmap

```text
M1.0 Read-only Woo audit + SKU reconciliation        COMPLETE
M1.1 Canonical Product Bootstrap                     CLOSED / MERGED
M1.2 SKU Alias + Reconciliation Authority            CLOSED / MERGED
M1.3 Logistics Weight Enrichment / Evidence          SCOPE_FROZEN / NEXT
M1.4 Woo Product / Variation External References     WAITING
M1.5 Product360 Detail Workspace                     VISUAL_BASELINE_APPROVED / WAITING
M1.6 WordPress Product + Media Inbound Sync          LATER
```

# Delivery governance

`ONE_WORK_PACKAGE / ONE_BRANCH / ONE_WORKTREE / ONE_PR`.

Implementation provider is US Claude Code CLI because M1.3 introduces Product-owned schema/provenance and modifies Product import/edit mutation paths.

Current authorization allows scope formalization only.

Until Owner separately authorizes implementation, do not:
- implement business code;
- run schema mutation outside disposable verification data;
- commit;
- push;
- open/update PR;
- merge;
- deploy;
- run Production migration/import;
- tag/release.

Implementation must stop at verified local build/UAT preparation and request Owner UAT before Git delivery.
