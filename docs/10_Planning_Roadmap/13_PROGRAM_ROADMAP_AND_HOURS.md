---
type: program-roadmap-hours
project: BYL ERP
version: 1.0
updated: 2026-08-28
---

# Program Roadmap and Hours

## V2 current-status overlay — 2026-09-05

This overlay supersedes older mutable `CURRENT_*` markers below while preserving them as historical planning records. Canonical current status is `27_ERP_ROADMAP_PRODUCTION_RECONCILIATION_V2.md`. Strategic line: **Production Consolidation → Fulfillment Intelligence**. KOL V1 and Purchase OS V1 are closed; Purchase is now live in the current bounded Production release; Product final cross-phase closure/promotion, Inventory/Packaging production closure, Logistics V1 and MVP2 Reservation are the next active-ready lanes.


This document is the repository source of truth for the complete Program Roadmap, estimate classifications, known and unknown remaining hours, actual-hours evidence and no-double-counting rules.

## Program Roadmap

```text
Architecture Migration / Global Architecture Gate [COMPLETE]
→ ERP-INQUIRY-M1 M1.0 Contract + M1.1 Company [COMPLETE]
→ ERP-ORDER-FULFILLMENT-M1 [COMPLETE]
→ Product Master / SKU M1 [COMPLETE]
→ Order Create/Edit V2 [COMPLETE]
→ Inventory Foundation + Inventory Batch Sync M1 [COMPLETE]
→ MVP1 Manual Fulfillment Integration [COMPLETE / PR #101]
→ MVP1.1 WooCommerce Inbound [COMPLETE / PR #104]
→ MVP1.2 Tracking Outbound [COMPLETE / PR #106]
→ MVP1.3 Sync Orchestration [COMPLETE / PR #108]
→ KOL M1.0 Foundation [COMPLETE / PR #103]
→ KOL M1.1 CRM [COMPLETE / PR #110]
→ ROADMAP RECONCILIATION [CURRENT / DOCS MERGE GATE]
→ KOL M1.2A Collaboration [NEXT]
→ KOL M1.2B Canonical Fulfillment Bridge
→ KOL M1.3 Content
→ KOL M1.4 Finance / Affiliate
→ KOL V1 Cross-phase Closure / Full Regression / Owner UAT
→ MVP2 Inventory Reservation Foundation + MVP2.1–MVP4 Fulfillment Intelligence / Connectors / Automation [PAUSED UNTIL KOL V1 CLOSED]
→ Accounting / Procurement expansion
→ remaining Inquiry milestone when reactivated
→ Milestone Remote Release / erp.beyourlover.com
→ Product / Media / Content / SEO / Shopify expansion
→ Production Hardening
```

The Program Roadmap is the top-level delivery sequence. It must not be replaced by the current Architecture track or a single Work Package map.

## Fulfillment planning principle

```text
MVP1 = MANUAL DECISIONS / ERP REPLACES TENCENT DOCS
MVP2 = ASSISTED INTELLIGENCE / HUMAN CONFIRMATION
MVP3 = WAREHOUSE CONNECTORS
MVP4 = AUTOMATIC FULFILLMENT
```

