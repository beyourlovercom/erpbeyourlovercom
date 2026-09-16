# ERP-ACCOUNTING-V11C-P7-WAREHOUSE-COST-001

## Objective
Materialize real overseas-warehouse Storage cost into Order Profit without reopening P6 carrier shipping or Historical Fulfillment reconciliation.

## Owner-approved allocation rule
`actual Storage Pool ÷ all outbound units in warehouse billing period × customer Order outbound units`.

Customer Orders materialize only their own share. KOL / historical / non-customer outbound units remain in the denominator but their share is excluded from customer profit. Finance provenance is `allocated_actual`.

## First real canary
Provider: `tianyun`.
Warehouse: canonical `de`.
Billing period: 2026-01-01 through 2026-01-31.
Currency: EUR.
Storage Pool: EUR 29.76.
All outbound: 43 rows / 48 units.
Canonical customer set: 27 Orders / 32 units.
Source-marked KOL historical set: 16 rows / 16 units.
Expected source customer share: EUR 19.84.
Expected excluded share: EUR 9.92.

## Hard boundaries
- `Shipping` stays P6 `last_mile_cost`; do not duplicate it.
- Tianyun outbound handling already sits inside P6B total carrier bill; do not materialize `overseas_handling_cost` from Tianyun in P7.
- MorePlus historical 144 tracking rows are HFR debt and are not repaired here.
- No fuzzy Order / Shipment matching.
- No Shipment creation or mutation.
- No Woo sync, Inventory stock mutation, FX rule change, Product mutation, P8/P9 work, or UI redesign.
- No Production business-data write in this WP until a separate exact Production Data Write Gate.
## Canonical source contract
Use a self-contained canonical CSV. Required columns:
- `warehouse_slug`
- `billing_period_start`
- `billing_period_end`
- `storage_pool_amount`
- `currency`
- `reference_number`
- `tracking_number`
- `outbound_units`

All rows in one source must share warehouse / period / pool / currency. `provider` is an explicit command option and participates in plan identity.

The reader must fail closed on invalid metadata, invalid positive integer units, duplicate source-row identity, unsupported currency, invalid period, or inconsistent batch metadata.

## Classification
Every valid outbound row contributes units to the denominator.

`ready`:
- exact tracking maps to exactly one canonical Shipment;
- Shipment warehouse equals source warehouse;
- Shipment Order is canonical customer and not Historical Logistics excluded.

`excluded`:
- exact Shipment maps to non-customer Order; or
- there is no canonical Shipment and source reference explicitly starts with `KOL` (denominator evidence only, never customer write).

`review`:
- missing/ambiguous tracking mapping for any non-KOL reference;
- warehouse mismatch;
- contradictory canonical identity.

Review rows remain in denominator but create no customer allocation. Never guess.
## Durable evidence
Inventory owns canonical warehouse source evidence; Finance consumes it through a contract.

Expected durable batch evidence includes:
- provider / warehouse / billing period;
- original filename / source SHA256 / parser version / plan SHA256;
- storage pool amount / currency;
- total outbound units;
- ready customer units;
- excluded units;
- review units;
- exact allocation rate;
- immutable applied timestamp / audit.

Expected row evidence includes source row number, reference, tracking, outbound units, shipment/order ids when exact, decision/reason, allocated source amount for ready customer Orders, row hash and raw/normalized data.

Applied provider+source checksum is immutable/idempotent. Same source replay is noop. Different source evidence must never overwrite a stronger or different existing Finance fact.

## Finance bridge
Add a Warehouse Storage Allocation evidence reader. Finance materializes `warehouse_cost` only from applied exact customer allocation evidence.

Candidate:
- `cost_type=warehouse_cost`
- `provenance=allocated_actual`
- `status=confirmed`
- stable source reference tied to batch + Order allocation evidence
- preserve source currency/source amount
- cross-currency uses existing Accounting FX Snapshot Bridge
- refresh Finance completeness after apply

`allocated_actual`, `actual`, and `confirmed_manual` remain strong facts. Different strong evidence blocks, never overwrites silently.
## Commands and gates
Provide a dry-run-first import command equivalent to P6B:
`inventory:import-warehouse-storage-cost {file} --provider=... [--apply] [exact guards]`

Production import apply must require process-local:
`ERP_WAREHOUSE_STORAGE_COST_IMPORT_PRODUCTION_APPLY_ENABLED=true`.

Provide Finance dry-run-first materialization command:
`finance:materialize-warehouse-cost [--apply] [exact guards]`.

Production Finance apply must require process-local:
`ERP_ACCOUNTING_WAREHOUSE_COST_PRODUCTION_APPLY_ENABLED=true`.

Exact Production guards must include source/plan/counts for import and seen/apply/noop/blocked/fx-freeze for Finance materialization.

## Continuous delta
Integrate `warehouse_cost` as a new stage in the existing Accounting continuous-delta service; do not add a scheduler. Candidate discovery must react when applied warehouse allocation evidence changes, without broad historical rescans.

## Tests
Focused tests must cover:
- exact 29.76 / 48 allocation math;
- 27 ready customer Orders / 32 units and 16 excluded KOL units in a Tianyun-like fixture;
- KOL share excluded from customer Order Profit;
- ambiguous/missing customer Shipment review, no write;
- warehouse mismatch review;
- source replay idempotency;
- strong fact protection;
- FX conversion using frozen transaction snapshot;
- continuous delta candidate trigger and materialization;
- dry-run purity and guarded apply behavior.

