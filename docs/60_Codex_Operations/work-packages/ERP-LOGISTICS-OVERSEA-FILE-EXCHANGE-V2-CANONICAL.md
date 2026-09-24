# ERP Logistics Overseas File Exchange V2 — Canonical

Status: DESIGN FROZEN / IMPLEMENTATION NOT STARTED
Date: 2026-09-20
Base: `main@cdd0468e4c1a73517326075c3381ff06b70e8420`
Branch: `feature/erp-logistics-oversea-file-exchange-v2`

## 1. Business workflow

Canonical owner flow:

`Vita 分仓 -> Dede 审核 -> 下载对应仓库真实 XLSX -> 上传仓库系统 -> 仓库生成 Tracking -> Dede 上传原始回传 XLSX -> Preview -> Apply -> Shipment=shipped -> Woo/AfterShip downstream`

Owner rule: Dede 上传带 Tracking 的回传文件并确认 Apply 后，ERP 即认定已经发货。仓库后台仍处于“仓库处理中”或 Tracking Status=Pending 不要求第二次上传。

## 2. File naming

Outbound filename body is frozen:

`007-{warehouse}-orders-{YYMMDD}.xlsx`

Examples:
- `007-us-ny-orders-260920.xlsx`
- `007-us-moreplus-orders-260920.xlsx`
- `007-us-3sm-orders-260920.xlsx`
- `007-de-tianyun-orders-260920.xlsx`
- `007-ca-orders-260920.xlsx`

## 3. Canonical order identity

ERP sends the customer/business order reference into each provider's reference field. Do not invent a second ERP-facing order-number system.

Provider mapping:
- US-NY: `Reference Number/参考单号`
- US-SM: `Reference Number/参考单号`
- DE: `Reference Number/参考单号`
- US-LA MorePlus: `Order NO`
- CA: `客户参考号`

Return matching is exact/fail-closed using the corresponding provider reference/order field. No fuzzy name/address/SKU matching.

## 4. Export format families

### 4.1 WMS-64 family — US-NY / US-SM / DE

One order = one row. Header A:BL is identical across the three real templates. Template exposes `SKU1..SKU20`, but Provider instruction is frozen at **max 10 SKUs** for V1. SKU11..SKU20 are reserved and must not be used until Provider explicitly confirms support.

Required provider profile values:

| ERP warehouse | Provider warehouse code | Provider shipping service | Provider carrier field |
|---|---|---|---|
| US-NY | `US01` | `USPS-NJ` | blank unless required |
| US-SM | `Yangfan` | `Service with the lowest price` | `USPS` |
| DE -> DE | `Hannover` | `DHL PAKET DOMESTIC` | blank unless required |
| DE -> non-DE supported Europe | `Hannover` | `DHL PAKET International N` | blank unless required |

US-SM owner rule: ERP canonical carrier is USPS; warehouse chooses the concrete final service. Export expresses USPS carrier and uses provider auto-selection service.

### 4.2 MorePlus-23 family — US-LA

Template has 23 columns. Multi-SKU orders are block rows:
- first row: `Order NO` + first SKU/Qty + complete recipient/order data;
- continuation rows: `Order NO` blank, only SKU/Qty populated.

Default `Request Shipping Service = Selected by Warehouse`.
`Warehouse Customized Service` remains supported by the provider template but is not required for V1.

### 4.3 Canada-69 family — CA

Template `OutboundConsignment` has 69 columns. One order = one row, up to `SKU1..SKU5`.

Provider profile:
- warehouse = `CA02_多伦多仓`
- default logistics product = `CAPOST[加拿大邮政多伦多]`
- customer reference = ERP customer/business order reference
- inventory quality for normal sellable stock = `G  良好`

Do not use stale hidden UK warehouse lists as authority; the active CA mapping is present in the provider validation data.

## 5. Return parser families

### 5.1 WMS return — US-NY / DE

Parse by header names, never fixed column indexes. Core fields:
- `Reference order No./参考单号`
- `Tracking No./物流跟踪号`
- `Carrier/承运商`
- `Shipping service/物流渠道`
- `Outbound Order No./出库单号`
- `OutboundTime/出库时间` when present

The US-NY and DE exports are the same WMS return family with different extra columns; one normalized parser must handle both.

### 5.2 MorePlus return — US-LA

Core fields:
- `Order#` = ERP business reference
- `Outbound Order#` = provider order reference
- `Master Tracking#`
- `Tracking#`
- `Requested via`
- `Ship Via`
- `Carrier`
- `Shipped Time` optional

Rows with blank `Order#` are continuation/item rows and must not become independent shipments.

Tracking resolution:
- only one of Master/Tracking present -> use it;
- both present and equal -> use it;
- both present and different -> BLOCK for review; never guess.

`Shipped Time` is optional. If absent, Apply time becomes canonical `shipped_at` per Owner business rule.

Live canary 2026-09-23 confirmed a second valid MorePlus return shape: the provider may return the original 23-column outbound `Template` with `Tracking Number` populated. This **outbound round-trip** variant is accepted only when the full 23 headers match the frozen MorePlus outbound contract exactly. Its match key is `Order NO`; its Tracking field is `Tracking Number`; `Shipping Carrier` and `Request Shipping Service` are treated as provider evidence when present. The same unique-order, existing-Tracking-conflict, review-approved and Apply safety gates still apply.

### 5.3 Canada return — CA

A real post-shipment Canada provider file was recovered on 2026-09-24. It has one `SHEET` with the exact 7-column header:

`订单号 | 包裹序号 | com_lab_3015 | 长(cm) | 宽(cm) | 高(cm) | 重量(kg)`

