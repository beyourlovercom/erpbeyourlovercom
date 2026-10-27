# Order Detail UX V1.3 — Left-gutter floating navigation

Date: 2026-10-27. Owner design PASS: move floating section menu as far LEFT as possible next to the existing ERP global sidebar, without consuming the order cards' width.

## Baseline
- Repo beyourlovercom/erpbeyourlovercom, new branch ux/order-nav-left-outside-content-v13-20261027 directly from main a6c6060161d7177098977479ae172805e01a6f57 (merged PR #528).
- Current production at start: release order-detail-uxv12-pr528-20261027-a6c60601. Actual production Order Show.vue source SHA-256 matched main.
- Only application file changed: resources/js/Pages/Orders/Show.vue (page-scoped CSS). Shared shell, inventory, logistics, accounting, Woo, controller, routes and schema untouched.

## Implementation
- No more two-column grid. Order body independently centered, max width 1080px.
- Nav and centered order content share one CSS grid cell as overlay siblings. The nav uses sticky positioning and computes its right edge relative to the ACTUAL centered content left edge: content left minus 5px. No hardcoded sidebar width or fixed left pixel value.
- 1580px+ viewport: 126px full navigation. 1024–1579px: 52px compact text-wrapping navigation; content reserves 50px to protect the left gutter. Under 1024px the rail hides and content uses full width.
- All ten anchors, click-to-scroll, page-local module fold/drag, inventory grouping and unsaved edit drafts remain unchanged.

## Verified checks
- Scoped Vue 67/67 PASS; vue-tsc PASS; Vite production build PASS; git diff --check PASS.
- Owner screenshot around 1586px showed overlap because previous implementation used sidebar width rather than the order content edge. This was corrected.
- Headless Chromium geometry smoke combined actual Show.vue CSS and ERP shell.css/tokens.css using a representative shell skeleton (NOT authenticated real-app UAT): final revision tested 18 viewport/sidebar states. Each had an exact 5px content-to-nav gap, zero overlap with sidebar or content, no horizontal overflow, sticky nav top 108px after scrolling.

| Width | Sidebar mode | Navigator width | Gap | Sticky top | Result |
|---|---|---:|---:|---:|---|
| 1024 | expanded / collapsed | 52px | 5px | 108px | PASS |
| 1280 | expanded / collapsed | 52px | 5px | 108px | PASS |
| 1440 | expanded / collapsed | 52px | 5px | 108px | PASS |
| 1579 | expanded / collapsed | 52px | 5px | 108px | PASS |
| 1580 | expanded / collapsed | 126px | 5px | 108px | PASS |
| 1586 (Owner screenshot width) | expanded / collapsed | 126px | 5px | 108px | PASS |
| 1599 | expanded / collapsed | 126px | 5px | 108px | PASS |
| 1600 | expanded / collapsed | 126px | 5px | 108px | PASS |
| 1920 | expanded / collapsed | 126px | 5px | 108px | PASS |
No horizontal overflow at any tested size.
- Updated isolated synthetic-SQLite Dev at http://100.94.52.109:18687/customers/orders/1. Dev Vue source SHA matched local worktree, login HTTP 200 and unauthenticated order route redirected to login (302).
- Isolated production-backend-parity PHP suite 38 tests / 399 assertions PASS.

## Release gates remaining
- Authenticated live US Mac Dev browser visual/interaction UAT with Owner PASS: main/global sidebar expanded/collapsed, nav layout, scroll, fold/drag, editor draft. Prior automated login blocked by connector safety policy; static Chromium geometry is not live Dev Owner UAT.
- New PR final-head CI/readback; merge after the Owner visual UAT PASS in accordance with AGENTS.md.
- Separate Owner formal production deployment approval; re-read then-current production, build source-overlay release from current LIVE backend (do not deploy old main wholesale), preserve rollback, atomic swap and public smoke; Owner final production visual PASS.
- No production changes or real order/inventory writes in this V1.3 development activity.