## Acceptance
No UI required. No Production data write. Schema/code may be prepared and validated. Stop at the first genuine Production release/data-write gate unless prior Owner authorization explicitly covers it.
## Final Work Log / merge-candidate evidence — 2026-09-16
- Provider handoff: initial US Codex CLI attempt stopped on usage limit before edits; execution then remained serialized on GPT Web + US Desktop Commander. No concurrent provider mutation occurred.
- Latest-main preflight: fetched `origin/main@1a008ca43020c5779ec56e64112c2301e6284b57`; drift from original P7 base had zero overlap with P7 target files.
- Current P7 branch rebased cleanly onto exact latest main with no conflict and no unrelated scope absorbed.
- Focused Inventory import: **5 tests / 38 assertions PASS**.
- Focused Finance warehouse-cost materialization: **4 tests / 30 assertions PASS**.
- Accounting continuous delta: **14 tests / 74 assertions PASS / 1 MySQL-only skip**.
- Affected Finance regression after rebase: **91 tests / 710 assertions PASS / 1 skip**.
- `git diff --check`: PASS.
- PHP lint for all changed/new PHP files: PASS.
- Governance suite on P7 branch: ModuleArchitecture **3 failures**, ModuleProvider PASS, AppShellNavigation **2 failures**, ApprovedMenuBaseline **3 failures**, ErpNavigation PASS.
- Exact same governance suite on exact `main@1a008ca43020c5779ec56e64112c2301e6284b57`: identical **3 / 0 / 2 / 3 / 0** failure signature; **regression delta = 0**.
- Inherited Architecture/navigation/frozen-baseline failures are therefore documented baseline debt and are not repaired inside P7.
- Scope variance: none. P7 stayed backend-only; no UI/menu/permission/route redesign; no P6/HFR repair; no second scheduler.
- Scheduler change: **0 new scheduler**; existing Accounting continuous delta is reused.
- Production business-data writes during implementation/final validation: **0**.
- Tianyun Storage source import: **NOT EXECUTED**.
- Finance `warehouse_cost` Production materialization: **NOT EXECUTED**.
- Data-write gate remains pending after the separate Production code/schema deployment gate.
- Effective-hours evidence: the pre-handoff implementation portion has no accepted elapsed/effective-time ledger in the canonical handoff, so its actual hours are **UNKNOWN WITH EVIDENCE** rather than invented. This continuation's rebase + regression + exact-main delta evidence + WP finalization consumed approximately **0.45 effective engineering hours**.
- Package total actual effective hours: **UNKNOWN WITH EVIDENCE** because the pre-handoff component cannot be reconstructed reliably from repository state alone.
- Merge-candidate status: validation complete; proceed commit → push → PR → CI → merge, then stop at the P7 Production code/schema deployment hard gate.
## Production Closure / backend-only UAT — 2026-09-16
- Final status: **P7 CLOSED / PRODUCTION ACCEPTED / backend-only UAT PASS**.
- Runtime implementation merged via PR #329; merge commit `b2808c467593caff9db080d5e968630f445a8a8c`.
- Production bounded overlay: `accounting-p7-pr329-overlay-20260915-183816-b2808c46`.
- P7 schema migration `2026_09_15_170000_create_warehouse_storage_cost_import_tables` ran as migration batch `[38]`.
- Code/schema deploy post-verify: login HTTP 200; both warehouse-storage apply flags remained unset; new evidence tables initially contained zero business rows.
- Tianyun canonical source SHA: `0a928351e7cf49d80d24a2edcbc90c27b721fe5516270c0f5c7633c35cc83593`.
- Tianyun deterministic plan SHA: `807311ce1dccb6748286c3d92d69685a7fb089064b4a7272a0988f6fd9d81816`.
- Source import Production gate applied exactly once: batch `1`, `43` rows, `27 ready / 16 excluded / 0 review`, `48` total units, `32` customer units, `16` excluded units.
- Source economics verified: Storage Pool `EUR 29.76`, allocation rate `EUR 0.62/unit`, ready-customer source share `EUR 19.84`, excluded KOL/historical share `EUR 9.92`.
- Source-import replay remained deterministic/idempotent and the process-local import apply flag was not persisted.
- Finance Production dry-run gate locked `seen=6247 / apply=26 / noop=0 / blocked=6221 / fx-freeze=0`.
- Blockers were `warehouse_storage_allocation_missing=6220` plus exactly one `stronger_or_different_existing_fact`.
- Strong-fact case: Order `1200` / `007-0813-c26990` retained existing `warehouse_cost=USD 1.00`, provenance `confirmed_manual`, status `confirmed`; Tianyun `EUR 0.62` allocation did not overwrite it.
- Finance Production materialization applied exactly `26` new `warehouse_cost` entries; total `order_cost_entries` moved `13876 → 13902`.
- All 26 P7 entries are `allocated_actual / confirmed` with exact `warehouse-storage-allocation:v1:batch:1:order:<order_id>` source references.
- Materialized P7 customer amount is `EUR 19.22`; adding the blocked strong-fact source share `EUR 0.62` reconciles exactly to original ready-customer source allocation `EUR 19.84`.
- All 26 materialized Orders and the Tianyun source are EUR, so no cross-currency freeze was required; FX fields remained null and `fx-freeze=0` is correct.
- Finance completeness refreshed for all 26 materialized Orders; none remained missing `warehouse_cost`.
- Post-apply replay dry-run: `seen=6247 / apply=0 / noop=26 / blocked=6221 / fx-freeze=0`.
- Existing Accounting continuous-delta dry-run after closure: gate PASS, `candidate_count=0`, `processed=0`, `failures=0`; no new scheduler exists and no duplicate warehouse-cost write is pending.
- Persistent Finance warehouse-cost apply flag remained unset after apply.
- P7 remains backend-only; per canonical handoff no visual Owner UAT is required because UI scope did not change.
- ERP Changelog intentionally not updated; changelog remains manual-only until explicit Owner request.
