# ERP-ACCOUNTING-ORDER-PROFIT-V1-001

STATUS: OWNER_LOCAL_UAT_PASS / A3-A6 IMPLEMENTED / READY_FOR_MERGE
EXECUTION_PROVIDER: US_CODEX_CLI
BASELINE: origin/main@5d7a694a1e510d3ff91c38260e84751018aec35e (reconciled into Accounting branch)
BRANCH: feature/erp-accounting-order-profit-v1-001
WORKTREE: /Users/a/AI/007/worktrees/erp-accounting-order-profit-v1-001
VERIFICATION_TIER: HIGH_RISK
PARALLEL_SAFE: YES
DEPENDENCY_TYPE: MERGED_PR + EXTERNAL_DATA

## Objective

Upgrade the inherited manual `/accounting/order-costs` foundation into the canonical Order Profit V1 workspace over real ERP/Woo facts, without creating a second Order truth and without manufacturing missing costs.

Canonical Order anchor remains `orders.id`. Historical `historical_logistics` Orders are excluded from operational accounting by default.

## Hard rules

- Missing authoritative facts remain missing; never zero-fill them to produce profit.
- Product standard costs are reference-only and never historical authoritative cost.
- `shipping_revenue != shipping_cost`; `tax_collected != tax_cost`.
- No fuzzy Affiliate/Order matching.
- Only approved provenance may produce confirmed profit.
- No Production deploy or Production accounting write is authorized by this package.
- New visible UI implementation is blocked until Owner visual PASS.
## A1 canonical runtime / data reconciliation

Production current release remains:
`/Users/a/AI/production/erp.beyourlover.com/releases/logistics-historical-isolation-20260905-230740-960e65fe`

Operational population:
- Total Orders: 6808.
- Eligible Woo customer Orders: 6043.
- Historical/KOL materialized Orders excluded: 765 (`historical_logistics`).
- Order Items: 8686; line revenue non-null 8686; positive 7259; 6036 eligible Orders have positive line revenue.
- Operational Woo currencies: USD 4477 / EUR 817 / CAD 258 / GBP 247 / AUD 244.
- `order_financials`: 0; `order_cost_entries`: 0.
- Product reference-cost coverage: 0 / 247.
- Shipments: 0; shipment carrier billing: 0.
- Procurement Orders / Items: 0 / 0.

Affiliate provider mirror:
- Referrals: 3351.
- Affiliate/KOL bound: 2416.
- Order-resolved: 0; unresolved: 3351; `order_id` non-null: 0.
- pending 95 / unpaid 835 / paid 1582 / rejected 228 / failed 610 / draft 1.

FX:
- 4477 eligible Orders are USD.
- 1566 eligible Orders are non-USD and have no accounting transaction FX snapshot today.
## A2 Order Profit V1 Fact Gap Matrix — frozen

| Profit fact | Canonical owner/source | Production V1 state | Confirmed-profit eligibility |
|---|---|---|---|
| Merchandise list amount | Woo `line_items[].subtotal` | Source available; not persisted | eligible after exact inbound evidence |
| Product/net line revenue | Woo `line_items[].total` / ERP item evidence | ERP partial evidence available | analytical only until settlement reconciliation |
| Discount amount | Woo `discount_total` | Source available; not persisted | eligible after exact inbound evidence |
| Shipping revenue | Woo `shipping_total` | Source available; not persisted | eligible after exact inbound evidence |
| Tax collected | Woo `total_tax` | Source available; not persisted | separate from commercial revenue |
| Refund amount | Woo `refunds[].total` | Source available; not persisted | eligible after exact inbound evidence |
| Refund tax | Woo `refunds[].total_tax` | Source available; not persisted | separate tax reconciliation fact |
| Provider order total | Woo `total` | Source available; not persisted | reconciliation fact, not standalone commercial revenue |
| Payment method | Woo `payment_method` | Source available; not persisted | evidence only; fee still missing |
| Product cost | Accounting snapshot from approved actual cost source | missing | blocks confirmed profit |
| Shipping cost | Logistics carrier billing | missing | blocks confirmed profit |
| KOL/Affiliate commission | AffiliateWP referral mirror | provider facts exist; 0 Order-resolved | excluded until exact Order relation exists |
| Payment fee | provider/manual | missing | blocks confirmed profit unless explicitly not applicable by frozen rule |
| Customs/tax cost | Accounting/provider/manual | missing | blocks confirmed profit unless explicitly not applicable by frozen rule |
| Other variable cost | Accounting | manual/optional | zero only by explicit confirmed not-applicable/zero fact, never implicit |
| FX snapshot | Accounting transaction snapshot | missing | blocks confirmed USD aggregate for 1566 non-USD Orders |

