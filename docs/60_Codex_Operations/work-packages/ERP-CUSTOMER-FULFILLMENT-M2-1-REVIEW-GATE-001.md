# ERP-CUSTOMER-FULFILLMENT-M2-1-REVIEW-GATE-001

## Purpose
Freeze and implement the first customer fulfillment handoff gate: Liang records real shipment truth, then Vita must review it before any WooCommerce tracking outbound is eligible.

## Baseline / dependency
- BASELINE: `main@016687a42e3b2dfe2d8a569dd8869fb95d782e2c`
- DEPENDENCIES: existing Logistics Shipment truth, Manual Sync state machine, WooCommerce Tracking Outbound, PR #260 canonical 007 order/grid behavior.
- DEPENDENCY_TYPE: MERGED_PR / merged main foundations only.
- DEPENDENCY_STATUS: SATISFIED.
- PARALLEL_SAFE: YES; no menu/schema/shared-navigation changes.
- EXECUTION_PROVIDER: US_CODEX_CLI.
- VERIFICATION_TIER: DAILY.

## Frozen business flow
`Customer Order → Liang shipment + carrier + tracking → manual_sync.needs_confirmation → Vita review → approve => manual_sync.pending OR return => manual_sync.returned → Liang correction => needs_confirmation again`.

This package does **not** execute the remote Woo write on approval. M2.2 will connect approved `pending` records to the already-existing Tracking Outbound path after Owner UAT of this review gate.

## Frozen implementation rules
1. Reuse `ManualSyncStatus::NeedsConfirmation`; no new table or status enum.
2. For `order_kind=customer`, `ShipmentService::markShipped()` with non-empty tracking and `updateTracking()` must set Manual Sync to `needs_confirmation`, not `pending`.
3. KOL fulfillment behavior is unchanged by this package.
4. Add an explicit ManualSync service transition `needs_confirmation → pending` for Vita approval with audit evidence.
5. Existing return action remains `returned`; correcting tracking on a customer order moves it back to `needs_confirmation`.
6. Woo outbound single/batch actions must not execute an order while Manual Sync is `needs_confirmation` or `returned`. Only `pending` is outbound-eligible.
7. Vita 的日常前端只保留业务动作：查看发货事实、`审核通过`、审核后 `同步到网站`；不展示批量写回、Run、重试、冲突、恢复超时、远端核对等技术调度控件。
8. 前端状态简化为 `待审核`、`已审核 · 待同步`、`已同步`；底层重试/冲突能力继续保留在技术层，不作为 Vita 日常工作流。
9. No role/permission/menu mutation: M1 Role Boundary Freeze is treated as already complete.
10. Preserve canonical `erp_order_id` (`007-...`) display and PR #260 grid controls.

## Explicit exclusions
- No Production deployment.
- No real WooCommerce external write.
- No new notification/inbox work (M4).
- No KOL/Emma email/WordPress account flow (M3).
- No schema/migration.
- No carrier/warehouse/reservation redesign.
- No menu or role preset change.

## Acceptance criteria
- Customer shipment with tracking creates/updates `manual_syncs.website_sync_status=needs_confirmation`.
- Vita approval changes exactly `needs_confirmation → pending` and audits the transition.
- Vita return changes to `returned`; Liang tracking correction returns to `needs_confirmation`.
- Direct single Tracking outbound is blocked before approval.
- Selected/all-pending orchestration cannot bypass review; only `pending` records are eligible.
- Vita 发货审核页只显示 ERP订单号、收件人、物流商、物流单号、状态，以及 `审核通过` / `同步到网站` 两个顺序业务动作。
- Existing KOL/manual-sync regressions remain green.
- Focused PHPUnit/Vitest, typecheck, build and `git diff --check` PASS.
- Owner UAT stops before any Production deployment.

