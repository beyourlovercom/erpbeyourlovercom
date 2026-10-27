# ERP Order Detail UX V1 — U0–U5 Handoff (2026-10-27)

> **START HERE / Section 0.** Owner has approved the visual design and directed a continuous U0→U5 implementation, including controlled production delivery. This GitHub branch is the canonical handoff and development entry point. **Do not redesign the order detail.** This initial commit is documentation ONLY; no application code has been changed yet.

## 0. New-chat executable instruction

Immediately take over **ERP Order Detail UX V1**, follow **U0 → U1 → U2 → U3 → U4 → U5**, carry out real development, tests, desktop Dev browser UAT, GitHub CI, and controlled deployment at `erp.beyourlover.com`. Avoid repeated planning or questions already resolved.

### Owner-approved design, frozen
- Take the **existing official ERP order detail page**, **not** the previously discussed WooCommerce-inspired two-column redesign.
- Add a **floating/sticky in-page navigation menu on the LEFT**, between the ERP global sidebar and the existing order content, using the available whitespace.
- Clicking a section smoothly scrolls to it (no reload, no loss of unsaved edits). Highlight current section as the page scrolls; offer a return-to-top action. If a section is missing, hide its nav entry.
- Make **every existing section more compact**: tighten vertical card gaps, header padding, redundant large headings/explanations, row spacing and oversized empty states. Keep the existing visual hierarchy and all business operations.
- **Desktop-only admin UX**, no mobile UAT or layout investment.
- No ERP data model change, no new order/stock/warehouse/logistics workflows, no WordPress component transplantation.

**Status at handoff:** Owner visual concept PASS. **Implementation U0–U5 not yet completed**. New window begins U0 now, without assuming the original production version remains unchanged.

### Authorized scope
Create an isolated Git branch/worktree and Dev UAT test environment. Implement, test and create PR. Production must pass latest-runtime comparison, regression, CI, backup/rollback and approved controlled-release safeguards. Do not reset READY devices, restart Chrome, mutate real orders/inventory, rewrite Woo transactions or overwrite concurrent production changes.

## 1. Source of truth / exact entry points

GitHub: `beyourlovercom/erpbeyourlovercom`, private repo.
This handoff/development branch: `ux/order-show-floating-nav-compact-v1-20261027`.

Production: `https://erp.beyourlover.com`.
Runtime on US Mac mini: `$HOME/AI/production/erp.beyourlover.com/current`.
Repo checkout on US Mac mini: `$HOME/AI/007/erpbeyourlovercom`.
Preferred remote access: **self-built DC `mcp.beyourlover.com`, formal OAuth**, NOT generic Desktop Commander/cloud, and never reboot READY devices.
Source files:
- `resources/js/Pages/Orders/Show.vue` — all existing modules / forms / modals.
- `resources/js/Pages/Orders/Show.test.ts` — current Vue regression tests.
- `resources/css/shell.css` — shared `.erp-form-content`, `.erp-form-card` CSS. Avoid global side effects; favor a page-local class.

Baseline observed on 2026-10-27 (historical, **must refresh at U0**):
- Production release: `order-trash-pr523-preserved-20261027-680f0def`.
- `origin/main`: `6d2a34adb77c03cdbef8f2bb4d824d59fd193bfc`.
- Production `Show.vue`: approx. 1,757 lines.
- Product 360 V2, SKU Alias, XLWMS, order amendment, logistics, soft delete are currently shipped. Do not revert any.

## 2. Existing sections: keep functionality, add stable anchors

Recommended single-count nav sequence (verify real DOM in U0):
1. `order-section-overview` — 概览 / order header
2. `order-section-fulfillment` — 履约状态
3. `order-section-flags` — 辅助标记
4. `order-section-inventory` — 库存分布 (one nav entry for multiple per-SKU inventory cards)
5. `order-section-packaging` — 包装与重量参考
6. `order-section-order-info` — 订单信息
7. `order-section-address` — 收件与地址
8. `order-section-items` — 订单商品
9. `order-section-notes` — 内部备注
10. `order-section-audit` — 操作审计

