# Order Detail UX V1.3 — Left-gutter floating navigation

Date: 2026-10-27. Owner design PASS: move floating section menu as far LEFT as possible next to the existing ERP global sidebar, without consuming the order cards' width.

## Baseline
- Repo beyourlovercom/erpbeyourlovercom, new branch ux/order-nav-left-outside-content-v13-20261027 directly from main a6c6060161d7177098977479ae172805e01a6f57 (merged PR #528).
- Current production at start: release order-detail-uxv12-pr528-20261027-a6c60601. Actual production Order Show.vue source SHA-256 matched main.
- Only application file changed: resources/js/Pages/Orders/Show.vue (page-scoped CSS). Shared shell, inventory, logistics, accounting, Woo, controller, routes and schema untouched.

## Implementation
- No more two-column grid. Order body independently centered, max width 1080px.
- Nav is fixed next to ERP global menu, 8px after the live sidebar width; respects expanded and collapsed global sidebar states. It remains visible when scrolling and does not reserve a full content grid column.
- 1600px+ viewport: 126px full navigation. 1024–1599px: 52px compact text-wrapping navigation. Main content reserves only 50px to avoid overlap. Under 1024px: hide floating nav and give full width to content.
- All ten anchors, click-to-scroll, page-local module fold/drag, inventory grouping and unsaved edit drafts remain unchanged.

## Verified checks
- Scoped Vue 67/67 PASS; vue-tsc PASS; Vite production build PASS; git diff --check PASS.
- Headless Chromium geometry smoke combined actual Show.vue CSS and ERP shell.css/tokens.css using a representative shell skeleton (NOT authenticated live ERP visual UAT):

| Width | Shell | Nav range x | Content range x | Overlap |
|---|---|---|---|---|
| 1024 | expanded | 228–280 | 289–955 | 0 |
| 1280 | expanded | 228–280 | 289–1211 | 0 |
| 1366 | expanded | 228–280 | 289–1297 | 0 |
| 1440 | expanded | 228–280 | 290–1370 (1080px) | 0 |
| 1440 | collapsed | 100–152 | 226–1306 (1080px) | 0 |
| 1599 | expanded | 228–280 | 370–1450 | 0 |
| 1600 | expanded | 228–354 | 370–1450 | 0 |
| 1920 | expanded | 228–354 | 530–1610 | 0 |

No horizontal overflow at any tested size.
- Updated isolated synthetic-SQLite Dev at http://100.94.52.109:18687/customers/orders/1. Dev Vue source SHA matched local worktree, login HTTP 200 and unauthenticated order route redirected to login (302).
- Isolated production-backend-parity PHP suite 38 tests / 399 assertions PASS.

## Release gates remaining
- Authenticated live US Mac Dev browser visual/interaction UAT with Owner PASS: main/global sidebar expanded/collapsed, nav layout, scroll, fold/drag, editor draft. Prior automated login blocked by connector safety policy; static Chromium geometry is not live Dev Owner UAT.
- New PR final-head CI/readback; merge after the Owner visual UAT PASS in accordance with AGENTS.md.
- Separate Owner formal production deployment approval; re-read then-current production, build source-overlay release from current LIVE backend (do not deploy old main wholesale), preserve rollback, atomic swap and public smoke; Owner final production visual PASS.
- No production changes or real order/inventory writes in this V1.3 development activity.
