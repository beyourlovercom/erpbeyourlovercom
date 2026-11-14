# US-SM OMS V2 P1 consumption-aware reconciliation (2026-11-14)

WORK_PACKAGE: ERP-USSM-P1-LOT-NET-V2-20261114
EXECUTION_PROVIDER: GPT_WEB (US Remote DC; frozen design, direct bounded remediation after Claude auth gate and Codex article-only instruction prevented handoff)
VERIFICATION_TIER: MILESTONE_RELEASE
BASELINE: main 8e6e3f65 (PR #777 diagnostics merged)
PRODUCTION_WRITE: FORBIDDEN by this package. Only separate explicit Owner release and reconciliation apply approvals.

## Verified Production read-only evidence, snapshot #23

The V2 blocked snapshot reported 13 blocking conditions: 12 product-specific and 1 unmatched-stock-summary condition. All four unverified OMS inventory SKUs (BV339A, MF414, TL278A, TL278B) have remote physical 0 and still require independent physical SKU identity verification. Do not assume MF414 equals MF414A or that existing TL278A/B aliases have approved OMS evidence.

Seven canonical mapped SKUs (A1, MW35, VA617A, VA617A-Pink, VG515A, VX357A, VX357D) all have OMS zero physical stock, no local warehouse inventory row, no dated lots, no recorded FIFO consumption or aging accrual. Establish only a genuine **zero** ledger baseline after a fresh authoritative OMS fetch and existing full-snapshot checksum/apply approval.

Five matched products have direct P9 consumption rows from existing unbatched source inventory lots. P9 stores lot gross quantities and separately stores immutable source-lot consumption; it does NOT decrement gross lots.

| OMS SKU / ERP SKU | Gross dated lots | Captured consumption | Net FIFO | OMS physical | Safe conclusion |
|---|---:|---:|---:|---:|---|
| C549A / VC549A | 10 | 1 | 9 | 9 | Net matches; preserve source lot |
| CL279B | 27 | 3 | 24 | 24 | Net matches; preserve source lot |
| VP676A | 6 | 2 | 4 | 3 | **BLOCK: unexplained 1-unit mismatch** |
| VP757B | 16 | 2 | 14 | 14 | Net matches; preserve source lot |
| VX760A | 3 | 1 | 2 | 2 | Net matches; preserve source lot |

These observations are a snapshot in time, not an authorization to apply the obsolete snapshot or assume current OMS conditions are unchanged.

## New bounded service rules

- For an OMS matched stock row with historical P9 consumption, require each row to reference a dated sellable, unbatched source lot of that same warehouse/product, matching status/date, and require consumed quantity not to exceed source lot gross.
- Use **net dated = gross dated - sum(verified direct P9 consumption)**. When net dated exactly matches OMS physical and unverified-age baseline is zero, reconcile ERP QOH to OMS physical upon existing authorized apply, without modifying any original lot or consumption row.
- A mismatch such as VP676A remains blocked (`LOT_NET_PROVIDER_MISMATCH`) with gross, consumed, net and remote quantities. Orphan/batch-linked/invalid P9 evidence remains blocked (`LOT_CONSUMPTION_SOURCE_UNVERIFIED`, `LOT_CONSUMPTION_EXCEEDS_SOURCE_LOT`).
- Permit only authoritative OMS physical ZERO on a verified matched SKU with no ERP inventory row, no lots/consumption/aging/unknown-age and no soft-deleted row. A normal, explicitly approved apply creates one zero QOH/zero reservation record, never positive stock.
- Keep unmatched remote SKU identity blocked even at zero stock. Continue to require the existing exact-checksum first V2 apply approval, nonstale OMS fetch, global blocking transaction, historical aging protection and no partial writes.
- No changes to XlwmsInventoryCoverage or Gift selection bypasses. PR #777 continues to show 0 effective Gift stock until an approved full V2 applied snapshot.

## Evidence and gates

- PHP: XlwmsInventorySyncServiceTest **26/26 PASS, 172 assertions**, including new P9 net match, unexplained shrink, orphaned source, zero baseline and unmatched zero cases.
- Broader P9 + XLWMS run: 117 tests, 3 old failures. An isolated detached baseline checkout of exact main 8e6e3f65 reproduced all 3 identical errors: two Dede overseas review prerequisites in InventoryWarehouseStorageAgingP9OperationalTest and one permission-route expectation in XlwmsSingleOrderDispatchTest. These are existing failures, not ignored or changed by this PR.
- No actual Production ERP inventory rows, aliases, lots, consumption evidence, OMS snapshot or orders changed.

## Remaining before P2 Production closure

1. Obtain actual physical SKU identity evidence and approved alias mapping for BV339A, MF414, TL278A, TL278B; no automatic approval.
2. Reconcile VP676A extra 1 unit with genuine shipment or OMS return/adjustment evidence. Do not fabricate an adjustment.
3. Re-run fresh OMS full read, compare SKU mapping/stock/lot ledger and exact checksum; ensure all blockers disappear.
4. Only after separate Owner authorization, make the controlled first V2 apply with explicit checksum, backup and post-apply readback. Then recheck Gift VC549A/VP757B and real fulfillment preview (no batch order mutation).