PROJECT POSITION
PROGRAM_ROADMAP: Architecture Foundation [COMPLETE] → Existing Operations [ACTIVE] → Three-role Fulfillment Closure [CURRENT] → Customer Loop M2 → KOL Loop M3 → Role Inbox M4 → Automation M5 → Three-role Real UAT M6 → Production Closure
CURRENT_TRACK_PHASE_MAP: M1 Role Boundary Freeze [COMPLETE] → M2.1 Customer Shipment Review Gate [CURRENT] → M2.2 Approved Tracking Outbound → M2.3 Customer Loop Real UAT → M3 KOL Loop → M4 Role Inbox → M5 Automation → M6 Closure
COMPLETED_PHASES: M1 Role Boundary Freeze; Logistics real-order identity/grid closure PR #260
CURRENT_PHASE: M2 Customer Fulfillment Loop
CURRENT_WORK_PACKAGE: ERP-CUSTOMER-FULFILLMENT-M2-1-REVIEW-GATE-001
PACKAGE_STATUS: READY FOR OWNER UAT
WAITING_PHASES: M2.2; M2.3; M3; M4; M5; M6
BASELINE: main@016687a42e3b2dfe2d8a569dd8869fb95d782e2c
GITHUB_STATE: dedicated branch / Draft PR required
SOURCE_CONFLICTS: NONE WITH EVIDENCE; current main already contains NeedsConfirmation and Tracking Outbound foundations
GLOBAL_GATE: PASS FOR OWNER UAT; Production remains blocked
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: Owner UAT PASS on M2.1
EVIDENCE_AS_OF: 2026-09-08

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 1.25h planning value; 0.8–1.8h range
KNOWN_CURRENT_TRACK_REMAINING: M2.2 1.5–2.5h; M2.3 UAT 1–2h; later M3–M6 tracked separately
ARCHITECTURE_GATE_REMAINING: 0h for M2.1 frozen contract
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE
MVP1_REMAINING: UNKNOWN WITH EVIDENCE
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE
LONG_TERM_CUMULATIVE_RANGE: Three-role fulfillment closure previously estimated 16–26h total
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE
ESTIMATE_CONFIDENCE: HIGH for M2.1
ESTIMATE_AS_OF: 2026-09-08
ESTIMATE_METHOD: existing state-machine/outbound reuse; no schema/menu changes

IMPLEMENTATION EVIDENCE
IMPLEMENTED_FILES: app/Modules/Logistics/Application/ShipmentService.php; app/Modules/Sync/Application/ManualSyncService.php; app/Modules/Sync/Application/WooCommerceTrackingOutboundService.php; app/Modules/Sync/Experience/Controllers/ManualSyncController.php; app/Modules/Sync/Experience/Routes/web.php; resources/js/Pages/Sync/Orders.vue; resources/js/Pages/Sync/Orders.test.ts; dedicated CustomerFulfillmentReviewGateTest; Woo outbound test; architecture route/hash baseline
IMPLEMENTED_BEHAVIOR: customer tracking enters needs_confirmation; approval audits needs_confirmation->pending; outbound requires pending; Vita UI is reduced to shipment facts + approve + sync-to-website, while orchestration/retry/run controls remain hidden from daily operations; KOL path retains ensurePending
VERIFICATION_PASS: post-rebase focused PHPUnit 20/20 (208 assertions); dedicated review-gate + Woo outbound 9/9 (40 assertions); Sync/Orders Vitest 8/8; npm typecheck PASS; Vite production build PASS; PHP lint PASS; git diff --check PASS; no NotificationCenter diff
BASELINE_GOVERNANCE_DEBT: ModuleArchitectureTest is 5/8 on this branch and independently 5/8 on exact origin/main@016687a with the same 3 stale frozen-baseline failures (module directory set, layer file set, migration file set); this package does not mutate those baseline sets. LogisticsOsV1Closure first dispatch test is also independently baseline-failing on main and is outside M2.1 scope.
VERIFICATION_BLOCKED: NONE for M2.1 acceptance; known mainline governance baseline debt recorded separately
OWNER_UAT: NEXT GATE; isolated Local/UAT only, no real Woo external write and no Production deployment
DELIVERY_STATUS: READY_FOR_OWNER_UAT; commit/push required before opening exact-head UAT; not merged; not deployed

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.15h
CORE_EXECUTION: 0.65h
TEST_AND_VALIDATION: 0.30h
REMEDIATION_BUFFER: 0.15h
ESTIMATED_EFFECTIVE_HOURS: 1.25h
ESTIMATE_RANGE: 0.8–1.8h
ESTIMATE_BASIS: bounded cross-module state transition + focused UI/test updates using existing foundations
ACTUAL_EFFECTIVE_HOURS: approximately 1.05h
ACTUAL_BREAKDOWN: preflight/scope 0.15h; implementation 0.40h; focused test authoring/static validation 0.25h; bypass remediation + dependency-safe verification + mainline rebase/evidence 0.25h; external dependency waiting excluded
ACTUAL_VARIANCE: approximately -0.20h versus 1.25h estimate (-16%); implementation and M2.1 acceptance verification complete, Owner UAT excluded from this effective-time total
OWNER_REVIEW: INCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: INCLUDED
DEPLOYMENT: EXCLUDED