Current confirmed-profit eligible Orders: **0**.
## Revenue posting contract

Woo source is authoritative for provider settlement components. Current inbound loses these fields, so V1 must preserve exact Woo evidence before Finance posting.

For Woo customer Orders:
- `merchandise_list_amount` = sum of Woo line `subtotal`, excluding line tax.
- `discount_amount` = Woo `discount_total`.
- `shipping_revenue` = Woo `shipping_total`.
- `tax_collected` = Woo `total_tax`, stored separately from commercial revenue.
- `refund_amount` = absolute sum of Woo refund `total`, preserving refund tax separately.
- provider `total` is preserved as a reconciliation fact; it must not cause discount/refund/tax to be deducted twice.
- payment method and paid timestamp are source evidence, not proof of payment fee amount.

Commercial Revenue V1:
`merchandise_list_amount - discount_amount + shipping_revenue - refund_amount`

Provider total reconciliation validates the component projection within decimal tolerance and preserves mismatches as explicit exceptions.

A Production sample confirmed the source shape: Woo Order 40219 exposes total, shipping, discount, tax, payment method and paid time while ERP currently persists none of those settlement components. A refunded sample confirmed Woo order total and refund facts remain separate.

## Finance projection status

Order Profit status is Finance-owned and never overloads `orders.order_status`:
`UNPOSTED | INCOMPLETE | ESTIMATED | CONFIRMED | RECONCILED`.

Projection must expose component statuses plus `missing_facts[]`; an incomplete Order is UNKNOWN for profit/loss classification.
## Cost / provenance foundation

V1 remains Order-level first. Existing `order_cost_entries` is expanded rather than replaced.

Every active cost fact must preserve at least:
- `cost_type`, original amount/currency and optional base USD amount.
- provenance: `actual | confirmed_manual | provider_import | allocated_actual | estimated_reference`.
- confirmation/status and effective/source timestamps.
- exact source reference/evidence where applicable.
- creator/confirmer for manual facts.
- transaction FX rate/date/source when normalization is required.

Existing immutable Audit remains the V1 correction history for active order-level cost snapshots. Advanced item-level immutable allocation/versioning remains compatible but is not a blocker for first Order-level V1.

Estimated/reference facts may be displayed but never silently upgraded to confirmed actual cost.

Affiliate commission is recognized only after exact canonical `order_id` resolution and frozen provider status mapping. Current 3351 unresolved Referral rows contribute **zero confirmed Order commission facts** until exact resolution exists.

`EcbFxService` is reference/display-only and is not a transaction FX authority. Cross-currency confirmed USD profit requires a preserved transaction/posting FX snapshot.

## Query / isolation contract

- Base population: customer/Woo operational Orders.
- Mandatory exclusion: `whereDoesntHave` canonical external reference `provider=historical_logistics`.
- Server-side pagination/filter/sort; default 50 rows/page.
- No PHP filtering over the complete Order population.
- No historical Orders in current KPI, loss count, margin, country or SKU performance.
## Pre-development visual proposal contract

Evolve the existing `/accounting/order-costs` page in place into **订单利润 / Order Profit**. Do not create a duplicate parallel page.

Top hierarchy:
1. Page title + short rule: incomplete facts do not produce confirmed profit.
2. KPI strip: eligible operational Orders / confirmed profit Orders / incomplete Orders / completeness % / FX-pending Orders.
3. Compact filter toolbar: search, date, country, currency, completeness, missing fact.
4. Global Data Grid V1.1 workspace.

Default grid columns:
`订单号 | 日期 | 国家 | 币种 | 商品收入 | 折扣 | 运费收入 | 退款 | 净商业收入 | 产品成本 | 物流成本 | 其他成本 | 总成本 | 利润 | Margin | 完整性 | 缺失项 | 操作`

Interaction:
- Order number links to canonical Order detail.
- Product/SKU evidence links to canonical Product when shown in detail.
- Fixed identity column left, fixed actions right, middle columns configurable through canonical `…` Column Settings.
- `成本补录 / 查看事实` opens the bounded Finance detail/editor; manual cost provenance is explicit.
- Missing values render `— / 缺失`, never `0.00` unless zero is an explicit authoritative fact.
- Confirmed loss is visually distinct only after completeness is CONFIRMED/RECONCILED.

Current UI defects corrected in this V1: stale source vocabulary, all-row `get()` loading, lack of canonical Grid V1.1 behavior, and hidden reasons for incompleteness.
## Implementation slices after Owner visual PASS