The item amendment preview is part of the order-items section. Partial reallocation, shipment entry and replacement fulfillment **modals remain modals**, not separate sections. Hide any absent anchor. Don't include each inventory SKU as an individual floating navigation item.

Real `Show.vue` card order recently verified:
- `erp-form-content` container around line 726
- 履约状态 ~769
- 辅助标记 ~886
- one inventory card per order SKU ~904
- 包装与重量参考 ~969
- 订单信息 ~993
- 收件与地址 ~1032
- 订单商品 ~1103
- 内部备注 ~1244
- 操作审计 ~1257
- modal variants ~1270 onward.
**Line numbers are approximate; read current source.**

## 3. Design mechanics and boundaries

### Floating nav
- Small low-emphasis nav positioned in **existing left whitespace**, after global ERP sidebar; do not overlay form.
- Desktop sticky/fixed position chosen against actual shell geometry and header offset.
- Active state follows scroll via `IntersectionObserver` or equivalent; click uses `scrollIntoView({behavior:'smooth'})`, offset with `scroll-margin-top`; handle unmount cleanup and SPA/DOM changes.
- No refresh or form reset on anchor navigation. Preserve current edit, unsaved field values, validation errors, scroll position during modal open/close.
- Accessible labels, keyboard activation, current status. Back-to-top.
- No horizontal overflow at ordinary 1440px desktop.

### Compact cards
- Tighten local card `gap`, padding, title size, table row height and note prominence. Reduce long duplicated explanatory text, but **never hide or delete critical control, warning or historical evidence**.
- Fulfillment: compact read-only status / warehouse / carrier / tracking / source synchronization, retain all actions.
- Flags: compact help, leave exception / transit / refund / OOS flags.
- Inventory: preserve entire per-SKU warehouse matrix, only tighten wrappers; no change to allocations.
- Packaging: preserve weight/dimensions/packaging snapshots and links.
- Order info, shipping address: compact multi-column fields while preserving validation and existing `useForm` bindings.
- Items: retain SKU Combobox, quantity, zero-price gifts, negotiated SKU edits, reasons, preview and atomic replan. Only alter presentation.
- Notes and audit: tighter layout; audit data never removed (optional visual collapse for historic rows if tests allow).
- Do not turn page into a WooCommerce-style two-column editor. No right action rail/new cross-module state.

## 4. U0→U5 tasks and estimates

| Phase | Implementation | Estimate | Acceptance |
|---|---|---:|---|
| **U0** | Refresh production symlink, GitHub main and current `Show.vue`/tests; identify overlapping branches; freeze sections/anchor IDs | 0.5–1h | Exact baseline recorded |
| **U1** | Sticky left floating section navigation, active-scroll detection, click-to-scroll, hide missing sections, top shortcut | 2–3h | Navigation Vue tests PASS |
| **U2** | Compact local styles/content, shrink redundant card spacing/title/help without removing business controls | 2.5–4h | Visual density and no overflow |
| **U3** | Functional Vue and scoped PHP regression for items/stock/warehouse/fulfillment/permissions/audit | 1–2h | No new regression |
| **U4** | Real Desktop browser UAT on **isolated Dev** synthetic order, 1440px viewport, edit+scroll+modal | 1–1.5h | Browser PASS, no JS error |
| **U5** | PR + CI + release on latest production baseline, production smoke/readback, rollback & source parity | 0.5–1h | Live deployed safely |
| **Total** | Planning baseline | **8–13h** | Done |

These are planning estimates, not a promise of asynchronous delivery.

## 5. U3 non-negotiable regressions

- Enter/cancel/save order edit; invalid form errors; unsaved edits survive nav.
- SKU Combobox, unit/quantity/gift, negotiation reason, amendment preview, atomic replan.
- Single- and multi-warehouse reallocation, reservation safety, insufficient-stock rollback, 409 optimistic revisions; shipped legs immutable.
- Per-item multiwarehouse stock matrix stays visible, `007` warehouse still not auto-allocatable.
- Carrier, service, tracking, shipment entry, partial reallocation, replacement fulfillment, global feedback, Chinese tracking character validation.
- Domestic Liang and overseas Dede queues unchanged; Vita allocations intact.
- Packaging current standard vs frozen fulfillment snapshot, notes, audit, Order Trash/soft-delete edit guards.
- `orders.view`, `orders.edit`, logistics roles, and finance-sensitive fields/permissions unchanged.
- Woo source order/payment must not be rewritten due to ERP negotiated fulfillment.
- Product 360 V2, SKU Alias and WMS integrations untouched.

