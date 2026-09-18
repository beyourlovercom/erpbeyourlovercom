# ERP-ACCOUNTING-V11C-P8.1-MULTI-SHIPMENT-COST-AGGREGATION-V1-001

**Status:** CLOSED / PRODUCTION ACCEPTED / CONTINUOUS DELTA ACTIVE / FINAL REPLAY CONVERGED
**Parent:** ERP Accounting V1.1C P8 Production Activation
**Purpose:** make multi-shipment customer-order profit calculation correct, auditable, and visible before final Accounting V1.1C closure.

## 1. Canonical accounting principle

1. **Product costs are Parent Customer Order-owned.**
2. **Logistics costs are Shipment-owned.**
3. **Profit is Parent Customer Order-aggregated.**
4. Fulfillment-derived orders are execution artifacts, not independent profit orders.
5. No cost may be duplicated merely because fulfillment is split into multiple shipments.

## 2. Product cost ownership

Canonical formula:

`product_cost = SUM(parent_order_item.quantity × canonical_unit_product_cost)`

Rules:
- calculate product cost exactly once on the parent customer order;
- include every parent order item and its full quantity;
- product cost must remain complete even when fulfillment is split into A/B/C/... derived legs;
- derived fulfillment orders must never independently materialize product cost into parent profit;
- product-cost source remains the canonical Product Cost Evidence / cost-book mapping effective for the order date.
## 3. Shipment-owned logistics cost

Each shipment/fulfillment leg calculates its own logistics facts.

### Overseas warehouse shipment

For shipment `S` with `Q` shipped product units:

`first_mile(S) = Q × USD 1.00`

`handling(S) = Q × USD 1.00`

`last_mile(S) = actual carrier billing for shipment S`

Examples of last-mile evidence include USPS, DHL, MorePlus/provider carrier billing or another confirmed carrier bill.

### China factory / China direct shipment

`first_mile(S) = 0`

`handling(S) = 0`

`last_mile(S) = actual international parcel/carrier cost for shipment S`

Factory/China warehouse introduces no additional warehouse or handling fixed cost under the frozen Owner rule.
## 4. Parent aggregation

For parent customer order `O` with shipment set `S1...Sn`:

`first_mile_cost(O) = SUM(first_mile(Si))`

`overseas_handling_cost(O) = SUM(handling(Si))`

`last_mile_cost(O) = SUM(last_mile(Si))`

`product_cost(O) = SUM(parent items unit cost × quantity)`

Order-level costs remain parent-owned and are not copied to shipment legs:
- payment fee;
- KOL commission;
- refund / replacement loss;
- customs tax / other variable order facts where applicable.

Canonical profit:

`known_cost = product_cost + warehouse_cost + first_mile_cost + overseas_handling_cost + last_mile_cost + kol_commission + payment_fee + customs_tax + refund_replacement_loss + other_variable_cost`

`contribution_profit = net_revenue - known_cost`

Final profit remains unconfirmed while required evidence is missing or blocked.
## 5. Formula visibility — mandatory UX

Finance must show the actual calculation used for every customer order, not only the final number.

The Order Profit view / drawer must expose a readable calculation ledger such as:

`Product Cost = SKU-A 2 × 10.00 + SKU-B 1 × 15.00 = 35.00`

`First Mile = Shipment #101 (US-LA, 2 units × 1.00) + Shipment #102 (US-NY, 1 unit × 1.00) = 3.00 USD`

`Handling = Shipment #101 (2 × 1.00) + Shipment #102 (1 × 1.00) = 3.00 USD`

`Last Mile = Shipment #101 USPS 8.40 + Shipment #102 DHL 6.20 + Shipment #103 China parcel 12.50 = 27.10 USD`

`Known Cost = Product + Warehouse + First Mile + Handling + Last Mile + Commission + Payment Fee + ...`

`Profit = Net Revenue - Known Cost`

For every component show:
- formula / basis;
- amount and currency;
- source/evidence reference;
- shipment / warehouse identity when logistics-owned;
- status: confirmed / estimated / missing / blocked;
- provenance and source timestamp when available.
## 6. Current gaps this WP must close

Current main behavior is not accepted for multi-shipment customer profit because:
- carrier billing reader blocks when an order has more than one canonical shipment;
- overseas-handling reader blocks when an order has more than one canonical shipment;
- fulfillment-origin reader blocks when an order has more than one canonical shipment;
- current first-mile rule is `USD 1.00 per overseas order`, not `USD 1.00 per shipped product unit`;
- fulfillment-derived orders are excluded by the Finance customer-order scope blocker;
- current `order_cost_entries` remains one row per `(order_id, cost_type)`, therefore aggregation must occur before parent materialization;
- split fulfillment currently has execution limits that may be narrower than real N-shipment operations.

## 7. Required implementation direction

Do not turn fulfillment-derived orders into standalone profit orders.

Introduce a parent accounting aggregation layer that:
1. resolves the canonical parent customer order;
2. enumerates all fulfillment legs / shipments belonging to that parent;
3. reads each shipment's warehouse, shipped item quantities, handling evidence and carrier billing;
4. calculates shipment-owned cost components independently;
5. performs FX conversion using the existing Accounting FX snapshot contract;
6. sums each logistics cost type across all legs;
7. writes one parent `order_cost_entries` row per existing cost type;
8. preserves source references/audit detail sufficient to reconstruct every shipment contribution.
## 8. Acceptance scenarios

Must pass at minimum:

