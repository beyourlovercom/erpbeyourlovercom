# ERP R4: Exclude from Logistics vs Operational Archive - Owner Rule (2026-11-10)

Source: Owner clarification during R4 Global Grid logistics rollout. This records intended business semantics; it is NOT an assertion that the backend already implements them, and it does NOT authorize any Production data mutation.

## Two distinct actions, NOT one state

| Dimension | 移出物流队列 / Exclude from logistics | 归档订单 / Operational archive |
|---|---|---|
| Order identity and historical audit | Retain | Retain |
| Active ERP order list | Retain with clearly marked "无需物流" | Exclude by default |
| Allocation, stock confirmation, factory shipping, overseas review, warehouse dispatch | Exclude completely | Exclude completely |
| Shipment/Tracking/in-transit and automated OMS dispatch/auto-retry | Exclude, with backend gate | Exclude, with backend gate |
| Working finance/order-cost list | **Include**, calculate actual income/cost/fees/recognized costs | Exclude from **active pending-accounting list** and active operational dashboards |
| Active operations metrics | Count only where semantically valid, NEVER as unfulfilled shipment | Exclude from active workload and active operations KPIs |
| Historical immutable finance, payment settlements and posted ledger | Retain unchanged | Retain unchanged, even if excluded from active dashboards |
| Undo | Restore logistics eligibility with its original checks | Use existing authorized operational-archive restore |

"Exclude from logistics" means order remains commercially/accountingly live, but is never allocated, approved for shipment, exported as shipping files, pushed to OMS/MorePlus, tracking-synchronized or entered into in-transit queues automatically. It MUST NOT be implemented by faking shipped, completed, cancelled, Renew or archived statuses. A distinct server-side exclusion flag/query scope is required.

"Archive" means remove an order from all ordinary day-to-day work lists and their workload reports, including ordinary finance follow-up. It MUST NOT delete historical transactions, alter settled revenue/posted entries or retroactively falsify accounting. Historical audit and authorized archive list remain searchable; genuine historical financial obligations survive.

## Safety requirements for batch Grid actions

- The left Grid checkbox column selects by stable **real ERP order ID**, not visible page line number, source order number or shipment ID. Selection must honor pagination and user-specific row visibility; no implied selection of all server-side results.
- Before bulk mutation, backend re-queries every selected order under the current actor permission, with original KOL/warehouse and parent-child scopes. Per-order eligibility, current workflow version and idempotency gates checked server-side. Every item returns success or an explicit reason for rejection; one failure cannot resend another successful item.
- "批量归档" may reuse existing single-order operational-archive semantics, but needs a controlled bulk endpoint with parent/child behavior, audit actor and reversible state, and must not silently archive a shipment in external warehouse transit.
- "批量移出物流队列" requires a new dedicated lifecycle state and server guard across all logistics queues, dispatch workers, carrier exports, tracking entry, OMS dispatch and scheduling. Only visually hiding a row is insufficient.
- If an order has already been transmitted to a warehouse/OMS, has carrier acceptance, is in transit or was shipped, **do not claim the operation cancels it**. Block the action or use a separate proven cancellation/exception workflow with external acknowledgement.
- No permanent deletion, no replacing accounting facts with zero, no widening Admin/Vita/Liang/Dede/KOL/Finance authority.
- Rows directly expose the domain-specific button at pinned right; checkbox pinned left. Do not replace existing mandatory review reason or backend validation with a fabricated default reason.
- Production rollout requires separate Dev/UAT acceptance and Owner authorization.

## Existing implementation read-only check (as of 2026-11-10)

- Order::scopeEligibleForOperations already filters operational_archived_at and archived fulfillment-parent lineage; LogisticsStatusSummary uses this scope for many operational queues.
- OrderOperationalArchiveController already supports an audited single-order archive and privileged restore, but no controlled new bulk endpoint was found at the existing route level.
- Finance/OrderAnalysisController::filteredOrders and Finance/ProfitDashboardController::index use Order::query() with order-kind and historical-logistics filters and **do not presently exclude operational_archived_at** in those shown queries. This is a **functional gap** relative to the newly clarified active finance screen semantics.
- No general logistics-only exclusion flag or proven all-queues/all-worker exclusion gate was identified. Implement and test as a separate backend capability before exposing a batch button.

## Acceptance examples

1. Order A: exclude logistics => disappears from pending allocation, domestic, overseas, shipment, tracking and transit lists; **still appears in finance cost/profit list** and can be reconciled, original order facts unchanged.
2. Order B: archive => appears only in authorized archive/audit/historical financial evidence, not in operational worklists, active finance-to-do, or current operational KPI counts.
3. Order C: successful warehouse OMS dispatch or tracking already shipped => bulk exclude/archive cannot silently cancel or hide external fulfillment; return explicit blocked/exception status without duplicate dispatch.
4. Mixed batch of eligible, unauthorized and stale rows => partial successful rows each audited once; rejected rows remain with reasons; retry does not repeat prior successes.
5. Restoring an eligible archived or logistics-excluded order uses explicit authorization, reevaluates current status/warehouse safety and does not automatically send shipping instructions.
