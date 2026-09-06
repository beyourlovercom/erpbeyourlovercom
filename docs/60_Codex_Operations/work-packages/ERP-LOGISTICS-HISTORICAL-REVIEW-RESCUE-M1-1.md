# ERP-LOGISTICS-HISTORICAL-REVIEW-RESCUE-M1-1

Status: DESIGN_PENDING_OWNER_PASS
Base: origin/main@2a7f0208000a1f9ba7325fcdc430307f9be3c975

## Production truth entering M1.1
- Batch #1 source: `0902-网红订单.xlsx`, checksum `164fe6242ec612d024bd521623879b0d3ede7afef2054347a8bce07f8f9d8c41`.
- 788 Ready source rows are already committed as 788 Historical Evidence rows.
- 144 source rows remain Review; 145 remain Skip.
- Apply gate is OFF.

## Review audit
- 141/144 Review rows have exact KOL resolution; 3 have no exact reconciliation candidate and remain manual Review.
- 54 `suspicious_compound_tracking` rows are 54/54 deterministically splittable into two source tracking candidates.
- 75 multi-tracking rows contain real multi-leg source evidence; do not choose a canonical tracking number.
- 9 duplicate-source-order rows are safe as source-row-scoped historical evidence; duplicate Order No. must not collapse rows.
- 2 explicit replacement/transfer rows preserve all explicit old/new/transfer candidates.
- 1 carrier-review row preserves raw carrier text without carrier inference.
- Deterministic rescue scope: 141 source rows -> 277 tracking evidence candidates.

## Frozen M1.1 behavior
1. Historical evidence only. Never create/update Orders, Shipments, KOL Collaborations, carrier master, tracking provider state, accounting, or Woo facts.
2. Multi-leg source rows produce one Historical Evidence item per explicit source tracking candidate; never select a canonical/main tracking number.
3. Preserve source row, raw tracking, raw carrier, candidate ordinal/count, resolution strategy, and full safe evidence snapshot.
4. Compound split is allowed only when a whole source token deterministically matches `WSH[A-Z]{2}<10 digits>YQ` + a second >=8-char alphanumeric token. No prefix-based carrier inference.
5. Replacement/transfer parsing may inspect safe Carrier/Tracking text only when explicit markers such as 新单号/转单/改为 are present; preserve all explicit candidates.
6. Duplicate Order No. is not a blocker for historical evidence because identity/idempotency is source-checksum + source-row + candidate evidence hash.
7. Three rows with no exact KOL remain unresolved Review. No fuzzy identity matching.
8. Existing 788 evidence rows must remain byte/identity stable and must not duplicate on re-preview/replay.
9. Production rescue apply remains separately gated OFF by default.

## UI proposal
Extend the existing `/logistics/historical-import` page only. Add one Review Rescue card below the current summary: `可安全救回 141 / 277 tracking evidence / 人工复核 3`, breakdown chips, multi-tracking preview chips, and a separate `提交可安全复核证据` action. No redesign of the approved M1 page.

## Next gate
OWNER_VISUAL_PASS required before tracked UI implementation.
