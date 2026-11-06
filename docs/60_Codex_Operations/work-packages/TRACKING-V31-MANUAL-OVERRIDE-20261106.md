# Tracking V3.1 — Owner manual AfterShip and Woo Completed override

2026-11-06 (Asia/Taipei) | Issue #635 | Branch: feat/manual-woo-tracking-override-20261106

## Frozen Owner rules
1. Retain existing Sync Woo Tracking per-row button; permit a single order of any ERP state with a real Tracking value to manually sync AfterShip and force the linked Woo order Completed.
2. For ANY A/B/C split child, publish that specific child's Tracking on the real/root Woo customer order, and make the mother Woo order Completed even if other children are pending.
3. Never introduce a second sync engine or button, guess SKU-to-Woo line identity, or replay the old failed/pending queue in bulk.

## Isolated code delta
- Existing domestic and overseas row buttons submit manual_override=true for exactly one order and appear whenever Tracking exists. Their batch action stays strict and unchanged. Role and permission gates preserved.
- Orchestrator writes a separate audited tracking_manual_force_completed run with per-order failed, skipped, conflict or succeeded outcome; the page displays actual reasons and skips rather than a false success.
- Manual service uses Woo root source and provider reference identity, child Tracking number, configured courier slug or order-level OTHER if the carrier is unknown, stable ship date; splits and old records with missing Woo IDs use order-level AfterShip package (empty line_items, never SKU guessing).
- Existing Woo gateway permits order-level package, verifies Woo native meta readback and preserves existing package and bindings on repeat. Protected Woo states can ONLY be overridden using an explicit additional allowProtectedStatus=true parameter from the permission-scoped manual operation. Automatic, retries, batch and prior caller defaults remain false.
- Manual write verifies BOTH AfterShip Tracking and Woo Completed before marking the selected fulfillment's manual sync completed, writing child woo_manual_tracking_verified marker and root woo_manual_force_completed marker. Backend list status respects the verified child marker plus completed manual sync; manual reopen still shows pending.
- Subsequent automatic status projection honors the root marker, so sending A does not get reverted to Woo processing when B remains pending. Internal ERP shipment/workflow truth does not change.
- Manual force can change refunded, cancelled, on-hold or failed Woo states: this is explicitly requested and may invoke Woo business hooks/notifications. The system logs actor, order and prior Woo status. Force only supports ONE order per click, never forced batch.

## Tests / verified
- Pending unshipped, no carrier, missing Woo line-id, split A parent identity, refunded override, mismatch/failure feedback, duplicate click, one-order-only and role permission cases: PHP feature targeted.
- Woo HTTP mock: line_items empty, existing package preserved, repeated no PUT, protected override, default protected safety.
- Aggregate marker test: later partial auto shipment cannot undo Woo Completed.
- Isolated actual WordPress Woo 11.2.1 and AfterShip 1.18.5: LOCAL mother order #24, pending child A with no Woo line IDs -> AfterShip package and Completed; repeat remains one package; PHP integration 1 test/11 assertions PASS; native AfterShip customer template shows Tracking and no guessed lines; Cloud is NOT verified.
- 113 targeted feature tests / 1010 assertions PASS, 1 opt-in E2E skipped by default. Isolated npm ci, Vue typecheck and Vite build PASS.
- Full suite additional test initiated; attach real result separately.

## Deployment gates / limitations
- No changes to Production flags or existing half-hour scheduler and no real customer order writes.
- No Woo order exists for arbitrary standalone manual ERP order: report a clear error, never create a fictitious parent.
- Real Dev and protected status UAT / exact-head CI before Production. Confirm customer-order side effects and choose an exact safe Canary. Maintain rollback path. No automatic historical backlog replay.

## Expanded proof and baseline comparison (2026-11-06)
- Fresh LOCAL Woo mother #25: pending A + real carrier USPS, 1 order-level AfterShip package, mother completed, original customer template verified.
- Fresh LOCAL Woo mother #26: pending A with ONLY Tracking, no carrier and no shipped_at. Manual fallback provider OTHER published, mother completed, AfterShip native display PASS.
- Fresh LOCAL Woo mother #27: split children A and B, both pending, different USPS Tracking numbers; manual sends both to the same real root Woo ID, mother Completed, AfterShip native plugin displays exactly TWO preserved packages with zero guessed line bindings, customer template displays both: one real local integration test / 17 assertions PASS.
- Full PHP Feature suite of current GitHub main + V3.1 branch had 2,195 tests / 20,466 assertions; 6 errors, 55 failures, 10 opt-in skipped. These failures are NOT claimed as resolved. An unmodified GitHub main baseline was checked in separate detached worktree on the identical 87 tests covering representative failing domains: both baseline and V3.1 produce exactly 5 errors / 27 failures / 1 skipped, with identical 608 assertions. Therefore those representative failures predate this branch.
- Prior targeted V3.1 feature regression 113 tests / 1,010 assertions PASS, with one intentionally skipped opt-in LOCAL test; current final-run result should be appended separately.
- One-order-only force override for per-row button; normal multi-select/batch remains strict. Force never bypasses user role authorization or invents Woo mother identity.
- Production status: NOT deployed, NOT merged. AfterShip Cloud remains unverified; do not present full rollout as PASS.

## Last safety refinement and acceptance
- On successful manual overwrite, manual_sync.sync_note stores a SHA-256 fingerprint of the exact current Tracking (not the plaintext number). The read-only list projection requires the fingerprint still matches current shipment Tracking plus completed ManualSync and verified marker. If a pending-shipment Tracking is subsequently edited without reopening manual status, the UI displays PENDING with an explanation rather than stale success.
- One-order-only API override is fail-closed (HTTP 422 if >1 IDs). Bulk button retains old shipped+carrier+timestamp requirement and does not send manual_override. Individual row button remains enabled by Tracking alone.
- Final fresh independent LOCAL mother #28: both pending A/B children synced order-level, Woo mother completed, AfterShip native two package records and customer template PASS, repeat no duplicates, 1 test / 17 assertions.
- Baseline subset from unmodified GitHub main: 87 tests / 608 assertions = 5 errors, 27 failures, 1 skipped; same exact result on V3.1 branch. These are legacy cross-module failures and must be tracked separately from this feature.
- No real order or Production flags were touched by V3.1.
