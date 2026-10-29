# XLWMS US-SM R5–R8 gated release evidence — 2026-10-29

Scope: Draft PR #535, `feature/xlwms-warehouse-sync-s1s4-20261028`. No Production write/deploy/OMS outbound in this branch. China Mac is now READY and available for read-only operations, but its old ERP repo at `/Users/a/AI/007/erpbeyourlovercom` is NOT the approved code/deploy base; its local MySQL access failed. US Mac mini is the canonical isolated current PR worktree.

## R5 Real OMS read-only — BLOCKED on authorized connection
- `https://erp.beyourlover.com/login` verified HTTP 200, TLS verified. Public reachability is not sufficient for application DB/OMS access.
- Existing encrypted US-SM OMS credentials were **not** available in the approved isolated worktree or reachable China Mac runtime. The China Mac old repo is not an approved Production runtime.
- Source/schema of `outboundOrder/detail` reviewed from XLWMS vendor documentation; SKU/quantity contract is tested with fake HTTP, **not** actual warehouse results. No live provider API call was made.
- Preferred exact verification on an AUTHORIZED deployed ERP runtime, after Owner approves a flags-OFF release: `php artisan xlwms:configure-inventory-api --check`, then `php artisan xlwms:verify-outbound-readonly 007-1027-c41863` and `... 007-1027-c41845B`. Do NOT copy credentials or access raw customer details into chat, git, shell history or test fixtures. A NOT_FOUND result **never** authorizes create. If the Prod runtime is unavailable, stop rather than claim R5 passed.

## R6 Dev / Dede Chrome UAT — AUTOMATED PASS, Owner visual PASS pending
- 2026-10-29 US Mac mini; new disposable Laravel APP_ENV=testing, local SQLite and synthetic Dede employee + 2 synthetic approved US-SM shipments.
- Real headless Google Chrome verified Dede login, original `/logistics/oversea/us-3sm?view=pending` desktop page, top-right 同步仓库 button, two rows, per-order blocked outcomes with no bound OMS warehouse, and zero browser JavaScript errors. Synthetic database confirmed `xlwms_dispatch_attempts=2`, both `batch_blocked`, `shipped_test_orders=0`.
- Later PHP regression proves safe locally-blocked attempts may be re-claimed while preserving unique claims; unconfirmed/create-in-flight and identity-conflicting attempts never automatically retry.
- All disposable server, SQLite database and test credentials removed. No real employee or Production data was used. This is automated Dev UAT, not personal Owner acceptance.

## R7 Release preparation — TECHNICAL PREFLIGHT PASS, Production deployment NOT APPROVED
- PR #535 is a Draft; current `origin/main` base `ff11378f381f7a039c0ea06ea6c429bf396ec938`, no new upstream differences at 2026-10-29 check. CI must re-pass on the final head.
- Three additive, reversible schema migrations: `2026_10_23_010000_create_xlwms_dispatch_attempts_table`, `2026_10_26_120000_add_xlwms_dispatch_feedback_fields`, `2026_10_26_180000_add_xlwms_readonly_order_status`.
- Isolated SQLite executed all three `down` in reverse order, then all three `up` in forward order with exit=0. **This does not verify Production MySQL backup/restore compatibility.** Never run down migrations against live fulfilled shipments or unknown Production data. Application rollback should preserve the new state tables/columns by default; destructive DB restore/drop requires separate authorization.
- OFF by default: `XLWMS_LIVE_DISPATCH_ENABLED=false`; `XLWMS_WAREHOUSE_BATCH_DISPATCH_ENABLED=false`; `XLWMS_LIVE_ORDER_ID=0`. No production cron/automation added. Existing OMS read-back may mark ERP shipped, so even read-only UX POST is not inherently write-free; use ONLY dedicated `verify-outbound-readonly` command during R5.
- Before any Production deployment: Owner separate PASS for merge and Production release; verify current Production deployed SHA and latest main; backup Production MySQL and record restore test, migration status, exact build artifact, app/queue restart procedure, Production settings and rollback, permission matrix, existing shipping/accounting compatibility; release with both create flags OFF; health/readback without shipment status updates; require Owner visual UAT.

## R8 Real order Canary — NOT RUN / independent Owner approval required
1. R5 real OMS identity/duplicate/quantity/status checks for `007-1027-c41863` and `007-1027-c41845B`; verify approved US-SM allocation and Product OS verified alias, recent applied V2 inventory, native carrier/channel, no ambiguous previous attempt.
2. After release PASS and verification, request Owner **one-order live outbound PASS** for `007-1027-c41863`, with exact order ID and one-shot flag/time window. Only this order may be created; verify OMS outbound number and quantity, then ERP state; never auto-complete Woo prematurely. If ambiguous response, stop and use read-only reconciliation; never blind retry.
3. Only after Canary #1 evidence and another Owner PASS, do `007-1027-c41845B`. Keep flags OFF outside each approved window, and preserve Vita's Woo sync responsibility.

## Additional correctness fixes at R7 review
- `WarehouseBatchDispatch`: if an OMS match has no native outbound ID, treat as `batch_identity_blocked` rather than falling through to provider create, even when flags ON.
- Re-claim is only permitted for durable, clearly pre-create `batch_blocked` with reason `blocked`, `create_disabled`, or `preflight_blocked`, absent OMS order ID and same warehouse+thirdOrderNo. Uncertain/created/identity-conflicting states remain non-retryable. Row lock and single-order uniqueness retained.
- Scope regression: 618 PHP assertions passing (77 existing local warnings); Vue 14/14 and TS/build previously pass; re-run CI on the new head before release.

**Overall status:** R5 BLOCKED (real provider), R6 AUTOMATED PASS (Owner signoff pending), R7 PREPARED (Production approval/backup/readback pending), R8 BLOCKED (real Canary approvals). Do not mark whole branch CLOSED.
