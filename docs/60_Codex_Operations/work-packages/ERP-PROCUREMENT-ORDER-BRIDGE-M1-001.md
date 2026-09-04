# ERP-PROCUREMENT-ORDER-BRIDGE-M1-001

## WORK PACKAGE

```text
ID: ERP-PROCUREMENT-ORDER-BRIDGE-M1-001
TITLE: Purchase OS V1 — Order shortage bridge and procurement-order creation
STATUS: IMPLEMENTATION_AUTHORIZED / OWNER_VISUAL_APPROVAL_EQUIVALENT_RECEIVED
OWNER_AUTHORIZATION: Owner froze Purchase OS V1 at 28–41h, later added procurement tracking-number capture to V1, then explicitly instructed “不需要渲染图了。直接推进执行！” after reviewing the rendered V1 proposal.
STANDARD_ID: LARGE-PROJECT-EXEC-001
STANDARD_VERSION: 1.5
EXECUTION_PROVIDER: US_CODEX_CLI
VERIFICATION_TIER: HIGH_RISK
```

## REPOSITORY

```text
REMOTE: git@github.com:beyourlovercom/erpbeyourlovercom.git
LOCAL_WORKTREE: /Users/a/AI/007/worktrees/erp-procurement-order-bridge-m1-001
TARGET_BRANCH: feature/erp-procurement-order-bridge-m1-001
```

## BASELINE

```text
BASE_BRANCH: main
BASE_SHA: f6690ab411fdb8f34db8268ca9a100f4a4744c1c
BASE_VERIFIED_AT: 2026-09-04 Asia/Singapore / US Mac reconciliation
DEPENDENCIES: ERP-NOTIFICATION-CENTER-M1-001 merged by PR #176; Packaging bridge merged by PR #174
DEPENDENCY_TYPE: MERGED_PR
DEPENDENCY_STATUS: SATISFIED
PARALLEL_SAFE: YES — dedicated worktree; no dependency on unmerged Procurement work
FOUNDATION_CHANGE_REQUIRED: NO
```

## FROZEN V1 BUSINESS CONTEXT

Purchase OS V1 is intentionally small. The complete V1 chain is:

```text
real Order
→ operator marks existing Order operational marker `stockout`
→ Order Detail exposes “创建采购订单”
→ create one Procurement Order sourced from that real Order
→ system-generated procurement number
→ source Order / Order Item / Product facts are read-only
→ operator fills purchase URL / size / purchase quantity
→ notify Procurement assignee Zhang through the existing Global Notification Center
→ procurement staff manually updates procurement status
→ procurement staff may later fill carrier + tracking number in the V1 workspace
→ Dashboard projects active procurement progress
→ when source real Order becomes `shipped`, non-cancelled Procurement Order auto-completes
```

The overall frozen V1 estimate is 30–44h after adding procurement logistics/tracking capture. This Work Package is only the first bounded slice.

## AUTHORIZED ACTIONS

- Implement only the Order → Procurement Order bridge and procurement-order creation foundation.
- Reuse the existing Order `stockout` operational marker; do not build a new automatic inventory-shortage engine.
- Add reversible schema/migrations and tests for the minimum Procurement Order + Procurement Order Item authority needed by V1.
- Generate stable human-readable procurement IDs (example format `PO-YYYYMMDD-0001`) while retaining normal internal primary keys.
- Source one Procurement Order from one canonical real Order and preserve canonical `source_order_id` / `source_order_item_id` / `product_id` links.
- Permit multiple purchase lines for one Order.
- Read source Order/Product identity as read-only; writable line fields in this WP are `purchase_url`, `purchase_size`, `purchase_quantity` only.
- Add Order Detail upper-right Page Header Action Zone behavior: no create button when not stockout; create button when stockout and no Procurement Order exists; view existing procurement order when one already exists.
- Prevent duplicate active Procurement Order creation for the same source Order.
- Provide the minimum create, list/index, and post-create detail/read surfaces necessary for the Order bridge to be usable; the full Procurement execution workspace/status editor remains deferred to the next WP.
- Use existing authorization primitives. `procurement.view` remains canonical read permission; if a create/operate permission is needed, introduce the smallest explicit server-side permission needed by this frozen behavior and cover it with tests.
- Keep the Owner-authorized public Purchase OS route contract under `/purchase`; if approved menu/runtime navigation changes, update `menu/APPROVED_MENU.json`, runtime config, tests and this WP together. Do not repurpose `/orders/procurement` as Procurement Order authority.
- Run full US Mac verification appropriate to reversible schema/UI changes.
- Create/maintain one Draft delivery PR for this Work Package.
- Prepare isolated Local/UAT on an unused port (18129 was free at activation; recheck before bind), using local-only UAT credentials.

## PROHIBITED ACTIONS

