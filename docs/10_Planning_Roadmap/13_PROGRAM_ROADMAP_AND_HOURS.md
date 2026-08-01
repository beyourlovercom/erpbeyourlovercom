---
type: program-roadmap-hours
project: BYL ERP
version: 1.0
updated: 2026-08-01
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
→ 001E Shared UI / Browser E2E        NEXT / PREPARED / IMPLEMENTATION NOT STARTED
001F Architecture Re-audit            WAITING
Global Architecture Gate              FAIL
```

## Source Conflict Snapshot

```text
GITHUB_FACT: PR #9, PR #12 and PR #13 are merged; main is 548d53f1eae2ec14eea7261d3a101c1f2fda0dcc.
GOVERNANCE_FACT: 001D is COMPLETE / PASS; 001E is prepared with implementation authorization NO.
SOURCE_CONFLICTS: NONE WITH EVIDENCE for merge state and current package order.
EVIDENCE_LIMITS: real-MySQL two-connection claim concurrency and MySQL 5.7 remain unverified; Dev/Staging/Production Migration was not run.
```

## Initial Hours Snapshot

| Metric | Value | Classification | Evidence / Qualification |
|---|---:|---|---|
| `001D_TOTAL_ESTIMATE` | 35–54h; planning value 45h | completed issued total estimate | Frozen and completed parent 001D package; not remaining |
| `001E_ESTIMATE` | 40–62h; planning value 51h | known remaining component estimate | Frozen 001E-A/B/C package; implementation not authorized |
| `001F_ESTIMATE` | UNKNOWN WITH EVIDENCE | unknown remaining component | No frozen package estimate exists |
| `KNOWN_CURRENT_TRACK_REMAINING` | 40–62h; planning value 51h | known remaining component | Frozen 001E only; excludes unknown 001F |
| `ARCHITECTURE_GATE_REMAINING` | UNKNOWN WITH EVIDENCE | unknown remaining total | 001E is known, but 001F is unestimated and cannot be safely added |
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
MEDIUM: frozen 001E-A/B/C bottom-up estimate before implementation preflights
LOW / UNKNOWN: project and Architecture Gate remaining totals because 001F and actual-ledger overlap remain incomplete
ESTIMATE_AS_OF: 2026-08-01
ESTIMATE_METHOD: repository source classification; no subtraction without accepted actuals; no addition of cumulative or overlapping ranges
```
