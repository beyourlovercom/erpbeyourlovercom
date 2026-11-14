# ERP SiteOps R2 — Real Dev read-only integration, 007windows (2026-11-14)

## Scope
Owner authorized R2 real data access, while preserving Laravel as the business authority and keeping ERP Production untouched.

## Verified source provenance
- Mac mini `erp.b` local Nginx/Laravel Dev, `APP_ENV=local`, `DB_CONNECTION=sqlite`, 10 rows in its local UAT SQLite.
- Mac Dev source is older than current ERP main. This is actual Dev database data, **not** current Production business records.
- Existing Laravel route used: `GET /orders` returns the Inertia `Orders/Index` component; the Dev local app has an auto-authenticated UAT Owner session and does not represent staff role parity.
- The 007windows-to-Mac tailnet connection was verified. Tailnet was **not restarted or reconfigured**.

## Execution
- Mac mini: a dedicated, token-gated, allowlisted read-only projection bridge, no arbitrary remote URL or business write, fixed GET `http://erp.b/orders`.
- 007windows: standalone localhost owner login and a back-end proxy, with Mac bridge token and address under `private/` and no browser-visible credential.
- No Laravel Dev database write, Worker, migration or Production operation performed.
- Projection strips addresses, emails, phone, customer data and notes; includes only order identity, workflow label, warehouse, SKU, tracking and date.
- Vben `/#/erp/orders` has a visible opt-in “读取 Mac mini 的真实 Dev 订单” action. Defaults to synthetic rows until clicked and shows the correct Dev source label after retrieval.
- Fail-closed: Dev fetch error clears prior rows and displays error; it never silently falls back to synthetic data.

## Validation on 007windows
- Mac direct bridge `GET` 200, records 10/10; 007 auth 401, POST 405, invalid view 400.
- Mac tokenless 403, Mac POST with valid token 405.
- 007 authenticated `GET /api/erp/dev/orders` 200, 10 Dev rows; output contains no address or customer email.
- Search nonexistent code returns total 0 from original Laravel; clearing search restores 10.
- Edge Playwright PASS: local login -> ERP orders -> real Dev click -> AG Grid 10 rows -> search 0 -> clear search 10, no synthetic leakage and no horizontal overflow.
- Vue TypeScript and Vite production-mode build PASS; previous five adapter Vitest unit checks PASS.

## NOT validated / hold
- Role-by-role session and authorization equivalence, including KOL-only visibility, on a current Dev ERP branch.
- Newest unified state projections, archived and deleted filtering, 20/50/100 pagination with >100 records, server sort.
- Fully reproducible pinned Vben vendor baseline and final 007 local Laravel runtime.
- Mac Dev bridge process autostart after reboot; current process is an active UAT session, not a Production service.
- All branch changes remain in Draft PR #766, with **no main merge or Production release**.