## 6. U4 Dev UAT procedure

Open isolated/synthetic order with staff role; test:
1. Sections appear in correct order, one inventory nav entry even for multiple SKU lines.
2. Click every nav entry and observe smooth scrolling + current-section highlight.
3. Click and edit various order fields, jump away and back, confirm unsaved input survives.
4. Check inventory, packaging, address, items, flags, internal notes, audit.
5. Open/close existing fulfillment and reallocation modals without losing draft.
6. Ordinary 1440px desktop and comparable width; no overlap with ERP left sidebar, header or order body; no horizontal scroll; zero JS exceptions.
7. Capture actual browser screenshot/readback; **do not call code-only test a browser UAT PASS**.

## 7. U5 release gates

- Independent PR based on current GitHub main (avoid direct edit of production).
- PR scope narrowly contains `Show.vue`, `Show.test.ts` and, if unavoidable, isolated CSS. No schema, OrderService, inventory/logistics, SKU Alias, Product360 changes.
- Required TypeScript, Vite, Vue scoped tests, relevant PHP tests, GitHub checks; distinguish existing baseline failures.
- **At release time, reread actual production symlink** and perform source diff. If another PR modified `Show.vue`, use true three-way merge with latest runtime, rerun tests. Do not overlay stale order page.
- Verify authenticated staff roles, existing UI controls and production HTTP status before cutover.
- Build a fresh versioned release from **then-current** production, allowlisted overlay, retain previous release and validated rollback. Normally no migration / DB write.
- Atomic symlink cutover and controlled PHP-FPM graceful reload only when gates pass. No READY device reboot or repeated Chrome windows.
- Read back frontend assets HTTP 200, real **read-only** order page at authenticated session, floating nav, edit/stock/fulfillment controls, and GitHub source checksum parity.
- Owner production visual PASS is the final closure gate. The earlier approved design PASS is not a claim that a newly deployed version was tested.

## 8. Forbidden scope / decision log

- **Canceled**: WooCommerce-like two-column overhaul and independent multi-package workstation.
- **Not requested**: changing product-to-warehouse business rules, splitting same SKU across warehouses, WP-to-Laravel reproduction, financial pricing system changes, new V2/V3 or mobile UAT.
- **Must retain**: one-order multiple-SKU assignment to different warehouses only where needed under existing rules; unchanged inventory reservation and shipment behavior.
- **Absolutely no** removal of stock grids, warehouse review, shipment actions, packaging, notes, audit, gift or SKU editing. No production transactions as test fixtures.
- Existing approved previous Product360 V2 and order amendments must not regress.

## 8A. Execution readback (2026-10-27; supplemental to original handoff)