1. Single overseas shipment, one product.
2. Single overseas shipment, multiple products/quantities.
3. Single China-direct shipment.
4. Parent order with 2 shipments from two warehouses.
5. Parent order with overseas + China-direct split.
6. Parent order with 3+ shipments when execution model supports it.
7. Four products split across three shipments: product cost counted exactly once across all four parent items.
8. Two shipments using different carrier currencies with valid FX snapshots.
9. Missing carrier bill on one leg blocks final last-mile completeness instead of silently treating it as zero.
10. Re-running materialization is idempotent and does not duplicate parent cost entries.
11. Strong manual/actual facts are never overwritten by weaker estimates.
12. Formula UI reconstructs every displayed parent total from visible line items and shipment contributions.

## 9. Closure rule

P8.1 is closed when implementation/regression evidence is green, Production replay is idempotent, continuous activation is explicit, duplicate parent cost rows remain zero, and any unavailable live multi-shipment canary is recorded rather than fabricated.

ERP Changelog remains manual-only.

## 10. Implementation progress — 2026-09-17
- Added opt-in Parent→N Shipment evidence resolution across fulfillment-derived child Orders while preserving legacy single-Shipment behavior until P8.1 activation.
- First Mile is now previewed as `USD 1.00 × shipped units` per overseas Shipment and `0` for China-direct; Handling follows the same per-Shipment rule.
- Actual last-mile evidence converts each Shipment billing fact to transaction currency before Parent aggregation; mixed source currencies are never summed first.
- Parent aggregate source references are deterministic fingerprints of participating Shipment/evidence facts; identical replay becomes noop.
- Existing strong/manual/different provider facts fail closed to review and are not silently overwritten.
- Added dry-run-first `finance:materialize-multi-shipment-costs` with deterministic `plan_sha`, Order/Shipment counts, apply/noop/blocked/review counts and per-cost-type delta summary. Production apply remains separately gated and disabled by default.
- Production continuous delta uses the canonical flag `ERP_ACCOUNTING_MULTI_SHIPMENT_AGGREGATION_ENABLED=true`; `ERP_ACCOUNTING_MULTI_SHIPMENT_PRODUCTION_APPLY_ENABLED` remains unset. Historical bulk rewrites remain separately gated.
- Estimated / incomplete canonical facts can never produce final confirmed profit: `canonical_final_ready` requires existing Accounting `profit_status=confirmed` and every canonical cost line to be Confirmed.
- Added canonical Order Profit explainability service and `/accounting/order-costs/{order}` page. Product lines, Shipment contributions, FX/source facts, parent-level costs and final formula are generated from the same materialization evidence rather than frontend business logic.
- Finance list exposes a dedicated `成本公式` entry while preserving the existing manual fact editor.

## 11. Local validation — 2026-09-17
- P8.1 focused matrix: **8 PASS / 70 assertions** covering two overseas Shipments, overseas+China hybrid, three Shipment/N-leg model, mixed-currency per-leg FX, missing carrier bill fail-close, strong-fact review, dry-run plan guard, formula evidence reconciliation and HTTP/Inertia formula route.
- Finance full regression: **105 PASS / 822 assertions / 1 MySQL-only skip**.
- P8.1 + Fulfillment split regression: **20 PASS / 191 assertions**; existing A/B derived-order, gift-SKU and allocation semantics remain green.
- Vite production build: **PASS** (`936 modules transformed`).
- Architecture exact-main delta: branch **3 fail / 5 pass / 131 assertions**; exact `main@d99ab97ac44d999c34caa6cd803042431d30da26` is the identical **3 fail / 5 pass / 131 assertions** with the same three failing rule names. Architecture regression delta = 0.
- `git diff --check` and PHP lint: PASS.
- No Production data writes, migrations, or P8.1 activation occurred during implementation.

## 12. Production closure — 2026-09-18
- Initial P8.1 implementation merged in PR #346. Production activation uses `ERP_ACCOUNTING_MULTI_SHIPMENT_AGGREGATION_ENABLED=true`; the Production bulk-apply flag remains unset.
- Historical materialization was executed only through explicit guarded gates; post-apply replay converged and duplicate `(order_id, cost_type)` groups remained zero.
- Final Closure audit detected immutable-FX replay drift: unchanged multi-shipment evidence could be re-evaluated by current Settings FX, including 1,084 zero-delta rewrites and one EUR carrier-bill revaluation.
- PR #360 (`6d2b701b64521ad9aaf3f390fd77ce50a22d93b4`) fixed replay semantics so an unchanged `multi-shipment:v1:*` evidence fingerprint preserves its frozen `OrderCostEntry` posting snapshot. Real evidence changes still produce a new fingerprint and remain apply-eligible.
- PR #360 validation: P8.1 focused **11 tests / 97 assertions PASS**; Finance full **133 tests / 953 assertions PASS / 1 MySQL-only skip**.
- Production code-only deploy of PR #360 preserved env/config and performed zero Production data writes.
- Final Production dry-run after the hotfix: `orders_seen=6281 / shipments_seen=1860 / apply=0 / noop=3858 / blocked=14983 / review=2`; first-mile `noop=1858`, handling `noop=1858`, last-mile `noop=142`, with no remaining cost delta.
- No eligible live Parent with a true `shipment_count > 1` existed at closure time. A live N-shipment visual canary is therefore evidence-unavailable, not fabricated. The N-leg model remains covered by focused 2-leg/3-leg/mixed-currency/fail-close tests and must be re-observed on the first genuine live multi-shipment Parent.
- P8.1 is accepted as closed with that first-live-N-shipment observation recorded as a non-blocking post-closure canary.
- ERP Changelog intentionally remains unchanged because publication is manual-only.
