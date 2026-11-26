# ERP Global SKU Isolation V2 — P0 remediation work package (2026-11-26)

Owner-approved scope: R0–R6, ERP_GLOBAL_SKU_ISOLATION_P0 (AGENTS.md).
EXECUTION_PROVIDER: GPT_WEB. VERIFICATION_TIER: MILESTONE_RELEASE.
Source of truth: GitHub main after PR #865, commit 16d3ac9a. Separate worktree; no production credentials, data writes or real outbound requests.

## R0–R2 source audit and remediation

- DE/Hannover: new physical authority service previously aborted all inventory writes for an unrelated unverified SKU; its coverage reader separately returned an empty warehouse-wide map. Fix both. Snapshot-wide integrity (complete rows, valid signed source quantities, freshness for outbound) is distinct from SKU-local identity, duplicate product mapping, deleted stock and readback drift. Store exact applied product IDs and quarantine evidence in snapshot summary without falsely marking FIFO reconciled. Old physical proofs without per-SKU IDs cannot authorize dispatch; obtain a new signed read and guarded apply after release.
- DE allocation availability and reservations now use the same proven SKU map when the existing DE authoritative auto-apply mode is enabled. Keep provider physical stock authoritative on reservation commit; preserve reservation history and other warehouses.
- US-SM: existing V2 partial apply/proof and manual fulfillment operating contract retained. Source integrity / initial approved checksum / unknown-age and duplicate rules remain intact.
- US-LA MorePlus: stock mirror skips identity-blocked rows; exact outbound preflight uses fresh signed stock and duplicate guard, batch worker isolates order failures. Absence-to-zero is deliberately not allowed if an unresolved provider identity makes absence unsafe. Keep its existing transaction consistency checks.
- US-NY, CA and other manual warehouses: existing per-product row contract retained; no new provider assumptions. US-AMZ remains manual only. No independent ERP2 business engine.
- Order preflight: sum required quantities of repeated product lines; a multi-item order must pass atomically. Worker persists allowlisted machine failure codes without exposing raw exceptions. Retain all create authorization and duplicate protection.
- No migrations, no new SKU exclusions, no changes to UI or provider payload formats.

## R3–R4 CI and verification

Always-on pull-request workflow ERP Global SKU Isolation P0 runs isolated SQLite PHP feature tests and frontend build, including actual DE batch worker with mocked signed stock and create/readback, US-SM, MorePlus, manual availability and reservation regression. This workflow is designed to fail on regression; GitHub branch-protection required-check enforcement must be confirmed separately, not assumed.

## R5–R6 release boundary

Production requires separately approved controlled release with backup, exact SHA, current runtime and rollback check. Refresh signed DE stock and verify per-SKU proof, then check exact OMS references (including manual references) before any new real dispatch for 8198 / 8336. Confirm actual OMS readback; do not mark shipped solely from creation. No release or real order writes are authorized by this work package alone.
