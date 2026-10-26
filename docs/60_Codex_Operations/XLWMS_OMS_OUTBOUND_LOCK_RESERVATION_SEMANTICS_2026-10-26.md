# US-SM OMS pre-shipment locks — provider-available stock policy

## Owner clarification (2026-10-26)

Real XLWMS `productStockDtl.lockAmount` reflects goods already reserved
for pending warehouse shipments. A lock **is not evidence of defective
stock, stock loss, or an ERP-created `quantity_reserved` record**.
Do not fabricate local reservations and do not block inventory QOH sync
solely because `OMS lockAmount > ERP quantity_reserved`.

The signed warehouse-scoped `integratedInventory/pageOpen` API has
two separate truths per SKU:

- `productTotalAmount` = physical GOOD stock QOH (includes OMS locks).
- `productStockDtl.availableAmount` = provider physically available,
  excluding provider's outbound locks.
- `productStockDtl.lockAmount` = provider-owned outstanding allocation
  (expected to be followed by an actual provider outbound shipment).
- Invariant: `QOH = available + lock`; `transportAmount` is never
  added to physical QOH.

## Behavior

- A provider lock is **allowed** during V1 dry-run and V2 reconciliation,
  even when local ERP reserved=0. Local ERP reservations only block
  when `ERP reserved > provider physical QOH`.
- Snapshot rows persist all three source values; the R7 snapshot checksum
  includes the source lock state. The V2 checksum contract was bumped
  to version 4 to prevent accidental replay of old 'lock=error' snapshots.
- Post-R6-applied V2 allocation uses **provider available** as a ceiling,
  preserving physical QOH separately. A product with 7 physical/6 OMS
  available/1 locked/0 ERP reserved can offer at most 6 pieces.
- Additional local reservations are conservatively deducted from the
  provider-available ceiling unless a verified same-order identity is
  available. This may under-offer stock if the reservation is identical,
  but never re-sells remotely held stock.
- `XlwmsInventoryCoverage` returns the available quantity for each
  current matched V2-applied provider product; if latest snapshot is
  blocked/unapplied or SKU is unreturned, availability is 0.
- Unknown-age inventory still does not count as FIFO-eligible; the
  previously preserved lot, aging and alias protections are unchanged.
- When the OMS lock is released, a freshly *applied* V2 snapshot can
  increase allocatable quantity without modifying ERP QOH or inventing
  a local reservation.
- No outbound API, booking, release, shipment status, pricing or Woo
  updates are performed.

## Live read-only evidence

User confirmed three provider outbound pre-shipment locks. Latest
isolated China Mac read from authenticated provider before this patch
(returned 2026-10-26T12:40:19Z): 17 good stock SKU rows, physical
QOH=102, provider available=99, locks=3. Specific provider SKUs:
`Pillow-blue`: 5/4/1, `VP676A`: 7/6/1, `VP757B`: 17/16/1
(total/available/locked). Other SKU locks zero.

Earlier validation failed solely due to the legacy mistaken
`OMS lock > ERP reserved` guard. This is now accepted.

This does **not** authorize any Production --apply: Production still
requires secure provider credentials, Product OS manually verified
SKU Alias identity, latest complete live Production read-only
snapshot, reviewed exact first-write checksum and pre-apply backup.
The scheduler and apply flags must remain OFF until those gates pass.
