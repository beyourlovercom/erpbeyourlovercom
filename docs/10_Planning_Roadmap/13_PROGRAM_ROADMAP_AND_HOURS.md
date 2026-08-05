---
type: program-roadmap-hours
project: BYL ERP
version: 1.0
updated: 2026-08-05
---

# Program Roadmap and Hours

This document is the repository source of truth for the complete Program Roadmap, estimate classifications, known and unknown remaining hours, actual-hours evidence and no-double-counting rules.

## Program Roadmap

```text
Architecture Migration / Global Architecture Gate
→ MVP1 Manual Fulfillment / Tencent Docs Replacement
→ MVP1.1 WooCommerce Inbound
→ MVP1.2 Single Tracking Outbound
→ MVP1.3 Sync Orchestration
→ Customer / Lead Pilot
→ Product / Media
→ WordPress Product and Image Sync
→ Content / SEO
→ Posts / Pages
→ GA4 / GSC / Cloudflare
→ Google Docs Publishing
→ Full Order Workspace
→ Logistics OS
→ KOL OS and Social Providers
→ Inventory and Procurement
→ Accounting / ROI Expansion
→ Shopify and Additional Integrations
→ Dev UAT and Production Hardening
```

The Program Roadmap is the top-level delivery sequence. It must not be replaced by the current Architecture track or a single Work Package map.

## Current Architecture Track

```text
001A Foundation Bootstrap             MERGED / PASS
001B Authentication / Authorization   MERGED / PASS
001C Application Shell                MERGED / PASS
001D Runtime / Audit / Sync           COMPLETE / PASS
001D-A Runtime Foundation             MERGED / PASS BY PR #9
001D-B Audit Foundation               MERGED / PASS BY PR #12
001D-C Sync Outbox Foundation         MERGED / PASS BY PR #13
→ 001E Shared UI / Browser E2E        COMPLETE / PASS
001E-A Shared UI / Design Tokens      MERGED / PASS BY PR #17
001E-B Browser E2E Foundation         MERGED / PASS / CLOSED
001E-C Auth / Permission / Shell E2E  MERGED / PASS / CLOSED
001F Architecture Re-audit            ACTIVE / PREFLIGHT REVIEWED
001F-A Governance Status Correction   COMPLETE / CLOSED
001F-B Modular Boundary Decision      COMPLETE / REVIEWED / OPTION A1 APPROVED
001F-B1 Module Architecture Contract COMPLETE / PASS / CLOSED (PR #24; closure PR #25)
001F-B2 Module Skeleton & Automated Boundary Enforcement COMPLETE / PASS / CLOSED
001F-B3 Identity & Access Boundary Migration COMPLETE / PASS / CLOSED (Work Package PR #29; Implementation PR #30; Closure PR #31; main closure 0af914ebf8fc9f94b18e63e008fd78739bba6811)
001F-B4 Runtime / Audit / Sync Boundary Migration COMPLETE / PASS / CLOSED
001F-B5 Shell / Dashboard Boundary & Compatibility Cleanup PREFLIGHT COMPLETE / WORK PACKAGE FORMALIZED / IMPLEMENTATION AUTHORIZED
001F-C through 001F-F                 PROPOSED / NOT AUTHORIZED
Global Architecture Gate              FAIL
```

## Source Conflict Snapshot

```text
GITHUB_FACT: PR #24 merged the B1 contract into main at b3ac6268488b9ea96ceebaf7e7dcb23e8b5f2c9f.
GOVERNANCE_CLOSURE_FACT: PR #25 is the B1 governance closure PR.
GOVERNANCE_FACT: 001F-B1 is COMPLETE / PASS / CLOSED; 001F-B2 is COMPLETE / PASS / CLOSED through PR #28; B3 is COMPLETE / PASS / CLOSED through PR #31; B4 is COMPLETE / PASS / CLOSED through PR #34; B5 Work Package is formalized and implementation-authorized; 001F-C-F are not started.
SOURCE_CONFLICTS: NONE WITH EVIDENCE
EVIDENCE_LIMITS: real-MySQL two-connection claim concurrency, live Redis, Queue Worker, Scheduler and Dev Migration remain unverified.
```