- **U0 PASS:** fresh production symlink and current `origin/main` checked. The live `Show.vue` differed materially from GitHub main due to newer shipped product edits, atomic stock replanning, shipment validation and Order Trash. The **live production file** was used as the non-destructive implementation baseline. Non-Show controllers were not edited.
- **U1 PASS (code + Vue tests):** ten anchors, one SKU-inventory navigation entry, missing-section filtering, smooth in-page scroll, section highlight, keyboard buttons and return-to-top; bottom-of-page audit highlighting added.
- **U2 PASS (code + build):** page-local compact styles and sticky navigation; shared CSS, data model and workflows untouched.
- **U3 PARTIAL:** 58/58 scoped Vue tests pass, `vue-tsc --noEmit` pass, `vite build` pass. Production-backend-parity PHP tests for OrderTrash, OrderFulfillment and InventoryReservation: **37 tests / 369 assertions PASS**. Wider overseas logistics PHP suite still has 9 failures in WMS/SKU fixture paths; not counted as green and not directly changed by this UX PR.
- **U4 PARTIAL / BLOCKED:** headless Chromium on first isolated synthetic-order Dev app confirmed 1440px navigation placement (nav right=406px, content left=422px), ten correct anchors, two SKU inventory cards with one nav entry, HTTP 200, no horizontal overflow and no JavaScript exceptions. Actual production-backend-parity Dev is now available at `http://100.94.52.109:18687/customers/orders/1` (Tailscale, synthetic SQLite only) and its source SHA and assets match the development build. Full browser login/edit+scroll+modal test encountered connector safety interception, so **no full U4 PASS and no Owner live Dev UAT PASS**.
- **U5 PARTIAL:** PR #528 opened as Draft; GitHub ERP Orders/KOL and Order Trash CI checks on initial implementation SHA both **SUCCESS**. Latest UX fix requires fresh push/check readback. No production symlink, database or Woo changes made.
- **Hard gates remaining:** complete authenticated Dev browser/edit/modal UAT; Owner sees live Dev page and returns explicit `PASS`; required CI on final HEAD; latest live runtime source re-read and safe rollback plan; explicit production release authorization; atomic cutover/read-only smoke; Owner final production visual PASS.

**U4 additional authenticated-app and modal evidence (2026-10-27; not equivalent to browser PASS):**
- Isolated production-backend-parity Laravel feature test `OrderDetailUxV1U4GateTest` (not committed to GitHub or production) used staff roles with `orders.view` / `orders.edit` and a two-line synthetic order: authenticated real `/customers/orders/{id}` HTTP **200**, `Orders/Show` Inertia component and order props, `canEditOrder` and `canTrashOrder` true for editor and false for read-only viewer: **1 test / 30 assertions PASS**.
- Added scoped Vue U4 tests for opening/closing shipment entry and partial-warehouse reallocation modals with **unsaved order notes preserved and no unexpected POST**; Vue suite now **60/60 PASS**. `vue-tsc --noEmit` and Vite build PASS.
- Actual headless Chromium can reach Dev login page: HTTP 200, login form present, no login-page errors. **Authenticated-browser interactions on production-backend-parity Dev are still not verified** because login automation was safety-blocked. Do not infer a browser PASS from authenticated feature tests or simulated Vue DOM tests.
- Prior GitHub CI at `5f2ce9ee`: **2/2 SUCCESS**. Re-run/re-read CI at the final source/test HEAD after these added checks.
- Owner must still perform live on-screen Dev visual UAT and sign off; no production release occurred.

**Release state: NOT DEPLOYED.** A passing test subset or Draft PR is never a substitute for the above Owner and Production gates.

## 8B. UX V1.1 approved follow-on (2026-10-27)

Owner selected visual **Mode 2** for compact **default view** + original **edit form**. Implemented in the same isolated PR branch; see [ORDER_DETAIL_UX_V11_DUAL_MODE_2026-10-27.md](ORDER_DETAIL_UX_V11_DUAL_MODE_2026-10-27.md). Scope remains presentation-only, no invented payment editing. Vue 62/62, TypeScript, build and isolated PHP 38/399 PASS. Full authenticated U4 browser and Owner live UAT remain pending, so this is NOT a release approval.

## 9. Completion checklist

- [x] U0 baseline frozen against latest prod/main
- [x] U1 floating nav implemented/tested
- [x] U2 compact cards implemented/tested
- [ ] U3 cross-module regression PASS
- [ ] U4 **real Dev Desktop browser** UAT PASS
- [ ] U5 GitHub PR and required CI pass / baseline exception documented
- [ ] U5 controlled release on **latest production**
- [ ] Live ERP HTTP 200, nav and assets verified, no order/inventory behavior regression
- [ ] GitHub main & runtime matching shipped changed files
- [ ] Owner final production visual PASS
- [ ] Technical archive, branch closed

**New window first action:** read this file from branch `ux/order-show-floating-nav-compact-v1-20261027` and immediately execute U0. Do not create a new design proposal or ask again what layout was approved.
