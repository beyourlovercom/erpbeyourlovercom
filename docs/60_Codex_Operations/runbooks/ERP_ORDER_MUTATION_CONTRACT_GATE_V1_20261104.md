# ERP Order Mutation Contract Gate V1

**Status:** OWNER APPROVED · MANDATORY · effective 2026-11-04
**Rule ID:** `ERP_ORDER_MUTATION_CONTRACT_GATE_V1`
**Incident:** Order #7870, PR #619 — the order editor displayed a SKU/stock preflight button while the corresponding POST route was absent from the deployed backend. An operator and an administrator both received a misleading generic error.

## Scope

Every new or changed ERP control that can **mutate an order or its workflow** must satisfy this gate. Examples: SKU/quantity/line amendments, archive/restore, workflow status, warehouse allocation, inventory reservation/release, logistics review, shipment/reopen/reship, tracking, and Woo/order synchronization that can overwrite order state. It also applies to mutations initiated from order lists, bulk actions, mobile pages, and role-specific surfaces.

## Same-revision contract

The **same exact GitHub commit/PR head** must contain and verify all of:

1. **UI action:** Button/menu entry is rendered only when appropriate; click invokes the documented HTTP method and exact route with expected payload. Disabled/hidden and error feedback are tested.
2. **Backend route:** Real registered route matches the frontend method/URI, targets a callable controller, and is covered by an authenticated request test. A missing endpoint (404) is **FAIL**, not a generic SKU/permission error.
3. **Authorization:** Authorized staff/admin operations succeed; unauthorized/read-only staff receive 403; cross-order/derived/KOL ownership boundaries are enforced. Never trust frontend-only authorization.
4. **Preflight/business gate:** Validate SKU, amounts, lifecycle state, fulfillment, stock/reservations, optimistic revision, and required external constraints as applicable. Read-only preflight must make no writes. Return specific actionable validation/409/403 errors.
5. **Persistence and readback:** Authorized mutation actually changes the correct records; independent DB readback verifies result and protected identities. Verify audit trail, immutable paid amounts, existing shipments, reservations and Woo line references where applicable. Negative cases must prove **no partial writes**.
6. **Regression:** Existing order/warehouse/accounting/shipping paths and previously Owner-accepted UI remain correct.

## Mandatory PR CI and release checks

- Use the **existing** `.github/workflows/erp-orders-kol-cross-module.yml`; do not create a parallel test framework.
- For order-mutating PRs, the workflow runs the Laravel Orders/KOL HTTP tests, the Order Mutation Contract test, the order editor Vue/Vitest tests, `npm run typecheck`, `npm run build`, and the existing diff check **on the same PR head**.
- Any failure of those checks blocks merge. A new mutation control must add a focused positive/negative HTTP + DB test and frontend click/visibility test in that **same PR**; do not rely on unrelated suites merely running green.
- UI and API route must be shipped together. A feature flag cannot hide the absence of tested backend functionality for a live clickable control. Do not release frontend ahead of its backend.
- If the change lives outside `app/Modules/Orders/**` or `resources/js/Pages/Orders/**`, the owning PR must also update the relevant CI path filters and focused tests; never silently skip this gate.
- Before an **Owner-authorized** Production release: confirm expected exact SHA, rollback marker, route registration, no unauthorized migration, and operation role. After release, verify `/up`, reachable route/method and a **safely authorized** real or synthetic transaction readback where appropriate. Never test unauthorized real shipments or payment changes.
- Production deploy remains a **separate Owner PASS**; passing CI or merging to main never grants Production authorization.
- Preserve snapshots/audit and a rollback or corrective recovery plan for any real-order canary. Do not claim that code rollback alone restores already-mutated order data.

## Evidence required in each PR / handoff

Record **UI action → HTTP method + URI → controller → permission → preflight result → database assertion → audit/result → CI run SHA → release readback**. Include successful and blocked scenarios and their user-visible messages. If any link is absent, the status is `CONTRACT_GATE_FAIL` and the feature must not ship.

## Current reference coverage

`tests/Feature/Orders/OrderItemAmendmentPreflightTest.php` covers the #7870 amendment HTTP/route/permission/amount/reservation/save scenarios; `resources/js/Pages/Orders/Show.test.ts` covers order editor controls and interaction, and the same-head CI also runs `npm run typecheck` and `npm run build`. Together they provide the current layered gate. These are minimum examples, not automatic coverage of every future action; new mutation flows must add specific button-to-HTTP and write/readback tests.

**Avoid expanding feature scope or changing Production as part of this governance-only gate.**
