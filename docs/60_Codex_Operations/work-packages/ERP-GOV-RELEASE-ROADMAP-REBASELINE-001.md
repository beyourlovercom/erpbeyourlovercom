# Work Package: ERP-GOV-RELEASE-ROADMAP-REBASELINE-001

**Type:** Governance / Documentation
**Status:** DRAFT / OWNER REVIEW REQUIRED
**Branch:** `docs/erp-roadmap-release-rebaseline-001`
**Base commit:** `3d35916a9634b7303470cad9cce07f76185ea4bb` (main after PR #95)
**Rebaselined against:** `f5247a2f9d18e8912dd64d7e728a30ec6bef9a9b` (main after PR #96)
**Created:** 2026-08-27
**Rebaselined:** 2026-08-27 (post PR #96 merge)
**Prepared by:** GPT/Owner roadmap analysis; docs implementation via US Claude Code

---

## Purpose

Produce a bounded re-baseline proposal for the BYL ERP release roadmap following the
formal merge of PR #90, #93, #95, and subsequently PR #96. This WP is documentation-only;
no application code, schema, migration, or runtime file is modified.

---

## Trigger

Four PRs merged to main in sequence:

| PR | Feature | Merge Commit |
|---|---|---|
| #90 | Order Fulfillment workspace | `ee3ba6be4a784e5ecb85663c852d4d9ca4cfe92b` |
| #93 | Product Master / SKU | `dd09bed704dfd4424e2c2c39d495da25a0550daf` |
| #95 | Order Create / Edit V2 | `3d35916a9634b7303470cad9cce07f76185ea4bb` |
| #96 | Inventory Foundation + Batch Sync M1, UAT remediation, dynamic warehouse shortcuts | `f5247a2f9d18e8912dd64d7e728a30ec6bef9a9b` (merge); head `b797db527328ba76d258b7446fec4349195ef36d` |

Additionally, Accounting Data Contract V1 is OWNER_APPROVED / FROZEN in main.

The cumulative delivery changes which program items remain open and which proposed
release-number labels were previously used, requiring a structured rebaseline before
new WPs are created against stale scope.

---

## Strict Scope

### In scope
- Create / update `docs/10_Planning_Roadmap/21_RELEASE_1_2_TO_1_5_REBASELINE.md` — the
  proposal document.
- Create / update this work package file.
- Create / update the session log at
  `docs/60_Codex_Operations/logs/2026-08-27_ERP-GOV-RELEASE-ROADMAP-REBASELINE-001.md`.

### Explicitly out of scope
- Editing any existing canonical roadmap / context / decision files.
- Any application code, migration, schema, route, permission, dependency, test,
  lockfile, or runtime file.
- Touching PR #94, #97 or their branches.
- Implementing Inventory, Accounting, Ship Cost, Inquiry, Woo Sync, Product Media,
  or any feature.

---

## Deliverables

| File | Purpose |
|---|---|
| `docs/10_Planning_Roadmap/21_RELEASE_1_2_TO_1_5_REBASELINE.md` | Re-baseline proposal for Owner review |
| `docs/60_Codex_Operations/work-packages/ERP-GOV-RELEASE-ROADMAP-REBASELINE-001.md` | This file |
| `docs/60_Codex_Operations/logs/2026-08-27_ERP-GOV-RELEASE-ROADMAP-REBASELINE-001.md` | Session log |

---

## Completion Criteria

- [ ] All three files updated on branch `docs/erp-roadmap-release-rebaseline-001`.
- [ ] No canonical roadmap file edited.
- [ ] No application code touched.
- [ ] PR opened against main for Owner review.
- [ ] Owner resolves PRIORITY_CONFLICT and records decision in Decision Register.
- [ ] Owner reconciles 04_LIVING_ROADMAP.md (updated) with 13_PROGRAM_ROADMAP_AND_HOURS.md (stale).
- [ ] Owner authorizes (or declines) update of canonical roadmap files.

---

## Frozen Facts Referenced

- Effective baseline SHA: `f5247a2f9d18e8912dd64d7e728a30ec6bef9a9b` (main after PR #96 merge)
- PR #90 merge commit: `ee3ba6be4a784e5ecb85663c852d4d9ca4cfe92b`
- PR #93 merge commit: `dd09bed704dfd4424e2c2c39d495da25a0550daf`
- PR #95 merge commit: `3d35916a9634b7303470cad9cce07f76185ea4bb`
- PR #96 merge commit: `f5247a2f9d18e8912dd64d7e728a30ec6bef9a9b`; head `b797db527328ba76d258b7446fec4349195ef36d`; STATUS: CLOSED/MERGED
- PR #96 delivered: Inventory Foundation + Inventory Batch Sync M1, UAT remediation, dynamic warehouse shortcuts. Inventory is NOT paused.
- Accounting Data Contract V1: OWNER_APPROVED / FROZEN — no freeze step pending.
- "Release 1.3 — Fulfillment Workspace": ABSORBED / COMPLETE by PR #90.
- Entity Architecture V2: DESIGN RECORDED / IMPLEMENTATION PAUSED — separately from Inventory delivery.
- Inquiry M1.2–M1.9: PAUSED — not reactivated.
- PR #94: open/draft — stale planning evidence; not modified.
- PR #97: design-only / implementation paused; not modified.
- Source conflict: 04_LIVING_ROADMAP.md updated 2026-08-27 (Shipping/Inventory-first); 13_PROGRAM_ROADMAP_AND_HOURS.md stale 2026-08-24 (Order Fulfillment as CURRENT). Requires Owner reconciliation.

---

## Pending Owner Decisions

1. PRIORITY_CONFLICT resolution: Accounting-first (prior closure overlay) vs.
   Shipping/Inventory-first (latest 04_LIVING_ROADMAP.md post PR #96).
2. Reconcile 04_LIVING_ROADMAP.md vs. 13_PROGRAM_ROADMAP_AND_HOURS.md.
3. Canonical Release 1.4 and 1.5 names and scope assignments.
4. Authorization (or declination) of canonical roadmap file updates.
