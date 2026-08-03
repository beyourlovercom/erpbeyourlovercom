---
type: current-context
project: BYL ERP
updated: 2026-08-03
status: architecture-migration-active
---

# Current Context

## Current execution

```text
ERP-ARCH-MIGRATION-001E
Title: Shared UI / Browser E2E
Status: COMPLETE / PASS / 001F ACTIVE / PREFLIGHT REVIEWED
001E authorization: historical implementation completed; no new 001E authorization item
Deployment: NO

ERP-ARCH-MIGRATION-001E-A
Title: Shared UI and Design Tokens
Status: MERGED / PASS
PR: #17
Accepted Head: e46fb54a1b41a1efa620b4af3646e104ff7bcbbc
Main Squash Commit: 442a3a872628764fecb7209049d2daf752624e9a

ERP-ARCH-MIGRATION-001E-B
Title: Browser E2E Foundation
Status: MERGED / PASS / COMPLETE / CLOSED
PR: #21
Accepted Head: 3b67586cafd2b4af3e248e3079719cb73b4e4163
Main Squash Commit: 4b9fbb0deacdfc02439fb947398b87c148602c75
Dependency: @playwright/test@1.62.1; Chromium Headless Shell
Evidence: local Chromium only; GitHub Actions Browser E2E not implemented or run

ERP-LOCAL-ADMIN-BYPASS-001
Status: MERGED / PASS
PR: #16
Accepted Head: 6b63229b7d776104a5736334de639d9b20fb69f3
Main Squash Commit: 55ba7320a7046deb04a03abd99053ccd7b7ed11e

ERP-SHELL-ACCOUNT-DOCK-001
Status: MERGED / PASS
PR: #15
Accepted Head: 0cac340443d47591b7e242ebe051fb4407d0a986
Main Squash Commit: 32d7244b9b1c6d1fb0cf8863cb9af694e7acc10f
Owner Visual Acceptance: PASS

ERP-MENU-BASELINE-001
Status: MERGED / PASS
PR: #14
Accepted Head: 6d5595e879778895105215dc98b1f740521b6f1a
Main Squash Commit: 493f32ce7bf1be4368a7e9f97de9ecf9f9c9c9d8

Previous package:
ERP-ARCH-MIGRATION-001D Runtime / Audit / Sync Foundation
Status: COMPLETE / PASS

ERP-ARCH-MIGRATION-001D-A
Status: MERGED / PASS
PR: #9
Accepted Head: 3d8f92b701370db41bed0df707183207f6924dd5
Main Squash Commit: 50803c3fcd51e9f8b3485f4fb57565154a284bf7

ERP-ARCH-MIGRATION-001D-B
Status: MERGED / PASS
PR: #12
Accepted Head: 953d1b07d48f975a5f1098069927ae9a680ebc7d
Main Squash Commit: e2247fcaaae690c94ef07d45c8807ce749de1b46

ERP-ARCH-MIGRATION-001D-C
Status: MERGED / PASS
PR: #13
Accepted Head: e4bc57b7a3f0edbe84ccfde63d02d9135a4875e3
Main Squash Commit: 548d53f1eae2ec14eea7261d3a101c1f2fda0dcc

LIVE_REDIS_PROBE: UNAVAILABLE / NOT CLAIMED
REAL_MYSQL_TWO_CONNECTION_CLAIM_CONCURRENCY: UNVERIFIED / GLOBAL GATE BLOCKER
MYSQL_SUPPORTED_BASELINE: MySQL 8.x
MYSQL_8_VALIDATED_REFERENCE: MySQL Community 8.4.11
MYSQL_5_7: OUT OF SCOPE / NOT SUPPORTED BASELINE / NOT A GLOBAL GATE BLOCKER
DEV_MIGRATION: UNVERIFIED / GLOBAL GATE AND MVP1 INTEGRATION BLOCKER
STAGING_MIGRATION: UNVERIFIED / UAT BLOCKER
PRODUCTION_MIGRATION_PLAN_AND_REHEARSAL: UNVERIFIED / DEPLOYMENT BLOCKER
PRODUCTION_REAL_MIGRATION: NOT RUN / NOT AUTHORIZED / NOT A GLOBAL GATE OR MVP1 START PREREQUISITE
```

## Current repository baseline

```text
001A Foundation Bootstrap = MERGED / PASS
Repository Governance = PASS
001B Authentication / Authorization = MERGED / PASS
001C Application Shell = MERGED / PASS
001D Runtime / Audit / Sync Foundation = COMPLETE / PASS
001D-A Runtime Foundation = MERGED / PASS
001D-B Audit Foundation = MERGED / PASS
001D-C Sync Outbox Foundation = MERGED / PASS
001E Shared UI / Browser E2E = COMPLETE / PASS
001E-A Shared UI / Design Tokens = MERGED / PASS
001E-B Browser E2E Foundation = MERGED / PASS / CLOSED
001E-C Authentication / Permission / App Shell E2E = MERGED / PASS / CLOSED
001F Architecture Re-audit = ACTIVE / PREFLIGHT REVIEWED
001F-A Governance Status Correction & Gate Scope Freeze = IMPLEMENTED / PENDING INDEPENDENT REVIEW
001F-B Modular Boundary Decision / Remediation = PROPOSED / NOT AUTHORIZED
Global Architecture Gate = FAIL
Production Deployment = NO
```

## Immediate sequence

```text
1. Independently review the 001F-A governance correction.
2. Resolve the modular boundary, MySQL concurrency, Redis/Queue/Scheduler and Dev Migration gates in separate authorized packages.
3. Complete the final 001F re-audit before declaring the Global Architecture Gate passed.
4. Keep MVP1, UAT and deployment blocked until their explicit gates.
```

## Product direction

The complete architecture is retained.

The immediate delivery target is a one-month MVP1 that replaces the Tencent Docs manual fulfillment workflow while using forward-compatible core models.

## MVP1 principle

```text
Functionality is incremental.
Architecture is not temporary.
Manual workflows remain permanent fallback paths.
```
