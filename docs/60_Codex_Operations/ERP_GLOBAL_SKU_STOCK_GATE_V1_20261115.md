# ERP GLOBAL SKU-LEVEL STOCK GATE V1 — Owner frozen rule
Date: 2026-11-15
Work package: ERP-GLOBAL-SKU-STOCK-ISOLATION-20261115
Base: erpbeyourlovercom main 0508a126
Status: Dev implementation and CI/UAT, **not** a Production activation instruction.

## Business rule

**One SKU problem must not shut down other SKUs at the same warehouse, or the same SKU in another warehouse.** Warehouse pages may aggregate alerts but may never use the count of unrelated SKU errors as an order-wide warehouse stock permission switch.

| Source family | SKU trusted stock | Unsupported/unknown SKU | Dispatch rule |
| --- | --- | --- | --- |
| MorePlus / US-LA | Signed warehouse provider mirror for that particular SKU, capped by ERP reservations | No signed SKU sync => zero usable until that SKU is read back | Never rewrite physical provider stock during ERP reservation or shipment |
| XLWMS / US-SM | Complete authentic OMS page scan plus verified identity and protected P9 lot reconciliation, then per-SKU guarded apply | Unmatched positive or zero => quarantine this SKU and preserve remote observation | Only individually applied, currently fresh and sufficient SKU can pass live OMS preflight |
| US-NY / DE / CA / China factory / other ERP manual stock warehouses | Known local SKU inventory row, manual or confirmed batch, with correct reservations and existing operational review | No SKU inventory record => unknown, never invent stock | Reserve/release/commit only that item's row; existing shipping/review gates remain |
| US-AMZ | Existing SKU stock and manual warehouse route | Unknown => no stock | Existing explicit no-auto-allocation rule preserved |

- Checked individually for each requested SKU and warehouse; multi-item order must pass **all its own items** atomically (order-level consistency is not warehouse-wide blocking).
- Fail closed for an inaccessible, incomplete, truncated or invalid provider response, malformed provider totals or changed provider identity. These are system/source integrity failures, not a single known SKU issue.
- Never automatically verify OMS Alias physical identity, change a warehouse's real inventory, override staff manual order edits, or resend an existing OMS order.
- Provider physical stock remains authoritative for overseas connected warehouses. MorePlus and US-SM ledger mirrors remain read-only relative to the external provider. US-SM ERP reservation commit must not subtract provider QOH.
- An existing real SKU with ambiguous product identity, unknown-age batches, protected consumption drift, insufficient physical/reserved units, or a per-SKU auto adjustment beyond the cap remains blocked **only for that SKU**.
- First US-SM V2 apply requires the exact full provider snapshot checksum approval; the new SKU isolation gate cannot bypass this. An operator may separately approve/enable application only after diff review.
- A partially applied V2 snapshot tracks **exactly which product IDs were safely reconciled and written**. Nonapproved rows never enter the provider available map. A subsequent signed OMS read may carry forward an already applied individual SKU only if SKU identity, physical, available, locked, local QOH and lot reconciliation evidence remain identical.
- No new DB tables or migrations required.

## Release gate

Global rollout switch: `INVENTORY_SKU_LEVEL_GATE_ENABLED` (default **false** until Owner Dev acceptance and controlled Production activation). Existing XLWMS `XLWMS_INVENTORY_APPLY_ENABLED`, V2 mode flag and full checksum approval **remain separate**.

1. Verify GitHub CI MySQL/SQLite/30-Tab green; run PHP inventory/OMS/Gift/MorePlus/warehouse grid tests and Vue Gift UAT.
2. Independent Dev database canary: US-SM matched C549A and VP757B allowed; four unknown zero-SKU identities remain quarantined and can never dispatch; verify no OMS external write; US-LA one synced SKU available and another unsynced SKU blocked; US-NY/DE/CA manual row independence.
3. Owner visual UAT, then separate production deploy and flag activation. Do not launch live `sync(true)` or real Gift order without a fresh OMS read, exact SHA approval, DB backup, warehouse audit, and order-level approval.
4. Re-check MorePlus provider stock authority, P9 consumption gross/net, other warehouse stocks, all 30 tabs, Dede approvals, website Sync OS.
5. Rollback: switch the feature flag off (one change, no stock write), revert the release directory SHA if required. Any controlled actual V2 partial apply must be preserved as accounting history, never blindly rolled back.

## Test coverage

- Baseline 42 PHPUnit inventory/Gift cases passed (337 assertions) with switch off.
- Reservation baseline 13 cases passed (46 assertions) with switch off.
- Added 4 US-SM partial apply tests (unknown zero, unknown positive, FIFO conflict, excessive delta), 4/4 PASS; first checksum gate and post-apply checked-source carry forward.
- Added independent MorePlus/manual/Amazon warehouse reader tests and manual/US-SM reservation cases.
- OMS actual-outbound preflight case confirms healthy SKU passes in a partially applied warehouse, unknown SKU and stale source remain blocked.
- Vue Gift suite 23/23 PASS; frontend TypeScript and 30-Tab bundle build PASS.
- Broader OMS legacy permission-route test `test_warehouse_batch_route_requires_logistics_operation_permission` is known pre-existing failure on clean baseline, not modified by this project. CI results must be reviewed independently.

This work intentionally does not state that the current Production US-SM V2 snapshot is applied or that VC549A/VP757B are live-shippable: they remain blocked until the new global gate and approved per-SKU V2 apply are deployed and verified.