- No Supplier Master, supplier selection, cost, currency, payment, receiving, warehouse receipt, inventory mutation, profitability, Product360 procurement projection, AI procurement, approval flow, or automatic purchasing.
- No automatic inventory shortage calculation in V1 M1.
- No Procurement Requirement entity/table.
- No Dashboard procurement widget in this WP; that is P-V1-M3.
- No manual procurement status editor or Zhang notification implementation in this WP unless strictly required as a minimal post-create default; those belong to P-V1-M2.
- No procurement tracking-number UI in this WP; it is frozen into overall V1 and belongs to P-V1-M2.
- Do not rename or reinterpret legacy `/orders/procurement`; it remains the external-warehouse Order slice.
- No Production deployment, Production migration execution, real-data mutation, destructive data rewrite, or credential escalation.
- Do not stop or modify unrelated UAT processes/worktrees, including the current service on port 18128.
- Do not widen architecture/business rules; ambiguous issues return to GPT Web.

## DATA CONTRACT — MINIMUM

`procurement_orders` minimum authority:
- internal primary key
- `procurement_number` unique
- `source_order_id` canonical FK/reference and uniqueness preventing duplicate V1 order-level procurement creation
- initial `status` sufficient for later V1 state editor; default `pending`
- `created_by`
- completion fields may be reserved only if implementation is clean and directly required by the frozen V1 schema; do not implement M3 behavior yet
- timestamps

`procurement_order_items` minimum authority:
- internal primary key
- `procurement_order_id`
- `source_order_item_id`
- `product_id` nullable only when canonical source genuinely lacks product identity; do not fabricate
- stable source display snapshot only where existing OrderItem snapshot conventions require it (`sku`, `product_summary`, source quantity)
- `purchase_url`
- `purchase_size`
- `purchase_quantity`
- timestamps

Snapshot fields are presentation/history evidence only; Product/Order remain canonical truth.

## INTERACTION CONTRACT

### Public Purchase OS URL Contract
- `/purchase` is the canonical Purchase OS landing and renders the minimal Procurement Order index in M1.
- `/purchase/orders` renders the same Procurement Order list/index.
- `/purchase/orders/{procurementOrder}` is the canonical Procurement Order detail route.
- `/purchase/orders/from-order/{order}/create` and `POST /purchase/orders/from-order/{order}` are the canonical create flow from a real source Order.
- Legacy user-facing `/procurement`, `/procurement-orders`, `/procurement-orders/{id}`, and the old Order create URL are compatibility-only redirects/aliases to the canonical `/purchase` contract.
- `/orders/procurement` and `/orders/procurement/export` remain the legacy external-warehouse Order slice and are explicitly outside Purchase OS route authority.

### Minimum Procurement Order Index
- Show only procurement number, source Order link, status, created time, and detail action.
- Desktop uses existing ERP table vocabulary; 390px uses cards without ordinary horizontal overflow.
- No Supplier, cost, payment, receiving, tracking, status-editing, search/filter expansion, or Dashboard scope is introduced by this index.

### Order Detail
- Preserve the existing Order page and existing header-action-zone layout.
- When Order is not marked `stockout`: no procurement create action.
- When Order is marked `stockout` and has no linked Procurement Order: primary action `创建采购订单` in upper-right Page Header Action Zone.
- When linked Procurement Order exists: replace create action with `查看采购订单 · {procurement_number}`.
- Never show duplicate create actions at page bottom.

### Create Procurement Order
- Entry must come from a real source Order.
- Read-only: source Order identity/status and source item Product/SKU/order quantity.
- Editable per source item: purchase URL, size, purchase quantity.
- Defaults may initialize purchase quantity from source order quantity but remain editable.
- Use the Owner-approved Form Design Standard V1.
- Primary create action belongs to upper-right Page Header Action Zone; no duplicate bottom action bar.
- Server validation rejects invalid/negative/non-numeric quantities and duplicate source-order procurement creation.
- Successful creation deterministically shows the created procurement record or returns to Order with a visible linked PO; choose the simplest route consistent with existing ERP conventions.

### 390px
- One-column form/cards.
- No ordinary horizontal scrolling.
- Header actions may wrap but remain at top.

## ACCEPTANCE CRITERIA

