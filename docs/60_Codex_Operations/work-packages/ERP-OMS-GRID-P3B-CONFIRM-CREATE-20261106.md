# ERP OMS Grid P3-B — Confirm and create imported customer orders

EXECUTION_PROVIDER: GPT_WEB
VERIFICATION_TIER: HIGH_RISK
BASELINE: main@f68829254b9f08106f6a6110e66128bfca9bee90
OWNER_AUTHORIZATION: 2026-11-06 — Owner approved starting P3-B development and Dev validation; Production release is not authorized.
DEPENDENCIES: P3-A preview-only merged by PR #638; WPS date compatibility merged by PR #640.
DEPENDENCY_TYPE: SEQUENTIAL
PARALLEL_SAFE: NO (dedicated branch/worktree)

## Objective

Complete the P3-B safety and acceptance contract for the **new OMS Grid**. Reuse the existing XLSX import implementation, confirmation UI and backend route; do not build a second importer.

## Scope

- Verify the existing independent P3-B switch `ERP_OMS_GRID_PRODUCTION_IMPORT_ENABLED` remains OFF by default.
- Verify Production commit access requires the existing OMS Grid allowlist, scoped to administrator ID 1 for the approved canary.
- Add same-PR backend tests for allowed admin creation, non-allowlisted denial, disabled-gate denial, audit/readback, and exactly-once replay protection.
- Add same-PR frontend tests for preflight → explicit browser confirmation → exact confirm endpoint/payload; cancellation must not submit.
- Run focused backend/frontend tests, Orders/KOL mutation-contract CI, typecheck and build on the exact PR head.
- Prepare the isolated Dev/UAT environment and leave the **new OMS Grid** ready for Owner validation.

## Existing behavior to preserve

- P3-A template download and no-write preflight remain independent from P3-B.
- The existing confirm route rechecks the same XLSX hash, actor-bound 15-minute token, product/SKU and duplicate validations.
- Creation uses the canonical OrderService transaction and audit; batch/source claims prevent repeat import.
- Created records are manual customer Orders only. No WooCommerce sync, warehouse allocation, shipment creation or auto-dispatch.
## Authorized actions

- Modify focused OMS P3-B tests and this Work Package record.
- Use synthetic XLSX fixtures and isolated test databases only.
- Commit, push, open one Draft PR, run required CI, and merge after Owner UAT.

## Prohibited actions

- No Production deployment or live P3-B enablement.
- No real buyer data or Production database writes.
- No changes to P1, P2, P3-A or unrelated order workflows.
- No new importer, schema migration or dependency.
- Do not claim Owner UAT PASS before Owner inspection.
## Acceptance criteria

1. In Production-mode tests, allowlisted admin ID 1 with P3-B ON sees the confirm control and creates a synthetic order.
2. Non-allowlisted users are denied at the page and import endpoints.
3. P3-B OFF keeps confirm 404 while P3-A preview remains independently available.
4. Confirm requires the exact previewed XLSX, actor-bound unexpired token and explicit browser confirmation.
5. Successful creation persists one canonical manual/customer Order, SKU items, address, ERP order ID, audit, batch and source claims atomically.
6. Replay creates no second Order; blocked/failed cases leave no partial writes.
7. No shipment, warehouse allocation or WooCommerce outbound sync is triggered.
8. Frontend sends POST /orders/oms/import/confirm only after confirmation; cancel sends no request.
9. Exact PR head passes required Orders/KOL CI, focused tests, typecheck and build.

## Validation

```text
REQUIRED_COMMANDS:
- php artisan test --filter=OrderOmsProductionGateTest
- php artisan test --filter=OrderOmsBatchImportTest
- focused OrderOmsTransfer Vitest suite
- npm run typecheck
- npm run build
- erp-orders-kol-cross-module.yml on exact PR head
EXPECTED_EVIDENCE: route + role gate + isolated DB readback + audit + replay denial + frontend request payload + CI SHA.
HARD_GATES: no Production flag change; no real business data; Owner Dev/UAT PASS required before closure.
```
## Project Position

