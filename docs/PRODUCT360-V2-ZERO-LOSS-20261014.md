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
