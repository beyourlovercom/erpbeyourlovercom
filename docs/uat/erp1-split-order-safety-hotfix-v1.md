# ERP1 Split Order Safety Hotfix V1

Scope: ERP1 only; no ERP2 change. Candidate, not production release.

Root cause: warehouse bulk return previously reset both selected original fulfillment child and source parent, and selecting the parent reset the family. Manual workflow labels could override shipment facts. A source parent with live original children is a rollup, not an executable shipment. Return/reject a child independently. Whole-family reallocation is allowed only via dedicated guarded reset, before any dispatch evidence. Preserve non-split explicit reissue workflows and deliberate family holds.

Production read-only preflight: 36 split roots, two stale pending-allocation roots, eight shipped children with stale sending labels. Two root allocation records are rejected; do not auto-restore them. No production mutation. Repair requires verified backup, exact affected IDs and revisions, audit/history, and explicit production gate; preserve tracking, shipment facts, provider attempts, stock reservations and financial/source records. Never push again merely because of a stale workflow label.

UAT: reproduce A ready, B stockout, C shipped with tracking. Restock B; only B changes; parent remains partially shipped, C stays shipped, A unchanged. Return B to allocation when no dispatch evidence; only B becomes an independent exception for reassignment. Verify parent cannot be selected for allocation, review, export or shipment. Verify C and a previously submitted warehouse child cannot be force-returned, exported as pending, or re-pushed. Confirm ordinary non-split flows still work and dedicated reissue remains separately controlled. Use synthetic data, no production orders for mutation tests.

Verification: 98 focused PHP tests/1231 assertions; additional actual overseas restock endpoint regression 1 test/13 assertions; 125 Vue tests, typecheck and production bundle pass. Full PHP release gate not green; baseline failures independently reproduced on the original main. Do not merge or release until regression results are reconciled and required UAT/release authorization is recorded.
