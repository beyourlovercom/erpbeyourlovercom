# BYL ERP SiteOps V3 — Approved navigation-only island recovery (2026-11-14)

Owner-approved workbook: `BYL_ERP_SiteOps_菜单审批_V3_含信息孤岛审计_2026-11-14(1).xlsx`, sheet `孤岛候选审批`, rows 6–16, plus `一级菜单审批`.

## Confirmed scope
- Existing 68 originally approved secondary entries stay intact. The latest main already has `采购 → 外采单`, and this PR does not remove it. The other 12 primary labels are still pending approval.
- Primary menu `会计` is **renamed only** to `算账`, with `/accounting` URL, route name, permission, children, and financial logic unchanged. Legacy menu config and visible profit/dashboard breadcrumbs updated.
- **Three approved orphan candidates are button-only**. Do not create new main/sidebar menu entries:
  - `历史物流控制台` GET `/logistics/historical-import`: small link on existing `/logistics/wait-stock`, shown only if `system.permissions.manage` and `logistics.view`.
  - P4 GET `/kol/import-activation`: link on existing `/kol/import-preview`, shown only if `system.permissions.manage`, `kol.operate`, `kol.affiliates.manage` (plus existing parent `kol.view`).
  - P5 GET `/kol/affiliatewp/binding-activation`: link on existing `/kol/affiliatewp`, same admin gate. P4 already contains a conditional `下一步：Affiliate 精确绑定` button after successful P4 application; keep that gate unchanged.
- Other rows: logistics review/completed/cancelled have status bar, order trash has an original button, KOL payouts and WP API have existing links: **retain unchanged**. Architecture and OMS UAT stay hidden.
- Unmerged B2B, Tingon, core status and other Draft PRs are independent and **not enabled** by this change.

## Authorization
- Each new button uses a boolean **computed server-side** using the exact existing role/permission keys. UI does not grant access; Laravel existing GET and mutation middleware remain the final authority.
- Historical import GET is currently `can:logistics.view`; this PR intentionally does not widen or narrow existing GET route permission. Its mutating endpoints still require `can:logistics.operate` plus existing business gates. Showing an admin-only shortcut is *not* equivalent to restricting manual URL entry.
- No router/migration/worker/queue/WordPress/OMS/order/warehouse mutations. No changes to 007windows SiteOps prototype or frozen 30+3 tabs.

## Acceptance and rollout
1. PHP `ApprovedMenuEntryV3Test`: unchanged accounting route/children, P4 and P5 admin booleans, role 403, historical admin boolean.
2. Original ERP Vue typecheck + build, source formatting, and exact route name checks.
3. On Dev only, 007windows Chrome: log in as Owner and restricted staff to check buttons, each link, and no access escalation. Include desktop + mobile, verify button fits compact header.
4. Owner visual PASS. PR may be merged only after CI/review and with authorization. Production deployment remains **separately gated**.
