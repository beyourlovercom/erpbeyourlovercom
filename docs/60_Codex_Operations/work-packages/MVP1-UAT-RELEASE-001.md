---
type: work-package
project: BYL ERP
work_package_id: MVP1-UAT-RELEASE-001
title: Local MySQL UAT release readiness
status: complete-pass-closed
updated: 2026-08-05
---

# MVP1-UAT-RELEASE-001

```text
OWNER_CONTINUOUS_AUTHORIZATION_ID: BYL-ERP-001F-TO-MVP1-LOCAL-UAT-20260805
TARGET: http://erp.b / local MySQL 8.4.11 / local Redis
MENU_IMPACT: NO
APPROVED_MENU_CHECK: PASS
REMOTE_DEV_ACCESS: NO
PRODUCTION_ACCESS: NO
HUMAN_UAT: NOT STARTED
```

## Evidence

```text
MYSQL: PASS / 8.4.11 / byl_erp_local_uat / 127.0.0.1:13306 / repository-external disposable data directory
MIGRATION: PASS / fresh migrate / 11 migrations
SEED: PASS / 3 users / 7 orders / 3 manual sync rows
BACKUP: PASS / .codex/backups/byl-erp-local-uat-final.sql / SHA-256 ffed2ea8353455b3d826a5985eb3faab38d7c809240107c21b126f6c04fef3e2
RESTORE: PASS / independent byl_erp_local_uat_restore / counts preserved
REDIS: PASS / PONG on 127.0.0.1:16379
PHPUNIT: PASS / 157 passed / 2 skipped / 1 pre-existing risky
VITEST: PASS / 71 passed
TYPECHECK: PASS
BUILD: PASS
BROWSER_E2E: PASS / 16 of 16 / 0 external requests / cleanup PASS
MENU_ROUTE_PERMISSION: PASS / architecture and feature tests
SECRET_SCAN: PASS / no tracked credentials, dump or browser state
LOCAL_SMOKE: PASS / Host erp.b returns 200
```

```text
PHP_CLI: PASS / PHP 8.3.32 / redis 6.3.0 / class_exists Redis YES
REDIS_RUNTIME: PASS / TCP and PING on 127.0.0.1:16379
QUEUE_RUNTIME: PASS / queue:work --once --tries=1 --timeout=5 / exit 0
SCHEDULER_RUNTIME: PASS / schedule:list / exit 0
E2E: PASS / 16 of 16 / disposable external runtime cleanup PASS
STATUS: COMPLETE / PASS / CLOSED
HUMAN_UAT_STATUS: NOT STARTED
NEXT_REQUIRED_ACTION: Owner performs local human UAT
```

## PROJECT POSITION

```text
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate → MVP1 Manual Fulfillment / Tencent Docs Replacement → MVP1.1 WooCommerce Inbound → MVP1.2 Single Tracking Outbound → MVP1.3 Sync Orchestration → Customer / Lead Pilot → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion → Shopify and Additional Integrations → Dev UAT and Production Hardening
CURRENT_TRACK_PHASE_MAP: 001F CLOSED → MVP1 preflight CLOSED → Foundation CLOSED → Order Core CLOSED → Fulfillment CLOSED → Manual Sync CLOSED → Finance Analysis CLOSED → UAT Release blocked before ready
COMPLETED_PHASES: 001F; MVP1 preflight; Foundation; Order Core; Fulfillment; Manual Sync; Finance Analysis
CURRENT_PHASE: MVP1 UAT release
CURRENT_WORK_PACKAGE: MVP1-UAT-RELEASE-001
PACKAGE_STATUS: COMPLETE / PASS / CLOSED
WAITING_PHASES: Owner local human UAT
BASELINE: main / e8c6f7e3fe916671ecff265df4bd94b067ccd601
GITHUB_STATE: no open execution PR
SOURCE_CONFLICTS: NONE WITH EVIDENCE
GLOBAL_GATE: PASS
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: Owner local human UAT
EVIDENCE_AS_OF: 2026-08-05
```

## ROADMAP HOURS

```text
CURRENT_PACKAGE_ESTIMATE: 12–20h; planning value 16h
KNOWN_CURRENT_TRACK_REMAINING: runtime rerun and UAT readiness closure
ARCHITECTURE_GATE_REMAINING: 0h / complete
MVP1_TOTAL_ESTIMATE: 122–190h
MVP1_PLANNING_BASELINE: 166h
MVP1_MAXIMUM_ENVELOPE: 192h
MVP1_REMAINING: UNKNOWN WITH EVIDENCE
POST_MVP_KNOWN_INCREMENT: approximately 68–120h
LONG_TERM_CUMULATIVE_RANGE: 235–402h; 603–1,018h; 1,059–1,802h; 1,595–2,714h; 1,819–3,146h
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: 18.5h for recorded MVP1 packages
ESTIMATE_CONFIDENCE: MEDIUM
ESTIMATE_AS_OF: 2026-08-05
ESTIMATE_METHOD: UAT contract and verified local evidence
```

## ESTIMATED CODEX HOURS

```text
PREFLIGHT_AND_SCOPE: 3h
CORE_EXECUTION: 7h
TEST_AND_VALIDATION: 4h
REMEDIATION_BUFFER: 2h
ESTIMATED_EFFECTIVE_HOURS: 16h
ESTIMATE_RANGE: 12–20h
ESTIMATE_BASIS: 3 + 7 + 4 + 2 = 16h issued package estimate
ACTUAL_EFFECTIVE_HOURS: 2.5h
ACTUAL_HOURS_BREAKDOWN: local MySQL/seed/backup/restore 1.25h; regression/E2E 0.75h; governance 0.5h
VARIANCE: -13.5h / -84.4%
VARIANCE_REASON: application and UAT dataset were already implemented; blocked only on PHP runtime extension evidence
OWNER_REVIEW: INCLUDED
MERGE: INCLUDED
MIGRATION: INCLUDED
MIGRATION_FILE_AUTHORING: INHERITED
MIGRATION_EXECUTION: INCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```
