# BYL AI Worker A7–A8 Dev checkpoint

- A7 synthetic corpus: 100 generated cases in ten format families. Baseline
  10/100 complete, 160/420 field checks; revised 100/100, 420/420.
  Local measured P95 0.037–0.045 ms in Vitest. These are regression fixtures,
  not representative real KOL accuracy or 100 independent model requests.
- Existing three synthetic cloud fixtures and signed cross-device path were
  verified in A4–A6. A7 Windows hybrid-client self-test passed synthetic-only
  protection, simulated connection failure and live AI suggestion review.
  This does not validate provider-side timeout, sustained concurrency or
  production quotas. No arbitrary text sent to cloud.
- A8 shared KolSmartInput mounted in KOL and KOL order create pages, with
  parse preview, field mapping, identity search hint and pasted-address review.
  The UI is local-rules only; the separate Windows hybrid client is not yet
  wired to the ERP backend. Product hints are not auto-selected SKUs.
- 76/76 targeted Vitest tests passed, including four new page integration
  cases. Global typecheck blocked by missing ag-grid-community and grid
  type errors; not a full build or browser UAT.
- Remaining gates: 100 representative deidentified, independently labeled
  real samples; accuracy and sustained inference/timeout tests; supported
  shared-service authorization; ERP backend bridge; profile-first missing-KOL
  creation and draft resume; server-side address confirmation and affiliate
  repair; full build and isolated browser UAT; ERP2 port.
- No production deployment, production database writes or live affiliate calls.
