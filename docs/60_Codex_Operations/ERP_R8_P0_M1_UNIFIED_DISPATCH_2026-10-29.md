# ERP R8-P0 M1 — 统一仓库下发状态（Dev）

## 最新 Owner 规则（2026-10-29，优先于本文后面旧版段落）
- **世迈 US-SM 新建订单唯一默认渠道**：无需物流服务，OMS channelCode = No_Shipping_Service，OMS 官方接口实际只读记录 channelType = 2、channelName = 无需物流服务。不需要上传面单；不自动选择最低价、不使用 USPS 上传面单。
- **普通 API 发货只创建 OMS 草稿**：V1 outboundOrder/create 使用 isSubmit = 0。生产 Gateway::createDraftSingle 与原 createSingle 分离，应用层仅使用草稿创建。用户看见 OMS 单号不代表仓库已接单。
- **状态必须按 OMS readback 判定**：码 0 = 草稿，码 5 = 异常，无状态 = OMS 已建单 · 接单待核实；无需面单可能被 OMS 后台自动提交设置推进，不能因为请求 isSubmit=0 就保证一直是草稿。必须在生产实发前核对该 OMS 设置。
- **API 重发货**：Owner 另有需求 Issue #544，后续独立补发履约单也统一使用 No_Shipping_Service 和草稿模式，并附重发订单。该重发功能尚未实现，不得借普通重试或关闭幂等锁创建第二单。
- **旧 OMS 原订单不自动改动**。真实 OBS0272610290RS 当前草稿保留原渠道，后续如需更改须按 OMS 正式流程核实。
- **旧版章节状态**：下方 §5 对 Upload_Shipping_Label 的讨论仅保留审计历史，已废止；本节为最新有效规则。无新增真实 OMS CREATE、无 Production 写入授权。



关联 Issue #538 / PR #535。基于 GitHub main（含 Tracking Sync V2）。本变更不触发生产发货、库存同步、Woo 或 AfterShip 写入。

## 1. 冻结模型

- **订单主状态**保持 `pending_shipment` → `shipped`，既有 `Shipment.shipment_status` 和 `ShipmentService` 财务、库存及完成语义不更改。
- 独立 `warehouse_dispatch_states` 一单一 Shipment 的仓库下发读模型；`warehouse_dispatch_events` 保存真实 OMS 接受后状态转移的可审计事件。带唯一 Shipment 身份和仓库 / provider / provider_order_no 约束。
- `WarehouseDispatchStatus` 从既有 `xlwms_dispatch_attempts` 和 Shipment 状态进行保守投影，故历史 OMS 订单 **无需编造新状态或危险批量 backfill** 即能在页面正确显示；成功建单或状态更新时可逐步写穿新审计表。
- 只有可信 OMS 单号 + 已确认接单状态才能显示 `已下发 OMS`；OMS code `5` 未核实，显示 `OMS 状态 5 · 待核实`，不自动推为拣货或出库；仅已核实 code `3` 作为 `已出库 · 待回写`；`code 4` 表示仓库取消待处理；绝不根据该投影标记 Woo 或 ERP 已发货。
- 无 OMS 单号且 `batch_blocked/create_disabled` 必须仍为 `待下发`。只有经过保守认证的纯本地前置阻断可重新触发单笔预检；已发生 / 可能发生远端 CREATE 的状态不得重发。

## 2. ERP 操作入口

- 世迈仓「同步待发货订单」在正式 CREATE 门禁关闭或白名单缺失时完全拒绝且 **不插入派发尝试记录、不请求 OMS**；受控 Canary 只处理唯一获批订单。批次结果区分创建、已存在、阻断、待核实等数量，并在点击后重新读取订单列表。
- 「更新 OMS 状态」为独立权限操作：只访问已经取得 OMS 出库单号的最多 10 笔记录（按最久未检查优先），调用现有 exact OMS 状态读取，缓存核对状态并记录审计转移；不会调用 OMS `outboundOrder/create`，也不会标记 ERP shipped 或更改 Woo。
- 运营列表增加 `待下发`、`已下发` 筛选，显示中文履约标签、OMS 单号、状态原码、上次核对时间和本地阻断原因；`待发货` 原有完整队列保留。未证实的状态不得显示为成功。
- 目前出站 CREATE 特性开关及订单正向白名单依然默认关闭；**M1 没有实现 Dede 审核后无白名单自动发货**，此项须单独审批和实现。

