# ERP-CUSTOMER-FULFILLMENT-M2-1-REVIEW-GATE-001

## Purpose
Freeze and implement the first customer fulfillment handoff gate: Liang records real shipment truth, then Vita must review it before any WooCommerce tracking outbound is eligible.

## Baseline / dependency
- BASELINE: `main@efd0924ae2a2c0b6c01d838f4b8c151214d90104`
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
7. Sync Orders UI uses Chinese business labels: `待运营审核`, `待同步正式站`, `已完成`, `已退回`, `失败`.
8. A `needs_confirmation` row exposes `审核通过` and `退回修改`; it must not expose Tracking write actions before approval.
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
- Sync Orders UI clearly differentiates review versus outbound states and actions.
- Existing KOL/manual-sync regressions remain green.
- Focused PHPUnit/Vitest, typecheck, build and `git diff --check` PASS.
- Owner UAT stops before any Production deployment.

PROJECT POSITION
PROGRAM_ROADMAP: Architecture Foundation [COMPLETE] → Existing Operations [ACTIVE] → Three-role Fulfillment Closure [CURRENT] → Customer Loop M2 → KOL Loop M3 → Role Inbox M4 → Automation M5 → Three-role Real UAT M6 → Production Closure
CURRENT_TRACK_PHASE_MAP: M1 Role Boundary Freeze [COMPLETE] → M2.1 Customer Shipment Review Gate [CURRENT] → M2.2 Approved Tracking Outbound → M2.3 Customer Loop Real UAT → M3 KOL Loop → M4 Role Inbox → M5 Automation → M6 Closure
COMPLETED_PHASES: M1 Role Boundary Freeze; Logistics real-order identity/grid closure PR #260
CURRENT_PHASE: M2 Customer Fulfillment Loop
CURRENT_WORK_PACKAGE: ERP-CUSTOMER-FULFILLMENT-M2-1-REVIEW-GATE-001
PACKAGE_STATUS: IMPLEMENTATION AUTHORIZED
WAITING_PHASES: M2.2; M2.3; M3; M4; M5; M6
BASELINE: main@efd0924ae2a2c0b6c01d838f4b8c151214d90104
GITHUB_STATE: dedicated branch / Draft PR required
SOURCE_CONFLICTS: NONE WITH EVIDENCE; current main already contains NeedsConfirmation and Tracking Outbound foundations
GLOBAL_GATE: PASS FOR BOUNDED LOCAL IMPLEMENTATION
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

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.15h
CORE_EXECUTION: 0.65h
TEST_AND_VALIDATION: 0.30h
REMEDIATION_BUFFER: 0.15h
ESTIMATED_EFFECTIVE_HOURS: 1.25h
ESTIMATE_RANGE: 0.8–1.8h
ESTIMATE_BASIS: bounded cross-module state transition + focused UI/test updates using existing foundations
OWNER_REVIEW: INCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: INCLUDED
DEPLOYMENT: EXCLUDED
