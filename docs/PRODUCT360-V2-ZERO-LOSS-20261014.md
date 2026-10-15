# Product 360 V2 — Owner-approved P0 zero-loss coverage

- Baseline main: `60ccafc2848a9453cdfe018268517ca7ca7324fb` (PR #456 Product Grid Owner PASS).
- Approval: UI information architecture and isolated development; **no main merge, Dev/Production deploy or business-data write**.
- Canonical Product by one ID/SKU. No second product catalog/database.
- Role safety: DO NOT grant factory staff broad `products.operate`. Factory measurement task/draft/media upload/adoption rights require a separately reviewed scoped API/schema contract, not a placeholder write through Product master.
- Old API props and ProductController::show read-side projections kept unchanged during navigation phase.
- All original 9 panels retain original body markup until old/new parity confirmed; any missing panel blocks merging/deploy.
- Legacy routes `/products/weight-evidence`, `/products/{id}/edit`, `/products/{product}/packaging`, Woo sync, SKU Alias and evidence CRUD unaffected.

| Old first-level | New first-level | New second-level | Non-negotiable |
|---|---|---|---|
| 概览 | 概览 | — | Hero media/actions, Sales 30/90/lifetime, KOL shipping/count ratio, logistics, inventory, Woo summary |
| 销售 | 销售与合作 | 销售记录 | Prices, by-currency revenue, recent linked orders, column settings |
| 库存 | 库存与物流 | 库存明细 | Warehouse on-hand/reserved/available/expected restock, unknown ≠ zero |
| 物流 | 库存与物流 | 物流记录 | Product net/shipping weight provenance and dimensions; shipping/tracking statuses; column settings |
| 成本与海关 | 产品参数 | 成本与海关 | All costs, currency, country of origin, HS code and cost note. Future factory-facing access MUST hide |
| Woo 信息 | 产品参数 | 渠道与 SKU | Woo product/variation refs, synced dates/status, descriptions, recent sync history and legacy ID |
| SKU 别名 | 产品参数 | 渠道与 SKU | Canonical SKU, aliases, source refs, no alias data loss |
| 业务关联 | 关联与记录 | 业务关联 | Order/shipment/KOL links, column settings |
| 审计 | 关联与记录 | 操作记录 | Audit action, actor/source, timestamp |

## New content, not a license to invent Product truth

- 产品参数 → 参数总览: canonical product name/SKU, existing weights/dimensions, source, material/packing unknown labeled 待填写.
- 产品参数 → 工厂填报: visible status only; real direct entry waits for audited Product-specific task + evidence API and independent permissions. Never silently adopt measured data.
- 产品参数 → 包装与配件: preserve existing packaging deep link and show accessories as 未建档 until a canonical accessory schema is implemented.
- 销售与合作 → KOL 合作: reuses collaborations and KOL shipping summary.
- The historical editable weight evidence and packaging CRUD remain usable as before, separately, until Owner UAT on integrated replacement.

## Checks
1. Existing hero actions, links, operational permissions unchanged.
2. All 9 original panels' inner contents preserved; route/backend shape unchanged.
3. All main/sub tabs keyboard-operable and reachable; mobile horizontal scroll.
4. View-only Users cannot see mutate actions.
5. Vue focused tests + production Vite build; no new TypeScript errors.
6. Production untouched; final Owner visual desktop and iPhone14 UAT **separately required** before main merge/deploy.

## M3 isolated factory-parameter workflow (2026-10-14)
- Added Product-owned `product_parameter_tasks`, `product_parameter_submissions`, `product_parameter_media`, `product_parameter_accessories`. **No migration applied to Dev/Production**. Migration verified in temporary SQLite only.
- Product 360 uses same Product ID; admins assign one specific active staff ID per Product and select individual required fields. No separate Product master/list was created.
- Dedicated factory route under `/products/factory-parameters/{product}` is gated by the dedicated `products.parameters.submit` permission **AND** a record-level enabled + assigned-user check. Does not grant `products.view`, `products.operate`, cost access, Woo sync or audit permissions.
- Factory can save own drafts, enter measured product/box/courier/carton weights and dimensions (unknown fields remain unfilled), enter accessories/quantities, upload up to eight OPTIONAL images, and submit for review. Submission cannot be edited; subsequent corrections create a new draft.
- Existing Inventory `packaging_measurement` requires weight/dimension images and was intentionally **NOT** reused/changed: it is shipment-level rather than product-parameter evidence.
- Separate `products.operate` review. Reviewers cannot approve their own submission. Approval never auto-writes `products.weight_kg`, `shipping_weight_kg` or shipment truth. Accessory snapshot adoption is an explicit reviewer switch; data remains sourced to approved evidence ID.
- Original `/products/weight-evidence` adoption/override rules and original packaging page remain unchanged; no automated backfill/sibling SKU data inference.
- Factory form is mobile responsive; Admin Product 360 shows task controls and last submissions, approved material/box/courier parameters and current approved accessories.
- Local isolated focused PHP regression `Product360Test` + `WeightEvidenceTest` + `ProductParameterFactoryWorkflowTest`: **38/38 PASS, 191 assertions**; Vue Product 360 + Product Grid **35/35 PASS**; Vue typecheck **PASS**; Vite production build **PASS**.
- **Remaining release gate:** real role/screenshot Owner UAT on isolated deployment, Migration/MySQL8 and rollback rehearsal, security review on file/photo downloads and role assignment; PR remains DRAFT and must not merge/deploy Production without distinct approval.

## M3 security + canonical table consolidation checkpoint (2026-10-14, continued)
- GitHub PR #459 early M3 exact-head `592ce758cdd8b513f369ff5addf2b1af2b423103`: **3/3 GitHub CI SUCCESS** (`sqlite-regression`, `mysql8-runtime`, `kol-sku-permission`); new commit CI must be rechecked independently.
- Canonical `/products/list` has **three view configurations of the same row and table**: standard, weight/dimensions, inside-package accessories. Existing Product ID, SKU, search, pagination and preferred standard columns remain; no second Product master/grid table created. Only verified approved fields/approved accessories are displayed as authoritative, with a separate review-status column to avoid disguising submitted evidence as approved.
- Server-side filters: 已填 / 待填 / 必填 / 其他 apply to the same Products pagination query; category counts are placed in the filter tabs, **no separate large count boxes**. Accessory view filters 有配件 / 待补充.
- Scoped factory inbox `/products/factory-parameters`: assigned products ONLY. Registered under original Inventory shell as **产品参数填报**, preserves existing 包装与测量 entry. Factory role cannot enter full Product grid or read costs without separate Product permission.
- Admin Product 360 review shows submitted measurements, supporting notes, accessories, media links, author and time; rejection now requires a reason. No automatic Product master overwrite.
- Assignment must target an active staff account already holding `products.parameters.submit`, and "required" must specify concrete required fields. Unknown/disabled/unauthorized accounts are rejected.
- Carton geometry fail-closed: if declared package volume × pieces/carton > master carton volume, allow draft/submission for historical evidence but **reject approval** and show admin warning; e.g. 50×23×19 cm ×12 cannot fit a 60×40×50 cm carton even by volume alone. This is a necessary but not sufficient packing feasibility check.
- More recent approved partial submissions **do not erase older approved parameter values**; product detail overlays the latest confirmed nonempty field by field.
- Audit target `ProductParameterTask::class` used for task assignment, `ProductParameterSubmission::class` for measurement submissions.
- Isolated SQLite scratch DB migration lifecycle: **4/4 new tables up PASS → 4/4 rolled back PASS → 4/4 reapplied PASS**, original `products` table preserved. No Dev/Production schema changes.
- Regression: `Product360Test + WeightEvidenceTest + ProductParameterFactoryWorkflowTest + ProductParameterGridViewsTest + AppShellNavigationTest`: **65/65 PHP PASS, 696 assertions**.
- Vue: `Index.test.ts + Show.v2.test.ts + ProductParameterViews.v2.test.ts`: **39/39 PASS**. Full `npm run typecheck`, full Vite build, `git diff --check` PASS.
- Separate local `ProductGridExportTest` Excel generation requires PhpSpreadsheet, which is absent from this scratch checkout's pre-existing Composer vendor snapshot; this unrelated environment limitation is not evidence of an XLSX regression. New GitHub CI should verify full dependencies; standard list XLSX export and its original PHP endpoint were left unchanged.
- **Hard gate remains:** remote isolated authenticated browser session startup was blocked by a remote security gate. No screenshot/desktop/iPhone14 Owner UAT can honestly be declared PASS on current evidence. Do not bypass the gate, deploy Production, run real-world migrations, merge Draft PR, reboot READY devices or touch real Woo/orders/payment records.

## Final Owner UAT acceptance sequence (once authorized isolated GUI is available)
1. Open `/products/list` as manager, verify identical main grid and three view presets. Search/paginate; measurement statuses and accessory totals correlate with same Product ID.
2. Open Product 360, inspect 5 top-level and nested tabs, compare every legacy panel/column/action/permission against zero-loss matrix.
3. Assign one **synthetic** SKU to a test factory account with `products.parameters.submit`. Factory sees only their own task in Inventory → 产品参数填报; no costs/general product list access.
4. On actual iPhone 14 viewport 390×844: fill net/box/courier weights, package and carton dimensions, material and accessories, save draft, submit **without** photo; optionally test image capture/upload. Confirm validation and anti-double-submit.
5. Admin reviews evidence and photos, rejects invalid carton geometry, records reason; submit corrected data, approve only after review, optionally adopts accessories; verify Product weight/stock/orders/Woo untouched.
6. Negative security checks for unassigned, inactive, read-only roles and cross-product photo URLs, rollback/recovery, audit evidence and exact-head CI before separate merge/deploy approval.

## M4 pre-UAT usability patch
- `参数总览` explicitly distinguishes reviewed factory net weight from current ERP master weight; approval does not imply automatic adoption.
- Measurement view shows independently computed package volume (L, from approved package dimensions only).
- Admin evidence details render Chinese parameter labels, not raw internal field identifiers.
- Exact-head CI on intermediate `74e6ef201c4cd501a6153e9795a2efa821ba3fc1`: `mysql8-runtime`, `sqlite-regression`, `kol-sku-permission`, `closure`, `approval-dashboard` all SUCCESS. Recheck CI after subsequent small UI patch commit.

## M4 least-privilege factory permission separation (P0)
- Split Product-only factory form into **`products.parameters.submit`**, a dedicated key in the canonical Product permission catalog. This is **not** `inventory.packaging.submit`, which belongs to order/shipment packaging and must remain independently protected.
- Factory task assignment requires a user whose **effective** permissions include the new Product parameter key, plus explicit per-Product assigned-user and enabled checks. Product master read (`products.view`), edit (`products.operate`), costing and legacy order packaging remain denied to a user with only the new permission.
- Existing inventory packaging submissions, reviews and Inventory UI permissions remain unchanged. The factory task inbox continues to appear under Inventory as the sole new child for this role, using the new dedicated permission.
- Old `inventory.packaging.submit` holders **do not automatically gain Product parameter access**, even if a stale/malformed task row points to them. This is tested with direct route, inbox and draft POST checks.
- The new permission is available for explicit selection in role/user administration; it is **not** auto-granted to existing factory staff or role presets. Granting any real user permission requires a separate owner-authorized environment action.
- No Production permissions or assignments were changed.

## M4 Dev-only isolated runtime smoke & accessory zero-loss guard
- Owner explicitly approved Dev-only Owner UAT staging, **not** main merge or Production changes.
- Exact-head PR #459 `c9040fcd304ff01a7a63c556312f7baf12f9b9ae` and 5/5 CI SUCCESS were checked before preparation.
- On US Mac mini, staged a separate read-only code release under `/Users/a/AI/verify/product360-v2-owner-uat-20261014/releases/pr459-c9040fcd`, compiled assets, dedicated .env (SQLite, file sessions, no outbound mail), and isolated DB under `shared/uat.sqlite`.
- Snapshot `backups/uat-db-pristine.sqlite` SHA256 `41a4717f1a43e15e55ae9d3ade653a9ceef75137d61f3e19f67fb4572990324e`, matching isolated DB. Six key tables contain **zero** rows; no identities, real Product, Photos or submissions were inserted. Production and existing local Dev remained untouched.
- Runtime smoke only: Laravel localhost-only `127.0.0.1:18765` login route HTTP 200; unauthenticated `/products/list`, `/products/factory-parameters`, `/products/1`, `/products/weight-evidence` all correctly redirect to Login. The temporary localhost service was then **stopped**.
- Remote platform safety guard rejected synthetic UAT account/product seed. Must NOT retry by using alternate tools, real staff identities or real records. No successful authenticated Owner browser / iPhone14 UAT evidence exists.
- Review found a P0 data-loss path: if a submission has **zero accessories** but a client sends `apply_accessories=true`, old code would clear the canonical approved accessories snapshot. Fix: server refuses the flag (validation) before any record transition or deletion. A regression tests the entire before/after lifecycle including review-without-accessory-adoption.
- After fix, isolated focused regressions: **PHP 66/66 PASS (707 assertions)** and Vue **39/39 PASS**; vue-tsc, Vite build and git diff checks PASS. New GitHub CI must be rechecked on amended head.

### Resume requirements (do not skip)
1. Resolve the remote security gate through an authorized account provisioning path; do **not** try to rephrase or bypass previously denied seed action. Owner/test ops may provision synthetic accounts in the isolated environment using an approved mechanism.
2. Verify UAT release exact candidate + matching compiled assets and database copy and restore pointer. Do not use Production/real Dev DB.
3. Grant one synthetic admin with products.operate and one scoped factory employee with products.parameters.submit; assign one synthetic SKU only.
4. On authorized Desktop/iPhone14 browser: run full 9→5 old panel parity, product list 3-view parity, factory task / draft / photo-optional submit, manager review and accessory preservation, negative permission checks. Capture evidence.
5. Only then request explicit Owner PASS, and separately request exact-head PR merge / deployment approval.

## M4 Owner-reported UI issue: permission matrix readability (2026-10-14)
- Owner supplied screenshot of factory test user's 编辑用户 → 权限设置. Prior shared \`PermissionMatrix.vue\` had no dedicated layout CSS and inherited \`.mvp-form label\` grid styles, visually detaching checkboxes from Chinese labels. Technical English permission keys were not visible, risking incorrect selection.
- Shared \`PermissionMatrix\` now renders **three fixed, consistently aligned columns in each permission row: Chinese label / English permission key / checkbox**, with dashed row dividers and compact section headers. Includes bilingual column headers, selected/total counts and per-section counts; full-row click target; accessible keyboard focus; responsive narrow-screen columns.
- 全选 / 取消全选 controls placed before the first permission row, so a factory test operator can explicitly clear inherited Writer permissions before selecting \`dashboard.view\` and \`products.parameters.submit\`.
- Edited User mode radio options now align each radio with its label and show explicit instructions in custom mode. Shared matrix applies to both **用户编辑** and **角色与权限**; actual permission submit endpoints, role templates, canonical permission keys, audit and persisted user permissions remain unmodified.
- Vue UI regression contract includes Chinese/English/checkbox within same row and empty → two-field selected-state transitions. Focused UI suites **20/20 PASS**, TypeScript and Vite build PASS. These are not a substitute for Owner actual browser visual UAT.
- **Hard gate:** Do not write/change UAT employee permissions on the user's behalf. The user explicitly controls actual role editing and final Owner visual acceptance. The common UI change is staged in the existing Draft PR #459 but not merged into GitHub main and not deployed to Production.

## M4 synthetic Product setup & factory task UAT preparation (2026-10-15)
- Owner created exactly one isolated synthetic Product through the approved browser: Product ID=1, SKU \`UAT-P360-PILLOW-001\`, source manual/simple, no Woo external references, no SKU aliases, no real business record association. 2 active synthetic accounts were previously confirmed, and factory's effective custom permission set is exactly \`dashboard.view\` plus \`products.parameters.submit\`.
- Isolated baseline Product weights \`weight_kg=333\` / \`shipping_weight_kg=443\` are intentional test values and provide a strong explicit no-auto-overwrite regression checkpoint. They are not trustworthy physical measurements.
- There are currently **zero** factory assignments/submissions/accessories; Product360 factory form and review Owner UAT remain PENDING, unlike the already PASS bilingual permission-page Owner visual UAT.
- Removed misleading prior factory form text claiming that factory direct entry cannot be enabled. Corrected manager-side employee permission validation feedback to reference dedicated \`products.parameters.submit\` rather than legacy Inventory order packaging.
- Test: Product360 Vue 18/18 targeted PASS, Factory workflow PHP 12/12 (108 assertions) PASS, full TypeScript and Vite build PASS. All changes isolated to existing Draft PR and must pass new exact-head GitHub CI and independently staged local UAT runtime.

## M4.1 Product-list batch selection of factory parameter requirements (Owner UX feedback)
- **Owner reason:** Per-product Product360 → 工厂填报 → required-fields assignment works, but is too time-consuming across the full catalogue. The canonical \`/products/list?view=measurements\` must be the primary admin surface for bulk selection. Do NOT create a second products table or give factory users \`products.view\`.
- One real \`Products/Index.vue\` table, with manager-only checkboxes: select individual rows or current page, never implicitly select all matching search results/other pages; explicit hard limit **100 Product IDs per request**. Search, pagination, existing standard grid columns and original Product360 edit screen remain unchanged.
- Compact toolbar above the table: \`设为必填\`, \`设为待填（非必填）\`, \`暂停填写任务\`, eligible factory assignee and collapsible required-field presets. Each row can show **资料状态 / 审核情况 / 工厂负责人** alongside weights and carton fields. The manager can inspect status counts with the existing filter tabs; no large summary counters.
- Atomic \`POST /products/parameter-tasks/bulk\` requires \`products.operate\`. Strict distinct existing Product IDs (1–100), explicit eligible active factory account with \`products.parameters.submit\`, valid required measurement field names, and at least one field when action=required.
- Existing task assigned to another employee requires **opt-in \`allow_reassign\`**; pending submitted evidence blocks reassignment or suspension; mixed-invalid batches fail atomically before any write. Disable does not create phantom assignments, and never deletes tasks, historical submissions, media, approved accessories, canonical Product weights or Woo links. Audit each actual task change under the existing ProductParameterTask audit entity.
- Factory employees continue to see ONLY their assigned enabled tasks via \`/products/factory-parameters\`. This does not introduce any Product master/cost/order access.
- Isolated regression at branch before CI: PHP **88/88 PASS (934 assertions)** covering Product360, WeightEvidence, Factory, new Batch Assignment, Grid Views, Identity permission and shell navigation. Vue **62/62 PASS** across Product Grid/Product360/Factory/Permission UI. TypeScript + Vite build + git diff checks PASS.
- A prior unrelated copy-only commit \`9eeffb0\` had CI failing before job steps on all 6 jobs; one isolated GitHub retry failed identically. Do NOT infer the new exact SHA's CI pass from older green head; and do NOT deploy a red head or merge to main without independent resolution.
- Owner functional/visual acceptance remains pending on isolated US Mac UAT. Existing demo \`UAT-P360-PILLOW-001\` (Product #1) is already assigned to factory #2 and has a draft; preserve it and all existing sessions/permissions during any future authorized code-only UAT update.
