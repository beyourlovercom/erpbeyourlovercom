# ERP-LOGISTICS-HISTORICAL-REVIEW-RESCUE-M1-1

Status: CLOSED / DEPLOYED / PRODUCTION ACCEPTED
Base at proposal: origin/main@2a7f0208000a1f9ba7325fcdc430307f9be3c975

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

## Owner visual gate — PASS — 2026-09-06
- Owner reviewed the rendered M1.1 Review Rescue proposal and replied `pass`.
- Implementation proceeded only after this PASS.

## Implementation verification
- Focused backend: 11 tests / 109 assertions / 0 failures.
- Historical Import frontend: 2/2 PASS.
- Full Logistics regression: 39 tests / 382 assertions / 0 failures (warnings only).
- Typecheck PASS; build PASS; `git diff --check` PASS.
- Production-safe 144-row replay through the implemented PHP extractor: 141 rescueable rows / 277 candidate evidence / 3 manual rows.
- US Mac Local/UAT used isolated SQLite with only Historical Import safe fields; no Production DB was mounted into UAT.

## Owner Local/UAT — PASS — 2026-09-06
Owner reviewed the real US Mac Local/UAT page at `/logistics/historical-import` and replied `pass`. Verified presentation included:
- source totals 1077 / Ready 788 / Review 144 / Skip 145;
- Review Rescue 141 rows / 277 Tracking Evidence / 3 manual;
- breakdown 1→1 carrier, 75→153 multi-tracking, 54→108 compound, 9→10 duplicate Order, 2→5 replacement/transfer;
- Rescue commit action disabled while gate is OFF;
- explicit Historical Evidence-only / Active Shipment unchanged messaging.

## Production gate
`LOGISTICS_HISTORICAL_REVIEW_RESCUE_COMMIT_ENABLED` remains OFF by default. Owner UAT PASS authorizes normal Git delivery/merge under `BYL_ERP_CONTINUOUS_OWNER_AUTHORIZATION_V1`; it does **not** authorize Production deployment or writing the 277 Rescue Evidence rows.

## Final latest-main reconciliation — 2026-09-06
- Rebasing target: `origin/main@b578ea6ab858caa9cfc3dac0ce9872043afe11e0`.
- Only overlapping path was `config/logistics.php`; resolved as an additive union preserving both the existing Historical Order Materialization apply gate and the new Review Rescue commit gate.
- Post-rebase full Logistics backend: 53 tests / 456 assertions / 0 failures.
- Historical Import frontend: 2/2 PASS.
- Logistics Index frontend: 6/6 PASS.
- Typecheck PASS; build PASS; `git diff --check` PASS.
- Post-rebase real 144-row safe UAT replay using the implemented PHP service: 141 rescueable / 277 evidence / 3 manual; Rescue gate=false.

`PACKAGE_STATUS: CLOSED / DEPLOYED / PRODUCTION_ACCEPTED`
## Production completion and closure — 2026-09-06
- PR #216 merged with merge commit `b044ec0aa3e1b791154d1dbca2237c4b8409d1f0`.
- M1.1 was deployed as bounded Production release `logistics-review-rescue-m1-1-20260905-223939-b044ec0a`; no migration was required.
- Production Rescue plan before commit: **141 rescueable rows / 277 pending evidence / 3 manual unresolved**.
- Owner explicitly authorized the Production Rescue commit; exactly **277** Rescue Historical Evidence rows were created.
- Final evidence state: **788 legacy M1 evidence + 277 M1.1 rescue evidence = 1,065 total**.
- Rescue evidence covers exactly **141 source rows** with **277 unique evidence hashes**.
- The remaining **3 unresolved Review rows have 0 evidence and are intentionally accepted as unresolved by Owner; no further processing is required**.
- Orders / Shipments / KOL Collaborations were not mutated by the Rescue service transaction. A concurrent unrelated process changed the global Orders count before the Rescue transaction; Rescue itself preserved its immediate transaction baseline.
- Production `LOGISTICS_HISTORICAL_REVIEW_RESCUE_COMMIT_ENABLED` is OFF after commit; the original Historical Import commit gate is also OFF.
- Final status: **CLOSED / DEPLOYED / PRODUCTION ACCEPTED**. No M1.2 is planned.
