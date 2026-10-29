# ERP R8-P0 M1 — 统一仓库下发状态（Dev）

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