MVP1 must not be expanded by pulling future intelligent judgment into the current delivery. The long-term interfaces must remain compatible with Reservation, warehouse recommendation, Packing, chargeable weight, shipping-cost estimation, transport-mode evaluation, carrier rules and WMS connectors. Future chargeable weight uses `max(actual_weight, volumetric_weight)`. Future express cost above the configurable threshold (initial business threshold 300, currency configurable) triggers ocean-freight evaluation and does not force ocean automatically.

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
001F Architecture Re-audit            COMPLETE / PASS / CLOSED
001F-A Governance Status Correction   COMPLETE / CLOSED
001F-B Modular Boundary Decision      COMPLETE / REVIEWED / OPTION A1 APPROVED
001F-B1 Module Architecture Contract COMPLETE / PASS / CLOSED (PR #24; closure PR #25)
001F-B2 Module Skeleton & Automated Boundary Enforcement COMPLETE / PASS / CLOSED
001F-B3 Identity & Access Boundary Migration COMPLETE / PASS / CLOSED (Work Package PR #29; Implementation PR #30; Closure PR #31; main closure 0af914ebf8fc9f94b18e63e008fd78739bba6811)
001F-B4 Runtime / Audit / Sync Boundary Migration COMPLETE / PASS / CLOSED
001F-B5 Shell / Dashboard Boundary & Compatibility Cleanup COMPLETE / PASS / CLOSED (PR #37)
001F-C MySQL Two-Connection Concurrency Validation COMPLETE / PASS / CLOSED (PR #40)
001F-D Redis / Queue / Scheduler Runtime Validation COMPLETE / PASS / CLOSED
001F-E Local Development Migration Validation COMPLETE / PASS / CLOSED
001F-F                                 COMPLETE / PASS / CLOSED (PRs #48, #49, #50, #51; main a13becbefe1272ffb59cb2d78369df544e68ca97)
Global Architecture Gate              PASS
```

## Source Conflict Snapshot

```text
GITHUB_FACT: PR #101, #104, #106, #108, #103 and #110 are merged; the old roadmap positions that listed those phases as future/current are stale.
CANONICAL_MAIN_FACT: merged main is the only execution authority after this reconciliation; draft PRs cannot supersede it.
FULFILLMENT_TRUTH_FACT: Product, Inventory, Order, Shipment and Tracking truth already exist in their canonical modules.
KOL_BOUNDARY_FACT: KOL may own Collaboration facts and project fulfillment state, but must not own a second Order/Shipment/Inventory/Tracking engine.
KOL_FULFILLMENT_FACT: KOL physical fulfillment uses canonical orders.id with order_kind=kol_fulfillment and existing Shipment/Tracking truth.
INQUIRY_FACT: M1.2 Contact remains PAUSED / NOT CANCELLED until explicit Owner reactivation.
ENTITY_ARCH_FACT: PR #97 remains a docs/design reference only and is not implementation authority.
STALE_PR_FACT: PR #98 roadmap rebaseline and PR #109 duplicate KOL CRM are CLOSED / superseded by later merged facts.
VERIFICATION_FACT: US Mac remains the default sufficient verification/UAT host; China is special-case only unless explicitly requested or required by a concrete issue.
CURRENT_FACT: Roadmap Reconciliation is docs-only; no product feature is active after it until Owner selects a bounded next Work Package.
EVIDENCE_LIMITS: Production deployment, live WMS connectors and remote production mutation remain outside this reconciliation.
```

## Current Program Position and Estimate

```text
TOTAL_ARCHITECTURE_MIGRATION_REMAINING: 0h / complete
ACTUAL_HOURS: UNKNOWN WITH EVIDENCE
INVENTORY_M1: COMPLETE / OWNER UAT PASS / MERGED BY PR #96
CURRENT_PACKAGE: NONE / Inventory M1 closed
CURRENT_PACKAGE_BASELINE: current main / see GitHub for exact SHA
NEXT_PACKAGE: ERP-KOL-M1.2A-COLLABORATION-001 / OWNER SELECTED KOL V1 FULL COMPLETION
MVP1_MANUAL_FULFILLMENT_INTEGRATION: COMPLETE / MERGED BY PR #101 / historical, not remaining
POST_MVP_SMART_FULFILLMENT_ESTIMATE: UNKNOWN WITH EVIDENCE / Reservation, Allocation, Packing, Cost, Mode, Carrier, Connectors and Automation are separately bounded future work
ERP_INQUIRY_M1_ESTIMATE: 86–150h / planning value 116h / frozen M1.0 contract; M1.1 complete; M1.2–M1.9 retained
ERP_INQUIRY_M1_1_COMPANY_ESTIMATE: 6–10h / planning value 8h / completed package estimate, not remaining
ERP_INQUIRY_M1_2_CONTACT_ESTIMATE: retained inside approved ERP-INQUIRY-M1 milestone / execution PAUSED
MVP1_HUMAN_UAT_FINDINGS_OPEN: retained until individually evidenced closed
REMOTE_DEPLOYMENT: NO / milestone gated
```

No exact estimate is issued for MVP1 Manual Fulfillment Integration until its bounded Work Package defines the remaining manual Order/Product/Inventory/Logistics connection scope. Smart fulfillment phases remain separately estimated later and must not be pulled into MVP1. Chat elapsed time must not be converted into an estimate.

Historical architecture estimate fields below remain historical/completed evidence and are not current remaining work.

## Initial Hours Snapshot

| Metric | Value | Classification | Evidence / Qualification |
|---|---:|---|---|
| `001D_TOTAL_ESTIMATE` | 35–54h; planning value 45h | completed issued total estimate | Frozen and completed parent 001D package; not remaining |
| `001E_PARENT_PLANNING_ESTIMATE` | 40–62h; planning value 51h | parent-level original planning estimate | Original allocation across 001E-A/B/C; not remaining |
| `001E_B_CONFIRMED_ESTIMATE` | 12–18h; planning value 15h | confirmed child implementation estimate | Accepted bounded implementation estimate; package merged and closed |
| `001F_B2_TO_B5_KNOWN_ESTIMATE` | 4–8 Codex / 20–40h; planning value 30h | historical planning estimate | completed architecture scope; not remaining |
| `001F_C_TO_001F_F_ESTIMATE` | SUPERSEDED / COMPLETE | historical planning estimate | 001F-C through 001F-F are closed; not a remaining estimate |
| `TOTAL_ARCHITECTURE_MIGRATION_REMAINING` | 0h / complete | remaining estimate | Global Architecture Gate is PASS and 001F is closed |
| `ACTUAL_HOURS` | UNKNOWN WITH EVIDENCE | actual-hours status | no fabricated actuals |
| `MVP1_TOTAL_ESTIMATE` | 122–190h | total estimate | Effective Hours source; not automatically remaining |
| `MVP1_PLANNING_BASELINE` | 166h | total planning value | Effective Hours source |
| `MVP1_MAXIMUM_ENVELOPE` | 192h | maximum total planning envelope | Living Roadmap and Effective Hours sources |
| `MVP1_REMAINING` | UNKNOWN WITH EVIDENCE | unknown remaining total | Accepted actual-hours ledger and proven architecture/MVP1 overlap map are incomplete |
| `ERP_ORDER_FULFILLMENT_M1_ESTIMATE` | UNKNOWN WITH EVIDENCE | historical completed package estimate | package is complete/merged; no retrospective range is fabricated |
| `ERP_INQUIRY_M1_ESTIMATE` | 86–150h; planning value 116h | milestone implementation estimate | Owner-approved M1.0 contract; M1.1 completed; M1.2–M1.9 retained and paused only by priority |
| `ERP_INQUIRY_M1_1_COMPANY_ESTIMATE` | 6–10h; planning value 8h | completed child estimate | M1.1 Company merged by PR #89; do not count as remaining |
| `POST_MVP_KNOWN_INCREMENT` | approximately 68–120h beyond MVP1 | incremental estimate | WooCommerce inbound plus single outbound |
| `PROGRAM_REMAINING` | UNKNOWN WITH EVIDENCE | unknown remaining total | Long-term ranges are cumulative; actuals, overlap mapping and the current package estimate are incomplete |
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
- the current package estimate must agree with its formal Work Package.

## Estimate Confidence

```text
HIGH: quoted repository total/cumulative historical ranges and Owner-frozen ERP-INQUIRY-M1 milestone range
MEDIUM: bounded completed child-package estimates with accepted scope evidence
LOW / UNKNOWN: next Owner-selected feature and post-MVP smart-fulfillment estimates until bounded Work Packages are issued; project/program remaining total
ESTIMATE_AS_OF: 2026-08-27
ESTIMATE_METHOD: repository source classification; no subtraction without accepted actuals; no addition of cumulative/overlapping ranges; no chat-time conversion
```