Contract:
- `订单号` = customer/order match key per Owner business rule; it must resolve uniquely through the existing ERP identity map or the row is **BLOCKED**;
- `com_lab_3015` = Tracking Number;
- `包裹序号` and parcel dimensions/weight are Provider evidence only;
- the file has no reliable Carrier or shipped-at field, so ERP keeps the reviewed Shipment carrier and uses Apply time for `shipped_at`;
- the parser accepts only the complete exact 7-column header. The older one-column `订单号` sample remains invalid and fail-closed;
- a real historical sample whose `订单号` is not present in current ERP must preview as **BLOCKED / ERP 找不到该订单号**. Never infer a match from Tracking, package sequence, recipient data, or row position.

Owner UAT PASS 2026-09-24. PR #419 merged and the Canada return parser is Production Active. Real provider file Production preview parsed 3 rows and safely BLOCKED all 3 historical rows because their order references are not present in the current ERP; no business data changed.

### 5.4 US-SM return

No real tracking-return template exists. Do not invent one. Batch Tracking return remains fail-closed until a real provider return sample is supplied.

This does **not** block US-SM daily fulfillment. The canonical V1 production path is:

`approved Shipment → real US-SM outbound XLSX → warehouse processing → warehouse supplies real Tracking → Dede enters Tracking on the US-SM pending row → explicit 确认发货 → Shipment shipped → Woo Tracking sync`

Rules:
- a real Tracking value is required before manual confirmation;
- manual confirmation is allowed only for an approved pending Shipment whose current warehouse is canonical `US-SM`;
- the existing reviewed Carrier/service is preserved and `shipped_at` is the ERP confirmation time;
- successful confirmation advances Overseas Review progress to `shipped` and immediately attempts canonical Woo Tracking outbound;
- Woo sync failure must not erase the already-committed ERP shipment truth; it is surfaced for follow-up;
- the file-return endpoint remains blocked for `us-3sm`; never infer a return schema from outbound files, historical generic warehouse files, recipient data, row position, or other providers.

## 6. Normalized return contract

All parsers must normalize into one internal row contract:

```text
warehouse_key
order_reference
provider_order_reference?
tracking_number
carrier?
provider_service?
provider_status?
shipped_at?
source_row
```

Then reuse one Preview / Apply engine.

## 7. Apply semantics

Preview is read-only and classifies each row as:
- `apply`
- `noop`
- `blocked`

Block at minimum on:
- no exact order/shipment match;
- wrong warehouse;
- Dede review not approved;
- invalid tracking;
- duplicate order row with conflicting tracking;
- existing shipped truth conflicts;
- MorePlus Master/Tracking conflict;
- ambiguous multi-package identity that cannot be mapped exactly.

Apply:
- uses existing `ShipmentService::markShipped()`;
- supplied provider shipped time is used when valid;
- otherwise current Apply time is used;
- local shipment truth commits first;
- existing Woo/AfterShip downstream remains canonical;
- no requirement for a second warehouse upload.

## 8. Canonical carrier vs provider service

Do not overwrite Carrier Master service identity with warehouse-internal service codes.

Examples:
- canonical `USPS` vs provider `USPS-NJ`
- canonical `USPS` vs MorePlus `USPS Ground Advantage`
- canonical `DHL` vs provider `DHL PAKET International N`
- canonical `Canada Post` vs provider `CAPOST[加拿大邮政多伦多]`

V1 may display provider service in Preview/evidence without mutating canonical Shipment service unless an explicit mapping exists.

## 9. Implementation minimization

Do not create five independent engines.

Target components:
- `Wms64OutboundExporter` — US-NY / US-SM / DE
- `MorePlusOutboundExporter` — US-LA
- `CanadaOutboundExporter` — CA
- `WmsTrackingReturnReader` — US-NY / DE
- `MorePlusTrackingReturnReader` — US-LA
- `CanadaTrackingReturnReader` — CA
- existing `OverseaTrackingReturnService` refactored to consume normalized rows

No new Export Batch schema in V1 unless implementation proves existing review/audit state cannot safely represent the workflow.

## 10. UAT target

For warehouses with a confirmed provider return protocol, Dede must be able to perform:
1. approve warehouse-assigned orders;
2. open one warehouse page;
3. download that warehouse's real XLSX format with frozen filename;
4. upload the untouched provider return XLSX;
5. inspect `apply / noop / blocked` preview;
6. confirm Apply once;
7. see Tracking + shipped state in ERP;
8. verify no second upload is requested.

For US-SM / 世迈, the equivalent confirmed production path is manual Tracking + explicit `确认发货`; batch return stays fail-closed until a real provider sample exists.

## 11. Production closure — 2026-09-24

Overseas Logistics V1 is closed for daily staff operations.

Production status:
- MorePlus / US-LA: real outbound + real Tracking return + real C2 shipment/Woo round-trip canary PASS;
- US-NY: real WMS outbound/return contract active;
- DE / 天运: real WMS outbound/return contract active;
- CA: real outbound + exact 7-column Tracking return protocol confirmed and Production preview PASS;
- US-SM / 世迈: real outbound active; no provider return template exists, so manual Tracking + explicit confirm-shipment path is Production Active; batch return remains fail-closed by design.

PR #424 merged at `2ed179c89755e8ee18e0538323589b255e888b7a` after Owner UAT PASS. The bounded Production overlay deployed the dedicated US-SM manual shipment route/UI without migrations, Woo mutations, or business-data writes during deployment. Post-deploy route/smoke PASS.

Read-only Production audit immediately after deploy found canonical `US-SM` warehouse id 7 with zero active operational shipments (`all=0 / pending=0 / approved_pending=0 / shipped=0 / exceptions=0`), so no real US-SM canary order existed to execute. This does not block staff entry; the first real approved US-SM order becomes the natural live canary.

No Production business-data writes are authorized by this document.