1. A normal non-stockout real Order does not expose `创建采购订单`.
2. A real Order with operational marker `stockout` and no linked Procurement Order exposes the create action in the upper-right Page Header Action Zone.
3. Create flow is impossible without a canonical source Order.
4. The form reads actual Order/OrderItem/Product facts and does not let the operator rewrite source Order/Product identity.
5. Operator can enter purchase URL, size, and purchase quantity for each source item.
6. One source Order may create exactly one V1 Procurement Order; repeated submit/retry cannot create duplicates.
7. Procurement number is automatically generated, unique, stable, and human-readable.
8. Created record links back to the real Order; Order page links to the created procurement record.
9. Multiple items on a source Order are represented as multiple procurement lines in the same Procurement Order.
10. Existing `/orders/procurement` external-warehouse view remains behaviorally intact.
11. Server-side permissions prevent unauthorized creation/read.
12. Desktop and 390px UIs follow frozen ERP form/action standards.
13. Existing Order regression tests remain green.
14. Fresh migration rehearsal passes on isolated Local/UAT data; no Production mutation occurs.
15. No out-of-scope Supplier/Receiving/Cost/Tracking/Dashboard functionality appears in this WP.
16. `/purchase` and `/purchase/orders` render the real minimal Procurement Order index rather than a placeholder or 404.
17. `/purchase/orders/{id}` is the canonical detail URL; legacy `/procurement` and `/procurement-orders*` resolve through compatibility redirects instead of becoming parallel primary URLs or 404s.
18. Legacy `/orders/procurement` and `/orders/procurement/export` remain behaviorally intact as the external-warehouse Order slice.

## VALIDATION

```text
REQUIRED_COMMANDS:
- targeted PHPUnit feature/unit tests for Procurement Order bridge, duplicate prevention and authorization
- relevant existing Order feature/regression tests
- menu/navigation tests if any governed menu entry changes
- npm test / targeted Vitest for touched Vue components where applicable
- npm run typecheck
- npm run build
- php artisan migrate:fresh --seed (isolated Local/UAT DB only) or repository-approved equivalent
- git diff --check
- verify no public/hot dev marker leak
- real Local/UAT browser smoke for desktop + 390px on US Mac
EXPECTED_EVIDENCE: exact commands, pass/fail counts, branch/HEAD, clean diff review, migration evidence, UAT URL/state
HARD_GATES: Owner live-page UAT PASS before formal closure/merge; Production remains forbidden
```

## FINAL VALIDATION EVIDENCE

```text
FOCUSED_VITEST: PASS — 4 files / 45 tests
LARAVEL_REGRESSION: PASS — AppShell + Approved Menu + Procurement; 1,753 assertions; 0 failures
TYPECHECK: PASS — npm run typecheck
PRODUCTION_BUILD: PASS — vite build; 889 modules transformed
DIFF_CHECK: PASS — git diff --check
MIGRATION_REHEARSAL: PASS — APP_ENV=testing + isolated /tmp SQLite; migrate:fresh --seed completed; temp DB deleted
OWNER_BROWSER_UAT: PASS — /purchase, /purchase/orders, /purchase/orders/1, compatibility redirects, 390px no horizontal overflow
UAT_DATA_INTEGRITY: PASS — existing PO-20260904-0001 preserved; no reset/delete
MAIN_RECONCILIATION: PASS — worktree base == origin/main == US Mac canonical main @ f6690ab411fdb8f34db8268ca9a100f4a4744c1c
PRODUCTION_MUTATION: NONE
```

## COMMIT / PR RULES

```text
COMMIT_MESSAGE: feat(procurement): add order bridge and procurement-order creation
PR_TITLE: feat(procurement): add Purchase OS V1 order bridge
PR_BASE: main
PR_DRAFT_STATE: DRAFT until required checks and Owner UAT are satisfied
MERGE_AUTHORIZED: CONTINUOUSLY AUTHORIZED only after mandatory Owner live UAT PASS + required checks; no Production release
```

## FINAL REPORT FORMAT

```text
REQUIRED_FIELDS: implemented files/behavior; schema; permissions; tests; typecheck/build; migration rehearsal; UAT state; branch/HEAD; PR/checks; scope exclusions; actual hours/variance; mandatory governance footer
ALLOWED_FINAL_VERDICT: READY_FOR_OWNER_UAT | OWNER_UAT_PASS_READY_FOR_DELIVERY | BLOCKED_WITH_EVIDENCE
```

## OWNER UAT FINAL

```text
OWNER_UAT_RESULT: PASS
OWNER_UAT_DATE: 2026-09-04
UAT_BASE_URL: http://100.94.52.109:18129
CANONICAL_PUBLIC_ROUTES: /purchase ; /purchase/orders ; /purchase/orders/{id}
COMPATIBILITY_ROUTES_VERIFIED: /procurement -> /purchase ; /procurement-orders -> /purchase/orders ; /procurement-orders/{id} -> /purchase/orders/{id}
LEGACY_ROUTE_PRESERVED: /orders/procurement remains the external-warehouse Order slice
390PX_ROUTE_UAT: PASS; no ordinary horizontal overflow
UAT_DATA_PRESERVED: PO-20260904-0001; no reset/delete during remediation
PRODUCTION_ACTION: NONE
FINAL_VERDICT: OWNER_UAT_PASS_READY_FOR_DELIVERY
```

