---
type: byl-erp-work-package
project: BYL ERP
work_package: ERP-CHANGELOG-READONLY-V1-001
status: implementation-authorized
standard_id: LARGE-PROJECT-EXEC-001
standard_version: 1.4
---

# ERP-CHANGELOG-READONLY-V1-001

## Authority

```text
OWNER_AUTHORIZATION: EXPLICIT / 2026-09-07
AUTHORIZED_BASE: 39a76a461b4e343c2a9152be1ade866cf19e6442
BRANCH: feature/erp-changelog-readonly-v1-001
WORKTREE: /Users/a/AI/007/worktrees/erp-changelog-readonly-v1-001
EXECUTION_PROVIDER: GPT_WEB
EXECUTION_SURFACE: US_MAC / REMOTE_DESKTOP_COMMANDER
VERIFICATION_TIER: DAILY
MERGE: NO
DEPLOYMENT: NO
PRODUCTION_MUTATION: NO
```

## Frozen Scope

Replace the current database-backed changelog workspace at `/settings/changelog` with the smallest read-only implementation:

1. One canonical Markdown file for all changelog entries.
2. Entries separated by `## YYYY-MM-DD` headings.
3. One Inertia page: date navigation on the left, rendered Markdown on the right.
4. Date switching is Vue local state only: no request, no redirect, no page refresh.
5. Optional `?date=YYYY-MM-DD` URL state uses browser History API only.
6. Remove changelog create/edit/publish/update routes so the browser surface is read-only.
7. Seed 2026-09-07 from merged `origin/main` work completed that day.

## Prohibitions

```text
DATABASE_SCHEMA_CHANGE: FORBIDDEN
NEW_CHANGELOG_TABLE: FORBIDDEN
CHANGELOG_CMS: FORBIDDEN
AUTO_RECORD_PIPELINE: FORBIDDEN
PRODUCTION_DEPLOYMENT: FORBIDDEN
UNRELATED_GUIDES_OR_SETTINGS_REFACTOR: FORBIDDEN
MENU_CHANGE: FORBIDDEN
```

## Acceptance / Verification

```text
GET_/settings/changelog: PASS
SINGLE_MARKDOWN_SOURCE: PASS
DEFAULT_LATEST_DATE: PASS
LEFT_DATE_NAVIGATION: PASS
ZERO_REFRESH_SWITCHING: PASS
RIGHT_MARKDOWN_RENDER: PASS
CREATE_EDIT_PUBLISH_UI: ABSENT
CHANGELOG_WRITE_ROUTES: ABSENT
TYPECHECK: PASS
FOCUSED_VITEST: PASS
FOCUSED_PHP_TEST: PASS
BUILD: PASS
GIT_DIFF_CHECK: PASS
```

## Verification Evidence — 2026-09-07

```text
CHANGELOG_ROUTE_COUNT: 1 / GET-only
FOCUSED_PHP: PASS / 6 tests / 103 assertions
FOCUSED_VITEST: PASS / 2 files / 2 tests
TYPECHECK: PASS
BUILD: PASS
GIT_DIFF_CHECK: PASS
CHANGELOG_WRITE_ROUTES: ABSENT
PRODUCTION_DEPLOYMENT: NOT AUTHORIZED / NOT PERFORMED
```

PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate → MVP1 Existing Operations → Inquiry → Remote milestone → Woo inbound → Tracking outbound → Sync orchestration → Product/Media → Content/SEO → Full Order Workspace → Logistics OS → KOL OS → Inventory/Procurement → Accounting/ROI → Production Hardening/Expansion
CURRENT_TRACK_PHASE_MAP: Settings utility / read-only changelog simplification
COMPLETED_PHASES: existing DB-backed changelog exists on baseline
CURRENT_PHASE: Owner UAT passed / merge gate
CURRENT_WORK_PACKAGE: ERP-CHANGELOG-READONLY-V1-001
PACKAGE_STATUS: OWNER_UAT_PASS / MERGE_GATE_READY
WAITING_PHASES: Owner UAT → Owner merge authorization → optional later production release
BASELINE: origin/main@39a76a461b4e343c2a9152be1ade866cf19e6442
GITHUB_STATE: PR #250 OPEN / Owner UAT passed / ready for Owner merge authorization; head d8c6786b2212812ff01df37c0b0757292e0080e7
SOURCE_CONFLICTS: AI/CURRENT_CONTEXT.md is dated 2026-08-29 and stale for current feature sequencing; bounded scope relies on Owner instruction plus exact origin/main and does not rebaseline program status
GLOBAL_GATE: one WP / one branch / one worktree / one PR; no merge or deploy authorization
PRODUCTION_DEPLOYMENT_STATUS: NO; this package is local/GitHub only
NEXT_DEPENDENCY: Owner merge authorization for PR #250; Production deployment remains separately gated
EVIDENCE_AS_OF: 2026-09-07

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 1.0h planning value / 0.6–1.5h range
KNOWN_CURRENT_TRACK_REMAINING: this bounded package only; other tracks excluded
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE; not evaluated by this bounded package
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE; not recalculated by this bounded package
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE; not recalculated by this bounded package
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE; not recalculated by this bounded package
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; no accepted global actual ledger reconciled here
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE; outside scope
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE; outside scope
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE; outside scope
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; accepted global work-log ledger not reconciled here
ESTIMATE_CONFIDENCE: HIGH for this bounded package
ESTIMATE_AS_OF: 2026-09-07
ESTIMATE_METHOD: direct inspection of existing Guides release-note controller/routes/Vue surface on exact origin/main

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.1–0.3h; planning value 0.2h
CORE_EXECUTION: 0.25–0.6h; planning value 0.4h
TEST_AND_VALIDATION: 0.15–0.4h; planning value 0.25h
REMEDIATION_BUFFER: 0.1–0.2h; planning value 0.15h
ESTIMATED_EFFECTIVE_HOURS: 1.0h
ESTIMATE_RANGE: 0.6–1.5 effective hours
ESTIMATE_BASIS: reuse existing Markdown renderer and existing changelog route/page; no schema or menu work
OWNER_REVIEW: INCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
UAT: INCLUDED / local only
DEPLOYMENT: EXCLUDED