## Current Architecture Migration Estimate

```text
001F_B2_TO_B5_KNOWN_ESTIMATE: 4–8 Codex / 20–40h
CURRENT_B2_TO_B5_PLANNING_VALUE: 30h
CURRENT_PACKAGE: ERP-ARCH-MIGRATION-001F-B5 / READ-ONLY PREFLIGHT / WORK PACKAGE
B4_WORK_PACKAGE_PR: #32; main squash 2a445ca6daffda8b79347b663218bf9f7c3e8d1b
B5_CURRENT_DEPENDENCY: independent Work Package review and merge.
001F_C_TO_001F_F_ESTIMATE: UNKNOWN WITH EVIDENCE
TOTAL_ARCHITECTURE_MIGRATION_REMAINING: UNKNOWN WITH EVIDENCE
ACTUAL_HOURS: UNKNOWN WITH EVIDENCE
```

Historical estimate: `001F_B_TO_F_ESTIMATE: SUPERSEDED / NOT CURRENT` and `PLANNING_VALUE: 18h` are retained only as historical evidence.

## Initial Hours Snapshot

| Metric | Value | Classification | Evidence / Qualification |
|---|---:|---|---|
| `001D_TOTAL_ESTIMATE` | 35–54h; planning value 45h | completed issued total estimate | Frozen and completed parent 001D package; not remaining |
| `001E_PARENT_PLANNING_ESTIMATE` | 40–62h; planning value 51h | parent-level original planning estimate | Original allocation across 001E-A/B/C; not remaining |
| `001E_B_CONFIRMED_ESTIMATE` | 12–18h; planning value 15h | confirmed child implementation estimate | Accepted bounded implementation estimate; package merged and closed |
| `001F_B2_TO_B5_KNOWN_ESTIMATE` | 4–8 Codex / 20–40h; planning value 30h | planning estimate only | B2-B5 only |
| `001F_C_TO_001F_F_ESTIMATE` | UNKNOWN WITH EVIDENCE | planning estimate only | not yet estimated |
| `TOTAL_ARCHITECTURE_MIGRATION_REMAINING` | UNKNOWN WITH EVIDENCE | planning estimate only | no completion-time guarantee |
| `ACTUAL_HOURS` | UNKNOWN WITH EVIDENCE | actual-hours status | no fabricated actuals |
| `001F_B2_TO_B5_ESTIMATE` | SUPERSEDED / see `001F_B2_TO_B5_KNOWN_ESTIMATE` | historical planning estimate | excludes 001F-C through 001F-F; current estimate is recorded above |
| `MVP1_TOTAL_ESTIMATE` | 122–190h | total estimate | Effective Hours source; not automatically remaining |
| `MVP1_PLANNING_BASELINE` | 166h | total planning value | Effective Hours source |
| `MVP1_MAXIMUM_ENVELOPE` | 192h | maximum total planning envelope | Living Roadmap and Effective Hours sources |
| `MVP1_REMAINING` | UNKNOWN WITH EVIDENCE | unknown remaining total | Accepted actual-hours ledger and proven architecture/MVP1 overlap map are incomplete |
| `POST_MVP_KNOWN_INCREMENT` | approximately 68–120h beyond MVP1 | incremental estimate | WooCommerce inbound plus single outbound |
| `PROGRAM_REMAINING` | UNKNOWN WITH EVIDENCE | unknown remaining total | Long-term ranges are cumulative; actuals and overlap mapping are incomplete |
| `COMPLETED_ACTUAL_EFFECTIVE_HOURS` | UNKNOWN WITH EVIDENCE | unknown actual effort | Accepted Work Logs do not yet provide a complete project-wide ledger; chat memory is not evidence |

## Long-Term Cumulative Ranges

| Milestone | Cumulative Estimate |
|---|---:|
| Architecture foundation | 235–402h |
| First highly automated operations MVP | 603–1,018h |
| Product/SEO growth layer | 1,059–1,802h |
| Full core ERP | 1,595–2,714h |
| All expansion | 1,819–3,146h |

