# BYL ERP — Release 1.2–1.5 Re-baseline Proposal

**Status:** DRAFT / OWNER REVIEW REQUIRED
**Effective after:** PR #96 merge (main = `f5247a2f9d18e8912dd64d7e728a30ec6bef9a9b`)
**Prepared:** 2026-08-27
**Rebaselined:** 2026-08-27 (post PR #96 merge; ERP-GOV-RELEASE-ROADMAP-REBASELINE-001)
**Authorized by:** — (pending Owner confirmation)

> This document is a PROPOSAL only. No canonical roadmap file (04_LIVING_ROADMAP.md,
> 13_PROGRAM_ROADMAP_AND_HOURS.md, or 12_WORK_PACKAGE_INDEX.md) has been edited.
> Release numbering and scope assignments below are PROPOSED and require Owner sign-off
> before repository-wide application.

---

## 0. Source Conflict — Explicit Record

**Two canonical roadmap sources conflict as of 2026-08-27:**

- `docs/10_Planning_Roadmap/04_LIVING_ROADMAP.md` — updated 2026-08-27 (post PR #96):
  states next sequence as **Shipping ↔ Inventory M1 → Carrier/Shipping Rule Engine M1 →
  Warehouse Connector Foundation → Automatic Fulfillment M1 → WooCommerce Inbound +
  Tracking Outbound → Accounting/Procurement expansion → Inquiry M1.2 later/paused**.

- `docs/10_Planning_Roadmap/13_PROGRAM_ROADMAP_AND_HOURS.md` — last updated 2026-08-24
  (stale): still states **Order Fulfillment** as the CURRENT track, predating PR #96.

These two files cannot both be the authoritative execution sequence. **This source
conflict is recorded here and must be resolved by Owner before any new WP is activated.**
Latest GitHub main is the current code/source fact; release-number mapping and priority
still require Owner reconciliation because the canonical roadmap sources themselves
conflict (04 updated, 13 stale).

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
| `f5247a2f` | #96 | Inventory Foundation + Inventory Batch Sync M1, UAT remediation, dynamic warehouse shortcuts | **CONFIRMED DELIVERED / COMPLETE** — merged 2026-08-27. See §3E for no-double-counting boundaries. |
| (in main) | — | Accounting Data Contract V1 — status OWNER_APPROVED / FROZEN | **Completed prerequisite for Release 1.2 closure.** Already frozen; no release-number ownership dispute applies. |

The Accounting Data Contract V1 is **already frozen**. There is no "formal freeze" step
remaining.

---

## 2. Release 1.2 — Core Data / Workspace Foundation Closure

**Proposed label:** Release 1.2 — Core Data & Workspace Foundation
**Closure criterion:** Two remaining items below are merged to main.

> **PRIOR OWNER CLOSURE OVERLAY — Reclassified:** The earlier proposal statement that
> "Release 1.2 closure leaves only Accounting Data Foundation M1 + Ship Cost WP" was
> recorded as the then-current closure sequence. It is now reclassified as a **PRIOR
> OWNER CLOSURE OVERLAY / prior sequence**. It cannot stand as an uncontested fact
> because the Owner-authorized main roadmap updated in 04_LIVING_ROADMAP.md after PR #96
> presents a different immediate next sequence. See §PRIORITY_CONFLICT below for the
> explicit conflict record. The items below remain proposed Release 1.2 candidates, but
> their activation priority relative to the Shipping/Inventory sequence is unresolved.

### Remaining open items in Release 1.2 (proposed — priority contested; see §PRIORITY_CONFLICT)

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

## PRIORITY_CONFLICT — Unresolved Execution Sequence

**This section records an explicit conflict between two Owner-authorized sequences.
No next product/feature WP should be activated until Owner resolves which sequence is current.**

### Sequence A — Prior Closure Overlay (Accounting-first)

Source: Earlier Owner direction / prior roadmap sequence (predates PR #96).

> Immediate next: **Accounting Data Foundation M1 → Ship Cost WP**

### Sequence B — Latest Owner-Authorized Main Roadmap (post PR #96)

Source: `04_LIVING_ROADMAP.md` updated 2026-08-27 on current main.

> Immediate next: **Shipping ↔ Inventory M1 → Carrier / Shipping Rule Engine M1 →
> Warehouse Connector Foundation → Automatic Fulfillment M1 → WooCommerce Inbound +
> Tracking Outbound → Accounting / Procurement expansion → Inquiry M1.2 later / paused**

### Conflict Statement

These two sequences cannot both be the immediate next execution sequence. Sequence A
prioritizes Accounting/Ship Cost immediately. Sequence B places Shipping ↔ Inventory M1
next and defers Accounting/Procurement to a later position. Both originate from
Owner-authorized sources and neither is cancelled by the evidence available to this
proposal.

**Required Owner action:** Explicitly confirm which sequence governs next WP activation.
Until that decision is recorded in the Decision Register, no new feature WP should be
opened for any item in either sequence.

---

## 3. Boundary Definitions (No-Double-Counting Reference)

These boundaries must be applied when scoping future work packages to prevent
double-counting already-delivered work.

### 3A. Accounting

**NOW (Release 1.2 — priority contested; see §PRIORITY_CONFLICT):** Accounting Data
Contract V1 (frozen) + Data Foundation M1 + Ship Cost WP only.

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

**NOT completed by #90 / #93 / #95 / #96:**
- WooCommerce API inbound order ingestion
- Tracking number outbound push
- Full sync orchestration

These remain unscoped future work (appear in Sequence B above).

### 3E. Inventory / Procurement

**Already delivered by PR #96 — EXCLUDE from future Inventory/Procurement scope:**
- Warehouse Master (warehouses table, initial seed, warehouse CRUD pages)
- Canonical SKU × Warehouse inventory truth (warehouse_inventories, lots)
- Inventory pages (dashboard, warehouse list/show/create, inventory sync UI)
- Paste / CSV / XLSX Preview → Validate → Confirm → Audit pipeline
- Normalized lots / sync evidence (inventory_sync_batches, inventory_sync_rows, adjustments)
- Dynamic warehouse shortcuts (shell navigation)

**Inventory status:** ACTIVE — no longer paused. PR #96 delivered Inventory Foundation +
Batch Sync M1. Future Inventory scope (Shipping ↔ Inventory M1, Warehouse Connector
Foundation, etc.) must exclude all of the above.

**Entity Architecture V2:** DESIGN RECORDED / IMPLEMENTATION PAUSED — separately paused.
Not affected by PR #96 delivery.

### 3F. Inquiry Track

Inquiry M1.2–M1.9 remains **PAUSED**. Not silently reactivated by this rebaseline.

---

## 4. Proposed Future Program Matrix (No-Double-Counting)

The table below lists canonical future program items and their delivery status.
Release numbers in the "Proposed Release" column are **PROPOSED** and require Owner
confirmation before canonical assignment. Sequence ordering follows Sequence B from
§PRIORITY_CONFLICT pending Owner resolution.

| Program Item | Status | Notes |
|---|---|---|
| Accounting Data Foundation M1 | Not started | Proposed Release 1.2 item — priority contested (see §PRIORITY_CONFLICT) |
| Ship Cost WP | Not started | Proposed Release 1.2 item — priority contested (see §PRIORITY_CONFLICT) |
| Shipping ↔ Inventory M1 | Not started | Sequence B next item; excludes all PR #96 deliverables (see §3E) |
| Carrier / Shipping Rule Engine M1 | Not started | Sequence B item |
| Warehouse Connector Foundation | Not started | Sequence B item; excludes all PR #96 deliverables (see §3E) |
| Automatic Fulfillment M1 | Not started | Sequence B item |
| WooCommerce inbound (order ingestion) | Not started | Sequence B item; excludes all PR #90+#95 deliverables (see §3C) |
| WooCommerce tracking outbound | Not started | Sequence B item |
| Accounting / Procurement expansion | Not started | Sequence B later item; excludes Accounting Contract V1 |
| Product / Media remainder | Not started | Excludes all PR #93 deliverables (see §3B) |
| Full Order Workspace remainder | Not started | Excludes all PR #90 + #95 deliverables (see §3C) |
| Entity Architecture V2 implementation | PAUSED | Design recorded; implementation paused |
| Inquiry M1.2–M1.9 | PAUSED | Not reactivated |

---

## 5. Canonical Release 1.3, 1.4 and 1.5

**Known mapping — Release 1.3:** Fulfillment Workspace = PR #90 = early delivered / complete.
This is confirmed by prior Owner mapping. The label must not be re-used for new scope.

**Release 1.4 and 1.5:** Exact names and scope are **NOT present in current canonical
repository evidence.** Fabricating definitions here would create false authority.

Owner must confirm:
- Which program items map to Release 1.4 and Release 1.5
- Whether the WooCommerce sync tracks, Shipping/Inventory tracks, Product/Media remainder,
  and Full Order Workspace remainder are grouped together or split across releases
- Whether / when Accounting Foundation M1 is assigned relative to Shipping tracks
- Resolution of the §PRIORITY_CONFLICT sequence

Until Owner confirmation is recorded in the Decision Register, repository-wide release
numbering beyond 1.2 must not be changed.

---

## 6. Open Branches — No Action Authorized

PR #94 remains open/draft — stale planning evidence only; not modified by this branch.
PR #97 (Entity Architecture V2) remains design-only / implementation paused; not modified.
PR #96 is CLOSED/MERGED (head `b797db52`, merge `f5247a2f`) — its facts are incorporated
in §1 and §3E above.

---

## 7. Next Steps Required from Owner

1. Resolve the §PRIORITY_CONFLICT: confirm which execution sequence (Accounting-first or
   Shipping/Inventory-first) governs next WP activation.
2. Reconcile `04_LIVING_ROADMAP.md` (updated) with `13_PROGRAM_ROADMAP_AND_HOURS.md`
   (stale — still shows Order Fulfillment as CURRENT as of 2026-08-24).
3. Review and confirm or amend the Release 1.2 remaining scope (§2).
4. Confirm or amend the no-double-counting boundary definitions (§3).
5. Provide Release 1.4 and 1.5 canonical names and scope assignments (§5).
6. Authorize update of canonical roadmap files once this proposal is accepted.
7. Record decision in `docs/10_Planning_Roadmap/10_DECISION_REGISTER.md`.