```text
PROGRAM_ROADMAP: Architecture / ERP core [COMPLETE] → Product / Order / Inventory [COMPLETE] → Manual Fulfillment + Woo inbound / tracking / sync [COMPLETE] → KOL / Customer / Purchase / Logistics / Accounting / Warehouse core [COMPLETE] → OMS Grid R3: P1 read-only → P2 export → P3-A preview → P3-B confirm-create [CURRENT] → R4 Inventory Grid migration [WAITING] → Fulfillment intelligence / warehouse connectors → Accounting / Procurement expansion → Product / Media / Content / SEO / Shopify expansion → Production hardening
CURRENT_TRACK_PHASE_MAP: P1 read-only [COMPLETE] → P2 export [COMPLETE] → P3-A XLSX preview [COMPLETE] → P3-B confirmed real order creation [CURRENT] → Owner Dev UAT → merge → separate Production approval
COMPLETED_PHASES: P1 read-only; P2 export; P3-A template/upload/preflight; WPS date normalization PR #640
CURRENT_PHASE: P3-B confirm-create safety and Dev validation
CURRENT_WORK_PACKAGE: ERP-OMS-GRID-P3B-CONFIRM-CREATE-20261106
PACKAGE_STATUS: OWNER_AUTHORIZED / IMPLEMENTATION
WAITING_PHASES: focused validation; prepared Owner Dev/UAT; separate Production approval
BASELINE: main@f68829254b9f08106f6a6110e66128bfca9bee90
GITHUB_STATE: PR #640 merged; dedicated P3-B branch created from exact main baseline
SOURCE_CONFLICTS: NONE WITH EVIDENCE
GLOBAL_GATE: ERP_ORDER_MUTATION_CONTRACT_GATE_V1; same-PR UI→route→controller→permission→preflight→transaction→audit/readback
PRODUCTION_DEPLOYMENT_STATUS: NO WITH EVIDENCE — no Production changes authorized or performed
NEXT_DEPENDENCY: exact-head tests and Owner inspection of the prepared Dev OMS Grid
EVIDENCE_AS_OF: 2026-11-06
```
## Roadmap Hours

```text
CURRENT_PACKAGE_ESTIMATE: 2.25 hours planning value; 1.5–3.0 hour range
KNOWN_CURRENT_TRACK_REMAINING: P3-B focused tests + Dev Owner UAT; Production release is a separate future gate
ARCHITECTURE_GATE_REMAINING: NONE WITH EVIDENCE — importer, token, transaction and independent P3 flags already exist on main
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE — legacy estimate sources are not reconciled to this 2026-11-06 package
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE
MVP1_REMAINING: UNKNOWN WITH EVIDENCE
POST_MVP_KNOWN_INCREMENT: P3-B estimate above; no unrelated increments included
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE — full-program estimate not reconciled against current main
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE — no accepted work-log ledger for this package
ESTIMATE_CONFIDENCE: MEDIUM
ESTIMATE_AS_OF: 2026-11-06
ESTIMATE_METHOD: bounded existing implementation review + focused mutation-contract tests + isolated Dev UAT; no schema or feature-engine rebuild
```
## Estimated Codex Hours

```text
PREFLIGHT_AND_SCOPE: 0.25 hours
CORE_EXECUTION: 0.75 hours
TEST_AND_VALIDATION: 0.75 hours
REMEDIATION_BUFFER: 0.50 hours
ESTIMATED_EFFECTIVE_HOURS: 2.25 hours
ESTIMATE_RANGE: 1.5–3.0 hours
ESTIMATE_BASIS: reuse existing P3-B controller/service/UI; add exact production-gate and browser-request tests; validate isolated Dev path. No migration or new importer.
OWNER_REVIEW: INCLUDED
MERGE: INCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: INCLUDED
DEPLOYMENT: EXCLUDED
```
