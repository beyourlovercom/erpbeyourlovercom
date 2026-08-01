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
001D Runtime / Audit / Sync           IN PROGRESS BY GITHUB EVIDENCE
001D-A Runtime Foundation             MERGED / PASS BY PR #9
→ 001D-B Audit Foundation             TRANSITION HELD IN OPEN PR #10
001D-C Sync Outbox Foundation         WAITING
001E Shared UI / Browser E2E          WAITING
001F Architecture Re-audit            WAITING
Global Architecture Gate              FAIL
```

## Source Conflict Snapshot

```text
GITHUB_FACT: 001D-A PR #9 is MERGED / PASS.
MAIN_GOVERNANCE_FACT: AI/CURRENT_CONTEXT.md and related main documents remain stale until PR #10 is updated and merged.
OPEN_PR_FACT: PR #10 contains the pending transition to 001D-B preparation.
CONFLICT_HANDLING: Preserve all three facts. GitHub governs PR/merge state; main governs merged repository documentation; unmerged PR #10 is pending content and not repository truth.
```

## Initial Hours Snapshot

| Metric | Value | Classification | Evidence / Qualification |
|---|---:|---|---|
| `001D-B_TOTAL_ESTIMATE` | 9–14h; planning value 12h | remaining component estimate | Frozen parent 001D package |
| `001D-C_TOTAL_ESTIMATE` | 14–22h; planning value 18h | remaining component estimate | Frozen parent 001D package |
| `KNOWN_001D_REMAINING` | 23–36h; planning value 30h | known remaining component sum | 001D-B + 001D-C only; excludes 001E and 001F |
| `001E_ESTIMATE` | UNKNOWN WITH EVIDENCE | unknown remaining component | No frozen package estimate exists |
| `001F_ESTIMATE` | UNKNOWN WITH EVIDENCE | unknown remaining component | No frozen package estimate exists |
| `ARCHITECTURE_GATE_REMAINING` | UNKNOWN WITH EVIDENCE | unknown remaining total | Known component floor is 23–36h, but 001E and 001F are unestimated |
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
| Project-wide accepted ledger | INCOMPLETE | UNKNOWN WITH EVIDENCE | UNKNOWN WITH EVIDENCE | actual-effort ledger | UNKNOWN WITH EVIDENCE | Existing accepted Work Logs do not yet provide complete actual hours and overlap classification |

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
HIGH: quoted repository total and cumulative ranges
MEDIUM: known 001D-B/C component sum
LOW / UNKNOWN: project and Architecture Gate remaining totals
ESTIMATE_AS_OF: 2026-08-01 13:33 +08:00
ESTIMATE_METHOD: repository source classification; no subtraction without accepted actuals; no addition of cumulative or overlapping ranges
```
