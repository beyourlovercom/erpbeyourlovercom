# ERP Order Detail UX V1.1 — Compact View / Full Edit Dual Mode

**Decision:** Owner selected visual **Mode 2** on 2026-10-27. The source of truth is the EXISTING official ERP order-detail screen and its V1 floating left navigation, not the invented visual mockup business sections. Do not introduce payment editing or a separate order route.

## Scope and effort contract

| Stage | Deliverable | Planning hours |
|---|---|---:|
| A | Freeze current live source, edit permissions and PR #528 | 0.5 |
| B | Compact text-only order information and address view | 1–1.5 |
| C | Compact SKU table and plain note in view | 1–1.5 |
| D | Preserve entire existing editable form, mode switching, left floating navigation, scroll, page-local styling | 1–1.5 |
| E | Vue / TypeScript / build, isolated Dev and PHP tests, GitHub CI | 1.5–2.5 |
| **Total** | **Development plus technical checks** | **5–7.5** |

This is a planning estimate, not a promise of background delivery.

## Delivered code

- **View is the default:** `ord-view-mode` on existing `Orders/Show.vue`.
- The order information card is a six-attribute text `dl`; immutable ID/source/owner no longer masquerade as disabled inputs.
- Recipient card shows all address fields, a single full-address line, optional address line 2, and no empty form controls.
- Order items are a compact `SKU / Product / Quantity / Unit price` table, one row per SKU. **No payment amount or financial total is inferred from fulfillment item unit prices.**
- Internal note is bare wrapped text without disabled textarea or redundant view-only label.
- Clicking **编辑订单** retains the original form, validations, SKU Combobox, add/remove, gift pricing, amendment preview and atomic inventory/warehouse replanning. **取消编辑** resets the draft to original data. Route stays unchanged.
- Order fulfillment, review, warehouse inventory/allocations, packaging evidence, operational flags, shipment draft, replacement shipment, order trash, audit history, original ten anchors, permissions and all existing modals remain visible and functionally unchanged.
- Styles apply only to `ord-compact-v1` and `ord-view-mode`; no shared shell CSS or mobile redesign.

## Technical validation — 2026-10-27

- Scoped Vue Vitest: **62/62 PASS** (including new summary/no-faux-input, multi-item, cancel/resume tests and pre-existing unsaved draft + logistics modals).
- `vue-tsc --noEmit`: **PASS**; `npm run build`: **PASS**; `git diff --check`: **PASS**.
- Isolated production-backend-parity Laravel tests for OrderTrash, OrderFulfillment, InventoryReservation, U4 role-and-route: **38 tests / 399 assertions PASS**. Isolated additional PHP test is UAT-only and is **not** shipped with the PR.
- Built assets and `Show.vue` copied ONLY to `~/AI/uat/erp-order-detail-ux-v1-20261027` (synthetic SQLite, no real ERP database). Dev `http://100.94.52.109:18687/login` returns HTTP 200 and unauthenticated order route returns redirect to login. Source SHA-256 matched development worktree on copy.
- Vite build retains an existing large-chunk warning (not a build failure).
- Live production `current` was still `order-trash-pr523-preserved-20261027-680f0def` when development began. It has not been changed.

## V1.2 approved follow-on

Owner added WordPress-style top-right collapse/drag and moved packaging immediately above notes. See ORDER_DETAIL_UX_V12_COLLAPSIBLE_SORTABLE_2026-10-27.md for code/readback/remaining gates.

## Remaining gates (DO NOT CLAIM CLOSED)

- Full authenticated 1440px Chromium/employee-browser visual UAT using the real isolated Dev app: visible compact summary, original actions, all anchors and no overlap; edit/save/cancel, SKU fields, form errors, stock and logistics modals; no JS errors. Earlier automated browser login was blocked by the connector safety policy; scoped DOM tests and PHP role tests **do not replace** real browser UAT.
- Owner live Dev UX V1.1 visual PASS. No production change on the strength of tests alone.
- GitHub CI required at last pushed HEAD, review Draft PR #528 and GitHub main vs. current production source drift before merging.
- A separate Owner authorization to ship production. Build fresh allowlisted overlay on latest production release, preserve rollback, deploy atomically without schema/data writes, verify authenticated live read-only smoke. Owner final production visual PASS.
- Never restart a READY device, overwrite current production data, or close the branch prematurely.

**Status:** Implementation and isolated technical checks PASS. Browser/Owner Dev UAT and production release NOT PASS. The PR remains Draft.