1. Add exact Woo settlement evidence persistence/projection for the frozen component fields; keep inbound idempotent and re-sync safe.
2. Expand Finance schema/model for revenue provenance, completeness/status and transaction FX snapshot compatibility.
3. Expand order-level cost entries with provenance/status/source/FX evidence without creating a second cost/order truth.
4. Implement Order Profit completeness projection and missing-fact reasons.
5. Upgrade `/accounting/order-costs` query to historical isolation + server pagination/filter/sort.
6. Implement the approved Data Grid V1.1 UI and bounded Finance fact editor.
7. Add confirmed-only loss projection and KPI aggregation.
8. Run focused Finance/Woo tests, architecture regression, typecheck/build, migration rehearsal and Local/UAT.

## Expected file scope

Primary ownership:
- `app/Modules/Finance/**`
- `resources/js/Pages/Finance/**`
- Finance-specific migration(s), tests and this Work Package.

Minimal shared boundary only when required for exact Woo evidence:
- `app/Modules/Orders/Application/Inbound/NormalizedInboundOrder.php`
- `app/Modules/Orders/Application/Inbound/WooCommerceOrderNormalizer.php`
- inbound-safe persistence adapter/service added additively.

Avoid edits to Logistics, Product, Inventory, Procurement, menu baseline and unrelated Order workflows. Any newly detected shared-file collision requires fresh reconciliation before mutation.
## Acceptance criteria

- `orders.id` remains the only Order truth.
- All 765 historical Logistics materialized Orders are excluded from operational P&L by default.
- Woo revenue components have exact provider provenance and no discount/refund/tax double counting.
- Every cost fact has explicit provenance; missing cost never becomes implicit zero.
- Completeness explains exact missing facts and uses UNPOSTED/INCOMPLETE/ESTIMATED/CONFIRMED/RECONCILED semantics.
- Only approved complete facts produce confirmed profit and confirmed loss classification.
- 1566 current non-USD operational Orders cannot enter confirmed USD aggregation until an FX snapshot exists.
- Affiliate commission contributes only after exact canonical Order resolution.
- Order Profit page uses server-side pagination/filter/sort and Global Data Grid V1.1.
- Canonical Order/Product links work from the workspace/detail projection.
- Existing manual Finance behavior remains recoverable through explicit Finance editing, with provenance/audit.
- Focused Finance/Woo tests, architecture regressions, typecheck/build and migration rehearsal PASS.
- Owner pre-development visual PASS is required before visible UI implementation.
- Owner live Local/UAT PASS is required before closure.
- Production deploy and any Production backfill/write remain separate explicit Owner gates.

## Owner visual gate — 2026-09-06

- Owner reviewed the pre-development Order Profit V1 visual proposal and returned `pass`.
- Visible UI implementation is authorized within this frozen Work Package scope.
- Production deploy and Production data writes remain unauthorized.

## Owner Local/UAT gate — 2026-09-06

- Owner reviewed the isolated US Mac `/accounting/order-costs` UAT runtime and returned `pass`.
- Final accepted editor interaction: right-side drawer, table remains in place, top-right close control, drawer-local scrolling, two-column revenue/cost VI on desktop.
- Production deploy and Production accounting writes remain unauthorized.

## Implementation / verification evidence — 2026-09-06

- Latest-main reconciliation: merged `origin/main@5d7a694a1e510d3ff91c38260e84751018aec35e`; PR #225 changed only Historical Logistics/date-correction paths and has no Accounting/Woo overlap.
- Woo settlement evidence, provenance/completeness, cost snapshot fields, original-currency profit semantics, Grid V1.1 pagination, canonical Order/Product links and manual Finance correction compatibility are implemented.
- Focused Finance + Woo verification: 100 assertions, 0 failures; 10 Woo cases are warning-only because of inherited file-read warnings.
- MVP1 Finance regression subset: 43 assertions, 0 failures; warning-only inherited file-read notices remain.
- Frontend `vue-tsc --noEmit`: PASS. Production Vite build: PASS, 914 modules transformed; existing large-chunk warning only.
- Isolated SQLite migration rehearsal after latest-main merge: fresh UP PASS → rollback PASS → reapply PASS.
- `git diff --check`: PASS.
- Full Architecture test remains blocked by pre-existing stale frozen module/application/migration file lists that omit already-merged KOL, NotificationCenter, Procurement, Inventory and Historical Logistics paths; Accounting-specific migration/Vue hashes are updated.
- Full MVP1 regression has one pre-existing Logistics failure: `shipment.reopened` Audit count remains 0 because `snapshotAndReset()` resets the Shipment before the later changed-state check. `ShipmentService.php` and that regression test are byte-identical to `origin/main`; Accounting does not widen scope to repair Logistics.
- Production remains untouched. No Production migration/backfill/deploy is authorized.