## PROJECT POSITION

```text
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate [COMPLETE] → MVP1 Existing Operations / Tencent Docs Replacement → ERP-INQUIRY-M1 B2B Inquiry Foundation → ERP-INQUIRY-M1 Local Milestone UAT → milestone-gated remote release → MVP1.1 WooCommerce Inbound → MVP1.2 Single Tracking Outbound → MVP1.3 Sync Orchestration → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion → Shopify and Additional Integrations → Production Hardening / Expansion
CURRENT_TRACK_PHASE_MAP: Purchase OS V1: → P-V1-M1 Order Bridge → P-V1-M2 Procurement Workspace + Zhang Notification + manual status + procurement carrier/tracking capture → P-V1-M3 Dashboard + source-Order-shipped auto-closure → P-V1-M4 regression/UAT/closure
COMPLETED_PHASES: Packaging/Product/Order bridge; Global Notification Center; preceding merged ERP foundations verified on current main
CURRENT_PHASE: Purchase OS V1 / P-V1-M1
CURRENT_WORK_PACKAGE: ERP-PROCUREMENT-ORDER-BRIDGE-M1-001
PACKAGE_STATUS: OWNER_UAT_PASS_READY_FOR_DELIVERY
WAITING_PHASES: P-V1-M2, P-V1-M3, P-V1-M4
BASELINE: main@f6690ab411fdb8f34db8268ca9a100f4a4744c1c
GITHUB_STATE: GitHub default main verified; US Mac main/origin/main/remote main exact-match at activation
SOURCE_CONFLICTS: AI/CURRENT_CONTEXT.md is stale (2026-08-29 KOL-era position); live GitHub/US runtime and later merged work packages show Packaging + Notification completed and current Procurement activation. GitHub/runtime wins.
GLOBAL_GATE: OWNER live-page UAT PASS received on 2026-09-04; Git delivery authorized; Production remains a separate hard gate
PRODUCTION_DEPLOYMENT_STATUS: NO — no Production action authorized
NEXT_DEPENDENCY: deliver/merge P-V1-M1, then activate P-V1-M2 from latest merged main
EVIDENCE_AS_OF: 2026-09-04
```

## ROADMAP HOURS

```text
CURRENT_PACKAGE_ESTIMATE: planning 12h; range 10–14h
KNOWN_CURRENT_TRACK_REMAINING: frozen Purchase OS V1 total after tracking addition = 30–44h; after this issued P-V1-M1 estimate, downstream packages remain planning-only and must not be arithmetically reduced by unaccepted actuals
ARCHITECTURE_GATE_REMAINING: 0h for this frozen P-V1-M1 contract; implementation/UAT remain
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE — current task is a later bounded Procurement track, not a re-estimation of historical MVP1
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE — not needed for this bounded track and current canonical estimate ledger not re-read for program-wide arithmetic
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE — same reason
MVP1_REMAINING: UNKNOWN WITH EVIDENCE — no safe accepted-ledger subtraction performed
POST_MVP_KNOWN_INCREMENT: Purchase OS V1 frozen current increment 30–44h
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE — no unrelated long-term horizons recomputed
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE — not safely derivable from this bounded WP
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE — accepted Work Log ledger not reconciled in this activation
ESTIMATE_CONFIDENCE: MEDIUM-HIGH for P-V1-M1
ESTIMATE_AS_OF: 2026-09-04
ESTIMATE_METHOD: Owner-frozen feature decomposition; reuse existing Order/auth/form patterns; reversible two-table foundation; allowance for migration/regression/UAT remediation
```

## ESTIMATED CODEX HOURS

```text
PREFLIGHT_AND_SCOPE: 1–2h; planning 1.5h
CORE_EXECUTION: 5.5–7h; planning 6.5h
TEST_AND_VALIDATION: 2–3h; planning 2.5h
REMEDIATION_BUFFER: 1.5–2h; planning 1.5h
ESTIMATED_EFFECTIVE_HOURS: 12h
ESTIMATE_RANGE: 10–14h
ESTIMATE_BASIS: frozen P-V1-M1 scope above, two-table reversible schema, existing Order bridge/UI reuse, no supplier/receiving/dashboard/tracking implementation in this WP
OWNER_REVIEW: INCLUDED
MERGE: INCLUDED after mandatory Owner UAT PASS and checks
MIGRATION: INCLUDED for reversible local/test migration authoring and rehearsal only
MIGRATION_FILE_AUTHORING: INCLUDED
MIGRATION_EXECUTION: EXCLUDED for Production/real data; INCLUDED only on isolated Local/UAT test database
UAT: INCLUDED for GPT/Codex preparation and Owner inspection gate; Owner waiting time excluded
DEPLOYMENT: EXCLUDED
```