## 3. 发布 / 数据风险

- 新增两张表为加法迁移；上线顺序：先数据库备份与迁移验证，再部署后端与构建前端；`down()` 仅供在数据库回滚方案获得明确批准后使用（会丢失新增状态审计，严禁直接在线执行）。
- 历史成功单如 `007-1027-c41863` 可使用原始 OMS 尝试记录安全投影为已下发，不必修改历史状态；`007-1027-c41845B` 的 `create_disabled` 记录投影为待下发，不能将这条本地阻断当作 OMS 接单。
- 新审计表只是附加证据，不能因审计写入失败而改写已经确认的 OMS 远端建单结果，避免错误重发；原始 `xlwms_dispatch_attempts` 唯一约束保持。
- 正式发布需 Owner 独立 `pass`，并核对真实两单和历史工作台；Dev UI/UAT/CI 通过后方可合并发布。M1 不授权 OMS 广泛 CREATE、自动标记已发货或自动 Woo/AfterShip 回写。

## 4. M1 自动化验收

- `XlwmsSingleOrderDispatchTest`：关闭标志无新尝试；单笔请求幂等；状态码 5 安全；投影/审计只更新真实身份；实际 OMS 状态刷新仅查询不创建。
- `OverseaWarehouseWorkbenchTest`：原有海外仓审核/物流流程兼容。
- `Show.test.ts`：第一笔已有 OMS 单号且状态 5 显示已下发但未发货；第二笔本地 create_disabled 仍待下发且操作入口可见；两种按钮与批次统计呈现。
- 生产 UAT 尚待后续单独审批；不能将 Dev 通过等同生产上线。

## 5. Owner-selected provider channel (2026-10-29): Upload_Shipping_Label

- Supersedes the earlier `Service with the lowest price` selection for NEW US-SM OMS outbound, without changing either existing OMS order or earlier Tracking.
- Owner explicitly selected the provider-native `Upload_Shipping_Label` (channelCode exact). Real Production read-only `logistics/channel/list` returns the code with channelName `USPS上传物流面单`, channelType=3, carrierName=`系统默认渠道`. No other native code is auto-selected.
- Provider official docs describe this as self-supplied/manual or supported-platform shipping label; it **does not guarantee USPS label purchase**, last-mile USPS carrier, or a Tracking number. Official WMS docs warn the standard upload channel may have carrier `other`. Dede/warehouse must verify that a real USPS label and tracking are supplied before fulfillment completion. A separate dedicated USPS carrier-specific upload channel configured by the warehouse may be required if the platform requires carrier=USPS rather than 'other'.
- US-SM new outbound preview uses only `Upload_Shipping_Label`; the native OMS catalog gate additionally requires channelType=3 and a USPS channelName for the exact warehouse. Never fallback to lowest-price, FEDEX/GOFO or other channel. Non-USPS ERP carrier selections require manual change/review and are blocked from automatic CREATE.
- US-SM legacy XLSX export uses `Upload_Shipping_Label`, marking USPS preference; it does not produce or transmit a shipping label itself.
- OMS CREATE may accept the upload-label channel but actual warehouse fulfillment still depends on separately supplying and verifying the USPS label. The API CREATE spec does not confirm purchasing labels, and no separate label-upload endpoint is part of this release. `WarehouseBatchDispatch` will never mark ERP shipped or sync Woo based solely on status+Tracking without verified carrier.
- The new channel does **not enable** real Production CREATE or lift strict one-order allowlisting. Production deployment and actual OMS sends require separate Owner authorization.

## 6. OMS draft and exception are NOT warehouse receipt

- Exact OMS order OBS0272610290RS transitioned in live OMS from error code 5 to draft code 0, without a new outbound. Channel remained old because saving Upload_Shipping_Label required a genuine USPS label.
- Status code 0 now maps to oms_draft / OMS 草稿 · 未提交仓库. Code 5 maps to exception / OMS 异常 · 待处理; code 3 continues to mean outgoing dispatch pending carrier review. Unknown codes do not prove warehouse receipt.
- Overview tab previously named 已下发 is relabeled OMS 已建单 because it includes unsent drafts.
- Owner required original channel save before Production release; do not mark this as done without provider readback.