These are alternative cumulative horizons, not additive components. They must never be summed together or added to an increment already included in the selected horizon.

## Completed Actual-Hours Ledger

| WORK_PACKAGE | STATUS | ESTIMATED_EFFECTIVE_HOURS | ACTUAL_EFFECTIVE_HOURS | SCOPE_CLASSIFICATION | INCLUDED_IN_MVP1_TOTAL | EVIDENCE |
|---|---|---:|---:|---|---|---|
| ERP-ARCH-MIGRATION-001D-A | MERGED / PASS | 12–18h; planning value 15h | UNKNOWN WITH EVIDENCE | architecture component | UNKNOWN WITH EVIDENCE | Accepted Work Log explicitly records no productive actual-hours value |
| ERP-ARCH-MIGRATION-001D-B | MERGED / PASS | 9–14h; planning value 12h | 3h | architecture component | UNKNOWN WITH EVIDENCE | Accepted 001D-B Work Log |
| ERP-ARCH-MIGRATION-001D-C | MERGED / PASS | 14–22h; planning value 18h | 4.8h | architecture component | UNKNOWN WITH EVIDENCE | Accepted 001D-C Work Log through remediation Head |
| ERP-ARCH-MIGRATION-001D | COMPLETE / PASS | 35–54h; planning value 45h | UNKNOWN WITH EVIDENCE | parent aggregate; do not add to component rows | UNKNOWN WITH EVIDENCE | 001D-A missing actual prevents safe parent aggregation |
| ERP-ARCH-MIGRATION-001E-A | MERGED / PASS | 16–24h; planning value 20h | 7.1h cumulative | architecture component | UNKNOWN WITH EVIDENCE | Accepted 001E-A Work Log including Preflight and implementation |
| ERP-LOCAL-ADMIN-BYPASS-001 | MERGED / PASS | 8–12h; planning value 10h | 4.3h implementation | supporting local-only package | NO | Accepted Local Admin Bypass Work Log |
| ERP-SHELL-ACCOUNT-DOCK-001 | MERGED / PASS | 5–8h; planning value 6.5h | 4.8h | supporting App Shell package | UNKNOWN WITH EVIDENCE | Accepted Account Dock Work Log; Owner visual acceptance PASS |
| ERP-MENU-BASELINE-001 | MERGED / PASS | 3–5h; planning value 4h | 3.2h | governance baseline | NO | Accepted Menu Baseline Work Log |
| Project-wide accepted ledger | INCOMPLETE | UNKNOWN WITH EVIDENCE | UNKNOWN WITH EVIDENCE | actual-effort ledger | UNKNOWN WITH EVIDENCE | Historical accepted packages and architecture/MVP1 overlap classification remain incomplete |

Future rows may be added only from accepted repository Work Logs. PR elapsed time, commit timestamps, token use and chat-only values are prohibited as actual-hours evidence.

## Calculation Rules

- identify every value as total, remaining, incremental, cumulative, planning value, range or actual effort;
- a total estimate is not a remaining estimate;
- calculate remaining effort only from accepted actuals and a documented non-overlapping scope map;
- never convert an unknown into zero;
- use `UNKNOWN WITH EVIDENCE` with checked sources, missing data and the unsafe operation explained;
- do not sum cumulative ranges;
- do not add an increment to a cumulative value when the increment is already included;
- the current package estimate must agree with its final `ESTIMATED CODEX HOURS` module.

## Estimate Confidence

```text
HIGH: quoted repository total and cumulative ranges; issued 001D estimate
MEDIUM: parent 001E-A/B/C planning allocation and bounded 001E-A Preflight estimate
LOW / UNKNOWN: project and Architecture Gate remaining totals because 001F and actual-ledger overlap remain incomplete
ESTIMATE_AS_OF: 2026-08-01
ESTIMATE_METHOD: repository source classification; no subtraction without accepted actuals; no addition of cumulative or overlapping ranges
```
