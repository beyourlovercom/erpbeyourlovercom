# ERP-HISTORICAL-FULFILLMENT-RECONCILIATION-HFR4-001

STATUS: ACTIVE
FORMAL CONTRACT: FROZEN
EXECUTION_PROVIDER: GPT_WEB + US RDC
VERIFICATION_TIER: HIGH_RISK
PRODUCTION: READ-ONLY

## Objective
Recover exact historical carrier/service evidence from real provider exports without weakening HFR-1 or inferring warehouse facts.

## Frozen source evidence
- Speeda source: `速达数据.xls`, 2,416 data rows.
- Speeda exact fields: 原单号, 转单号, 运输方式, 创建时间, 出货时间.
- Speeda source SHA evidence projection: `9467a999593517385bef07c4b70e72f4fb9341d2750546b9f8812559bf0fcc63`.
- Weship source: `纬狮数据.xlsx`, 882 data rows.
- Weship exact fields: 客户单号, 运单号, 服务商单号, 操作状态, 签入时间.
- Weship contains no service / 运输方式 field.
- Weship source SHA evidence projection: `b938cbe676c4fbccae811256068fbeb409b5c9d860287ebb459071db6a6e41b0`.

## Exact identity rules
- Speeda/Weship customer order may normalize only `C<digits>` or `C<digits>-007` to Woo numeric order id.
- Letter suffixes, doubled separators, KOL/internal ids and all other formats are not auto-normalized.
- Provider tracking must exactly equal the Woo HFR candidate tracking after trim + uppercase.
- Weship compound Woo tracking strings are not split automatically.

## Safety contract
- No Production write, schema, UI, scheduler, changelog, Accounting/Finance/P6 or Tianyun replay.
- No current routing policy may be used as historical warehouse evidence.
- Speeda may enrich carrier/service only when order + tracking are exact and Speeda explicitly supplies service.
- Weship may enrich canonical carrier identity only; service remains missing.
- Existing non-empty conflicting carrier evidence becomes Class E `CUSTOMER_REVIEW_CONFLICT`.
- Warehouse remains unresolved unless a separate exact/approved source is supplied.

## Production read-only evidence
- Speeda strict normalized exact join: 1,118 package rows / 1,117 Orders.
- Speeda safe after existing HFR conflict guards and before warehouse: 986 Orders.
- Speeda safe manifest SHA: `1bba98161f9c9a6a5b5334cefcc4e7df780c489e5ee1e1fd1aefb34ddf57194e`.
- Weship strict normalized exact join: 66 Orders.
- Weship all 66 rows are `交航`; all 66 have 签入时间.
- Weship safe manifest SHA: `13a13d3124fa2cc91ab5f6a468b6548e5a470fa1802ef51862397309cdfba39e`.
- Weship has 3 exact rows where Woo carrier says `4PX`; these must fail closed as source evidence conflicts.

## Deliverables
1. Provider evidence recovery adapters for Speeda and Weship.
2. HFR-1 classifier recognizes explicit source-evidence conflict.
3. Focused unit tests for exact normalization, tracking equality, fail-closed suffix/compound behavior and no warehouse inference.
4. Read-only Production reconciliation report after merge.

## Stop condition
If a later exact/approved warehouse source makes `READY > 0`, stop before HFR3 Production apply and present exact source SHA, plan SHA and ready/review/skip counts for Owner authorization.
