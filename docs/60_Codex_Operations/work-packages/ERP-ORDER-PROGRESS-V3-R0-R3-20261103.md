# BYL ERP Order Progress V3 — R0–R3 (Dev only)

**Owner decision (2026-11-03):** Plan B; R0–R3 core flow first, Owner UAT after; R4–R6 only after explicit subsequent pass. No UI redesign, no extra features, no Production edits, no historical order mutation.

## R0 Invariants
1. Exactly one **primary operational progress** is displayed for a fulfillment segment. It is a *read-only projection of authority*: allocation, active shipment, warehouse review, business review and tracking records. It must not become a separate redundant writable truth.
2. Order `workflow_status` remains the staff note/business-phase store for existing KOL use cases until R4 migration of auxiliary flags. Older staff annotations are not backfilled or erased by R0–R3.
3. Changes through order detail must use existing permissioned business operations; selecting "Sent" cannot forge shipped_at, shipped status, Tracking or warehouse evidence.
4. Vita allocated -> Kaven domestic business review -> Liang pending/final shipping -> Tracking. Overseas: Vita allocated -> Dede warehouse review -> fulfillment. Existing dedicated buttons and routes remain valid and delegate to the same service rules.
5. Historical inconsistencies are projected as **Needs reconciliation / 状态待对账**, not auto-reopened, exported, archived or auto-synchronized. Existing 8 anomalous 4PX orders are not mutated.
6. Domestic "ready for shipping" = factory assigned + Shipment pending + domestic_reviewed marker + not domestic_stockout + workflow != out_of_stock. Overseas "ready" = matching approved warehouse review + Shipment pending (subject to provider-specific queue gates).
7. A "today" provider list = provider-approved pending shipment OR truly shipped today. List **eligibility for export is narrower than inclusion**. Do not show "可导出" for shipped/non-approved items.
8. A split fulfillment child owns its progress; parent is a non-executable aggregate. Do not force child's progress onto parent, and do not mutate FIFO inventory evidence.

## Mapping / Transition matrix
| Current authority | UI main progress | Transition authority |
| --- | --- | --- |
| No assigned warehouse or rejected allocation | Pending Allocation / 待分仓 | Vita allocation service |
| Warehouse assigned and not approved | Pending Review / 待审核 | Kaven or Dede review service |
| Warehouse assigned, pending, approved | Sending / 待发货 | Liang / warehouse dispatch service |
| Factory pending, stockout | Out of stock / 缺货 | Kaven business review |
| Shipment shipped, trustworthy event, Tracking missing | Sent / 已发等单号 | Real outbound evidence only |
| Shipment shipped with Tracking | Shipping / 运输中 | Tracking evidence |
| Fulfillment complete | Completed / 已完成 | Shipment completion semantics |
| Conflicting canonical/Shipment/review/evidence | Reconciliation / 状态待对账 | Separate controlled repair |
| Auxiliary follow-up / KOL phase | Existing annotation | Staff / KOL existing workflow, not a shipment transition |

## Scope and acceptance
- R1: Add shared resolver / transition gateway and tests for unsafe manual transitions; preserve existing route contract.
- R2: Reuse domestic and overseas approved, stockout, rejected, shipped services from order status UI where authorized; no duplicate shipping writes.
- R3: Share pending/review/stockout criteria between domestic, overseas and order progress filters, plus show truth-based export eligibility. No page redesign.
- Manual Dev UAT: verify Vita -> Kaven -> Liang, Dede flow, stockout, rejection, direct dropdown guards, split view, historical conflict.
- Release gate: separate Owner approval after UAT; never merge main or deploy Production as part of R0–R3.
