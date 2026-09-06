# ERP-LOGISTICS-HISTORICAL-IMPORT-M1-M1-1-CLOSURE

STATUS: CLOSED / DEPLOYED / PRODUCTION ACCEPTED
CLOSED_AT: 2026-09-06
OWNER_DECISION: 3 unresolved Review rows require no further processing

## Final Production truth
- Canonical source: `0902-网红订单.xlsx`, sheet `工作表1`.
- Source checksum: `164fe6242ec612d024bd521623879b0d3ede7afef2054347a8bce07f8f9d8c41`.
- Source rows: 1,077.
- M1 Ready classification: 788; all 788 were committed as Historical Evidence.
- M1.1 Review Rescue: 141 source rows -> 277 Historical Evidence.
- Final Historical Evidence total: 1,065.
- Remaining unresolved Review: 3 rows, intentionally left with 0 evidence by Owner decision.
- Skip: 145 rows, intentionally not imported.

## Safety invariants at closure
- Historical evidence remains separate from active Shipment truth.
- No fuzzy KOL identity matching was introduced.
- No active Shipment was created by M1/M1.1.
- No KOL Collaboration was created by M1/M1.1.
- Multi-leg tracking is preserved as multiple historical evidence items rather than selecting a guessed canonical tracking number.
- Both Production write gates are OFF after completion.

## Delivery record
- M1 implementation: PR #205; Production Historical Import deployed with Apply OFF before real-data commit.
- MySQL FK compatibility correction: PR #208.
- M1 Ready Production commit: 788 evidence rows.
- M1.1 Review Rescue: PR #216, merge `b044ec0aa3e1b791154d1dbca2237c4b8409d1f0`.
- M1.1 Production release: `logistics-review-rescue-m1-1-20260905-223939-b044ec0a`.
- M1.1 Rescue Production commit: 277 evidence rows; audit recorded 141 rescueable rows / 277 created evidence.
- Pre-rescue backup: `pre-rescue-277-20260905-224714.sql`, SHA256 `f863c027f4c70bd87dfdbc26242a5a983deeeeba6eb409dd46d24ba3e13fbabc`.

## Closure decision
Historical Import M1 and M1.1 are complete. The three exact-KOL-unresolved Review rows are an accepted residual and are not backlog. The 145 Skip rows remain intentionally excluded. No M1.2 or additional historical-import remediation is planned unless the Owner explicitly reopens this scope in a new work package.