## Current gate

`A1_CANONICAL_RECONCILIATION = COMPLETE`
`A2_FACT_CONTRACT = FROZEN`
`A6_PRE_DEVELOPMENT_VISUAL = OWNER_PASS / 2026-09-06`
`A3_A4_A5_A6_IMPLEMENTATION = COMPLETE`
`ACCOUNTING_FOCUSED_VERIFICATION = PASS`
`LOCAL_UAT = OWNER_PASS / 2026-09-06`
`PRODUCTION_WRITE = NOT_AUTHORIZED`
PROJECT POSITION
PROGRAM_ROADMAP: Architecture COMPLETE → Core Operations COMPLETE → Product/Woo/Logistics real-data foundation COMPLETE → Accounting / ROI Expansion CURRENT → Production Hardening / Expansion WAITING
CURRENT_TRACK_PHASE_MAP: A1 COMPLETE → A2 FROZEN → VISUAL PASS → A3/A4/A5/A6 IMPLEMENTATION + FOCUSED VERIFICATION COMPLETE → LOCAL UAT CURRENT → A7/A8 WAITING → PRODUCTION GATES
COMPLETED_PHASES: A1 canonical reconciliation; A2 fact contract; A3 completeness engine; A4 Woo revenue projection; A5 cost snapshot foundation; A6 Order Profit UI implementation + focused verification
CURRENT_PHASE: FINAL CLOSURE / CI / MERGE
CURRENT_WORK_PACKAGE: ERP-ACCOUNTING-ORDER-PROFIT-V1-001
PACKAGE_STATUS: OWNER_LOCAL_UAT_PASS / READY_FOR_MERGE
WAITING_PHASES: final closure/CI/merge; A7/A8; Production release/data gates
BASELINE: origin/main@5d7a694a1e510d3ff91c38260e84751018aec35e (reconciled into Accounting branch)
GITHUB_STATE: dedicated branch/worktree active; Draft PR #222; merge-candidate implementation prepared; Owner Local UAT pending
SOURCE_CONFLICTS: AI/CURRENT_CONTEXT is stale versus current GitHub; Accounting Contract example base currency differs from later frozen USD contract and later Owner-frozen USD contract governs; repository Architecture frozen file lists are stale versus current main; latest-main MVP1 Logistics reopen Audit regression is outside Accounting scope
GLOBAL_GATE: OWNER_VISUAL_PASS_SATISFIED / LOCAL_UAT_OWNER_PASS_REQUIRED_BEFORE_CLOSURE
PRODUCTION_DEPLOYMENT_STATUS: NO — current package authorizes neither Production deploy nor Production accounting writes
NEXT_DEPENDENCY: final PR #222 CI/merge closure; then A7 Confirmed Loss Orders
EVIDENCE_AS_OF: 2026-09-06

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: planning 8.0 effective hours; range 5.5–12.0
KNOWN_CURRENT_TRACK_REMAINING: 8.0 planning hours before Owner wait time; Production backfill/deploy excluded
ARCHITECTURE_GATE_REMAINING: 0.5 planning hours for visual freeze / implementation handoff
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE — this package does not re-estimate historical MVP1
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE — not required to alter Accounting V1 scope
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE
MVP1_REMAINING: UNKNOWN WITH EVIDENCE
POST_MVP_KNOWN_INCREMENT: Accounting V1 only, 8.0 planning hours
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE — no accepted Work Log ledger issued for this package yet
ESTIMATE_CONFIDENCE: MEDIUM
ESTIMATE_AS_OF: 2026-09-06
ESTIMATE_METHOD: frozen scope + existing Finance/Woo foundation + one additive migration/UI upgrade
ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 1.0 planning hour; range 0.5–1.5
CORE_EXECUTION: 4.5 planning hours; range 3.5–7.0
TEST_AND_VALIDATION: 1.5 planning hours; range 1.0–2.0
REMEDIATION_BUFFER: 1.0 planning hour; range 0.5–1.5
ESTIMATED_EFFECTIVE_HOURS: 8.0
ESTIMATE_RANGE: 5.5–12.0
ESTIMATE_BASIS: Frozen Order Profit fact contract; existing Finance manual foundation; additive Woo evidence + Finance schema/projection + Grid V1.1 upgrade
OWNER_REVIEW: INCLUDED
MERGE: INCLUDED
MIGRATION: INCLUDED
MIGRATION_FILE_AUTHORING: INCLUDED
MIGRATION_EXECUTION: INCLUDED — Local/UAT only; Production execution excluded
UAT: INCLUDED
DEPLOYMENT: EXCLUDED