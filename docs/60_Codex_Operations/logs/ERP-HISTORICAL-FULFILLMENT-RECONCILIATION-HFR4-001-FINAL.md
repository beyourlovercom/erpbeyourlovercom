# ERP-HISTORICAL-FULFILLMENT-RECONCILIATION-HFR4-001 FINAL

## Scope
Exact historical carrier evidence recovery for Speeda / Weship on top of HFR-2 Woo candidates. Production remained data read-only.

## Real source recovery
- `速达数据.xls`: 2,416 data rows; real fields include 原单号, 转单号, 运输方式, 创建时间, 出货时间, 重量.
- Speeda service distribution proves historical service is not a single current-routing value.
- `纬狮数据.xlsx`: 882 data rows; fields include 客户单号, 运单号, 服务商单号, 操作状态, 签入时间 and weights/dimensions.
- Weship source has no service / 运输方式 field.
- PII was excluded from the normalized evidence projections used for reconciliation.

## Exact provider identity
Only `C<digits>` and `C<digits>-007` normalize to the same Woo numeric order identity.
Letter suffixes, doubled separators, KOL/internal formats and other variants fail closed.
Tracking must match exactly after trim + uppercase; Weship compound strings are not split.

## Source projection fingerprints
- Speeda projection SHA-256: `9467a999593517385bef07c4b70e72f4fb9341d2750546b9f8812559bf0fcc63`.
- Weship projection SHA-256: `b938cbe676c4fbccae811256068fbeb409b5c9d860287ebb459071db6a6e41b0`.
- Speeda strict normalized join: 1,118 package rows / 1,117 Orders.
- Weship strict normalized join: 66 Orders.
- Speeda pre-warehouse safe manifest SHA: `1bba98161f9c9a6a5b5334cefcc4e7df780c489e5ee1e1fd1aefb34ddf57194e`.
- Weship safe manifest SHA: `13a13d3124fa2cc91ab5f6a468b6548e5a470fa1802ef51862397309cdfba39e`.

## Implementation
- Added `HistoricalCarrierEvidenceRecoveryAdapter`.
- Speeda can recover canonical `4PX` + explicit historical service only from exact order + exact tracking evidence.
- Weship can recover canonical carrier identity only; service remains unresolved.
- No adapter writes or infers warehouse.
- HFR-1 classifier now treats explicit `source_evidence_conflict` as Class E conflict.
- Existing non-empty carrier disagreement is preserved and fails closed rather than being overwritten.
- Adapter source enrichment is idempotent.

## Actual Production read-only dry-run
HFR-4 enriched candidate snapshot:
- total candidates: 5,354
- Speeda enriched: 997
- Weship enriched: 66
- source-evidence conflicts: 9
- source checksum: `8333d0abd7cb493701e8f0891787fcb15b9d16e8901ab9180a8649c727f4db8c`
- source scan SHA: `4b1dd35b89a53527ecd34bdbf36c7731026a5f5320d178892fd81486d5697693`
- HFR-3 plan SHA: `c20e715ade5a6bc1fe45a50e86b35c43999043a9e381c95f6a1ce4b6640bd320`
- READY: 0
- REVIEW: 5,328
- SKIP: 26

Primary first reasons: missing_approved_warehouse 4,801; missing_exact_order_identity 471; duplicate_or_ambiguous_mapping 32; missing_exact_tracking 12; source_evidence_conflict 9; existing_shipment_conflict 2; tracking_collision 1; canonical_shipment_agrees 26.

## Zero-write proof
Before Production preview:
- shipments: 45
- audit_records: 37,257
- shipment_attempts: 1,010

After Production preview:
- shipments: 45
- audit_records: 37,257
- shipment_attempts: 1,010

No Production business-data mutation occurred.

## Warehouse semantic what-if only
Production warehouse `FACTORY` is id `5`, name `中国工厂`, active=1.
No source currently proves that every exact historical Speeda customer row used FACTORY; current routing policy is not accepted as historical proof.

A read-only simulation marked only exact Speeda-enriched candidates as warehouse FACTORY id 5:
- source checksum: `4c357867badc5c58bde15486d1710bd76828fb306909662bb5676723821a6300`
- source scan SHA: `d0f5c5f0845c0f3fd592b125aebea682e2f8a6ff4e822c77722e0e6b2e45eac9`
- plan SHA: `92665ba6245b469ae362df3045f8f016cd656cfa21a477046b3de380e6158ad4`
- READY: 974
- REVIEW: 4,354
- SKIP: 26

This simulation is NOT an authorized apply plan and must never be used for Production write without a separate Owner semantic approval and a fresh exact preflight.

## Production apply + Owner UAT closure — 2026-09-15
Owner approved the Speeda historical warehouse semantic gate for exact Speeda customer order + tracking matches, allowing `FACTORY` (`warehouse_id=5`) as historical warehouse evidence. A fresh Production preflight was run before apply; no prior what-if fingerprint was reused.

Final authorized source / plan:
- source checksum: `39e27b1d8ec895c11ec45c70880efb01ca17cb3b78b705c2ec5048148b32d528`
- source scan SHA: `29fc5844011131a9cbb9879a0816ed52cb3705a1c3e0b437e406560a5813849c`
- plan SHA: `b48450cd695582849c216c3ef1b9cad52534af4146cf75f296e1c8c0d40ba83f`
- preflight: READY 974 / REVIEW 4,354 / SKIP 26

Production apply result:
- shipments created: 974
- shipments total: 45 -> 1,019
- immutable HFR3 apply audits: 974
- target ShipmentAttempt rows created: 0
- target Woo sync outbox rows created: 0
- target Order business-state hash unchanged: yes
- verification SHA: `1676c2245106b1d43b4a50cf1b973f68cf58eac07b88814869bbf2a598053007`

Independent post-verify confirmed all 974 rows are unique customer Orders with unique tracking, `FACTORY`, `4PX`, `shipment_status=shipped`, `warehouse_selection_source=historical_exact`, and `carrier_selection_source=historical_exact`. All 974 source Orders remained `customer/shipped`.

Idempotency rerun after apply:
- READY: 0
- REVIEW: 4,354
- SKIP: 1,000
- already applied: 974

Owner UAT sample: `007-0918-c28518` / Order 1574. Production UI rendered `已发货`, `中国工厂`, `4PX`, `SD经济挂号-带电`, tracking `4PX3002183493089CN`. Owner replied `pass` on 2026-09-15.

HFR-4 is CLOSED / UAT PASS. Remaining review candidates are not silently backfilled. No changelog entry was created, per manual-only changelog policy.
