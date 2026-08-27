# BYL ERP — Release 1.2–1.5 Re-baseline Proposal

**Status:** DRAFT / OWNER REVIEW REQUIRED
**Effective after:** PR #95 merge (main = `3d35916a9634b7303470cad9cce07f76185ea4bb`)
**Prepared:** 2026-08-27
**Authorized by:** — (pending Owner confirmation)

> This document is a PROPOSAL only. No canonical roadmap file (04_LIVING_ROADMAP.md,
> 13_PROGRAM_ROADMAP_AND_HOURS.md, or 12_WORK_PACKAGE_INDEX.md) has been edited.
> Release numbering and scope assignments below are PROPOSED and require Owner sign-off
> before repository-wide application.

---

## 1. Confirmed Closure — Frozen Facts

The following items are **confirmed delivered and merged** into main. They are permanent
facts. The release labels beside them reflect previously used / Owner-mapped designations
proposed to be carried forward; they are not declared "repository canonical" or
"authoritative ownership" by the current main branch, which does not itself contain a
literal Release 1.2–1.5 taxonomy definition.

> **Important distinction:** "Release 1.2 Closure" names the current closure workstream
> only. Completion of a feature *during* this closure does not automatically assign it
> to Release 1.2. Each row below records the currently evidenced release-mapping status
> independently of when it was merged.

| Merge Commit | PR | Feature | Release Mapping Status |
|---|---|---|---|
| `ee3ba6be` | #90 | Unified manual Order → Logistics / Fulfillment workspace | **Previously used / Owner-mapped "Release 1.3 — Fulfillment Workspace"** — EARLY DELIVERED / COMPLETE / ABSORBED BY DELIVERY. This proposal recommends carrying that mapping forward. No new Release 1.3 implementation remains. |
| `dd09bed7` | #93 | Product Master / SKU — CRUD, import, search, canonical `product_id` on orders | **Completed foundation — canonical Release 1.4/1.5 ownership NOT ESTABLISHED** by repository evidence. Future release scopes must deduct these deliverables. |
| `3d35916a` | #95 | Order Create / Edit V2 — view / edit / create baseline | **Completed foundation — canonical Release 1.4/1.5 ownership NOT ESTABLISHED** by repository evidence. Future Full Order Workspace scope must deduct these deliverables. |
| (in main) | — | Accounting Data Contract V1 — status OWNER_APPROVED / FROZEN | **Completed prerequisite for Release 1.2 closure.** Already frozen; no release-number ownership dispute applies. |

The Accounting Data Contract V1 is **already frozen**. There is no "formal freeze" step
remaining.

---

## 2. Release 1.2 — Core Data / Workspace Foundation Closure

**Proposed label:** Release 1.2 — Core Data & Workspace Foundation
**Closure criterion:** Two remaining items below are merged to main.

### Remaining open items in Release 1.2

| Item | Work Package | Status |
|---|---|---|
| Accounting Data Foundation M1 | ERP-ACCOUNTING-DATA-FOUNDATION-M1 | Not yet formalized / not yet implemented |
| Ship Cost Work Package | TBD WP ID | Not yet formalized / not yet implemented |

No other items belong to Release 1.2 closure unless a confirmed blocking regression
against a merged feature appears and is explicitly added by the Owner.

### Previously used / Owner-mapped "Release 1.3 — Fulfillment Workspace"

This label is **ABSORBED / COMPLETE / NO NEW IMPLEMENTATION REQUIRED**.
PR #90 delivered the unified manual Order → Logistics / Fulfillment workspace.
The label must not be re-used for future scope.

---

## 3. Boundary Definitions (No-Double-Counting Reference)

These boundaries must be applied when scoping future work packages to prevent
double-counting already-delivered work.

### 3A. Accounting

**NOW (Release 1.2):** Accounting Data Contract V1 (frozen) + Data Foundation M1 +
Ship Cost WP only.

**LATER (Release 1.4+ or Owner-labeled):** Historical product cost versions, order-item
cost snapshots, shipment cost allocation, exchange rates, P&L engine, Accounting UI,
reconciliation, ROI reporting.

### 3B. Product / Media

**Already delivered by PR #93 — EXCLUDE from future scope:**
- Product Master and SKU CRUD
- Product import and search
- Product shipping reference fields, accounting reference fields
- Canonical `product_id` selection on orders

**Future Product/Media remainder (not yet scoped):** Product media management, advanced
media workflows. Any new WP must explicitly state it excludes the above.

### 3C. Order / Workspace

**Already delivered by PR #90 + PR #95 — EXCLUDE from future scope:**
- Unified manual Order → Logistics / Fulfillment flow (#90)
- Order Create / Edit / View V2 baseline (#95)

**Future Full Order Workspace remainder (not yet scoped):** Advanced order workspace
enhancements beyond the V2 baseline. Any new WP must explicitly state it excludes the above.

### 3D. WooCommerce Sync

**NOT completed by #90 / #93 / #95:**
- WooCommerce API inbound order ingestion
- Tracking number outbound push
- Full sync orchestration

These remain unscoped future work.

### 3E. Inventory / Procurement

Status: **PAUSED / PRESERVE STATE**. Do not pull into current closure planning.
Entity Architecture V2 is DESIGN RECORDED / IMPLEMENTATION PAUSED.

### 3F. Inquiry Track

Inquiry M1.2–M1.9 remains **PAUSED**. Not silently reactivated by this rebaseline.

---

## 4. Proposed Future Program Matrix (No-Double-Counting)

The table below lists canonical future program items and their delivery status.
Release numbers in the "Proposed Release" column are **PROPOSED** and require Owner
confirmation before canonical assignment.

| Program Item | Status | Notes |
|---|---|---|
| Accounting Data Foundation M1 | Not started | Release 1.2 closure item |
| Ship Cost WP | Not started | Release 1.2 closure item |
| WooCommerce inbound (order ingestion) | Not started | Proposed future release |
| WooCommerce tracking outbound | Not started | Proposed future release |
| WooCommerce sync orchestration | Not started | Proposed future release |
| Product / Media remainder | Not started | Excludes all PR #93 deliverables (see §3B) |
| Full Order Workspace remainder | Not started | Excludes all PR #90 + #95 deliverables (see §3C) |
| Inventory / Procurement foundation | PAUSED | Entity Arch V2 paused |
| Accounting expansion (FX, P&L, ROI, UI) | Not started | After Foundation M1 |
| Inquiry M1.2–M1.9 | PAUSED | Not reactivated |

---

## 5. Canonical Release 1.4 and 1.5

**Exact Release 1.4 and 1.5 names and scope are NOT present in current canonical
repository evidence.** Fabricating definitions here would create false authority.

Owner must confirm:
- Which program items map to Release 1.4 and Release 1.5
- Whether the WooCommerce sync tracks, Product/Media remainder, and Full Order Workspace
  remainder are grouped together or split across releases
- Whether Inventory/Procurement is reactivated and in which release

Until Owner confirmation is recorded in the Decision Register, repository-wide release
numbering beyond 1.2 must not be changed.

---

## 6. Open Branches — No Action Authorized

PRs #94, #96, #97 and their branches are not touched by this re-baseline proposal.
No action on those PRs is authorized by this document.

---

## 7. Next Steps Required from Owner

1. Review and confirm or amend the Release 1.2 remaining scope (§2).
2. Confirm or amend the no-double-counting boundary definitions (§3).
3. Provide Release 1.4 and 1.5 canonical names and scope assignments (§5).
4. Authorize update of canonical roadmap files once this proposal is accepted.
5. Record decision in `docs/10_Planning_Roadmap/10_DECISION_REGISTER.md`.
