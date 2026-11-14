# ERP Tracking Outbox 旧任务兼容 P0 · 2026-11-14

WORK_PACKAGE: ERP-SYNC-OUTBOX-LEGACY-P0-20261114
EXECUTION_PROVIDER: GPT_WEB
VERIFICATION_TIER: HIGH_RISK
SOURCE_OF_TRUTH: beyourlovercom/erpbeyourlovercom@main
ENV: isolated US Mac worktree; Production is a separate release gate

## Confirmed incident
- ERP #8003, Woo #42292, shipment #2043, Outbox #75, Tracking 4PX3003376508788CN.
- Error 5edf75f46771b87c7d58c28a01a8a7dd: HTTP 500 POST /sync/orders/8003/tracking-outbound.
- Outbox #75 has the same fingerprint as the current intent but lacks shipped_at_contract.
- Actual Woo readback at diagnosis: processing; Tracking empty.
- Earlier timestamp model rendered 02:29 UTC while canonical MySQL UTC is 10:29 UTC, so the automatic runner deliberately holds the old task.
- Read-only audit classified 32 active tracking jobs at query time: 30 legacy_timezone_hold, 1 matching_current, 1 requires manual review. No backlog bulk write authorized.

## Approved bounded remediation
1. Permit exact additive shipped_at_contract metadata compatibility only on explicit manual Tracking synchronization.
2. Keep all immutable identity, connection, aggregate, payload, attempt, timestamp and remote-status guards; never edit or delete historical Outbox payload.
3. Before manual operation inspect remote order status; fail closed for unknown/cancelled/refunded/failed/on-hold/trash.
4. Return actionable Chinese UI error rather than unhandled HTTP 500 for genuine outbox conflicts or gateway exceptions.
5. Keep scheduled paths strict; no automatic release of legacy timezone holds, no bulk replay or remote writes during tests.
6. No changes to ERP address, SKU, line item identity, manually overridden values, or 30 workspace tabs.

## Tests and release gates
- PHPUnit: WooCommerceTrackingOutboundTest, SyncOutboxSchemaContractTest, SyncOrchestrationServiceTest, TrackingV2LocalWooE2eTest, TrackingAutoLocalWooCanaryTest, SyncOrdersAllOrdersOverviewTest.
- Regression: old contract only -> manual same Outbox write & verification; periodic strict -> no write; genuine payload conflict -> clear error; refunded remote -> no write.
- Run PHP syntax, Pint and git diff --check; CI required before merge.
- Production release separately gated by Owner PASS and frozen 30-tabs build verification.
- Before Production switch: record exact SHA, backup DB/current symlink, build and verify tabs. Then read back HTTP route and Outbox identity.
- For order #8003 after release: re-read ERP shipment/current Woo status+Tracking and ensure no user/manual changes; run ONE authorized manual synchronization; verify Woo Tracking, provider, shipping status and Outbox state, with rollback route.
- Do not reprocess 30 historical holds in bulk; keep read-only audit and investigate individually.
