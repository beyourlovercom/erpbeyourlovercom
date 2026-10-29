# R8 Issue #544 — API 重发货独立补发单（开发进度）

## 基线与隔离
- 功能从 PR #540 代码构建在独立分支 `feature/erp-r8-issue544-reship-v1-20261029`，PR 不应先于 #540 合并。
- 用户需求：已发货订单点击「API 重发货」，创建**新的** OMS 订单；SKU、数量、收件信息、世迈仓与原单相同；备注必须包含「重发订单」；No_Shipping_Service、isSubmit=0 先草稿。
- 不允许重用原来的第三方订单号、原 Tracking 或覆盖主 ERP 订单已发货状态；新补发仓库成本单独归属，不产生第二笔营业额。
- 操作必须为 Dede 单笔操作，两次明确确认与独立订单白名单，异步未知结果不重试，严禁复用普通发货的 `xlwms_dispatch_attempts` 防重记录。

## 当前实际交付：只读 UX + schema + preview（不能真实发 OMS）
- Additive `xlwms_reshipments` 表定义独立 request_id、third_order_no、原订单与原 Shipment FK、原 OMS 与新 OMS 身份、SKU 数量快照、原因、备注明细、请求者/审批者、时间戳、状态与错误字段。唯一索引保障新单身份。
- 新独立 `ReshipmentPreview` 会检查源订单确实 shipped/completed 且 Shipment shipped、有真实原单发货证据、US-SM 正确、订单 SKU/OMS 绑定、库存/仓库快照、No_Shipping_Service+isSubmit=0；对已存在的任意待查补发阻断盲目再创建。
- Dede 原有海外仓表新增「API 重发货」入口（仅 shipped），点击获取只读预检、显示原 OMS、SKU、收件信息、No_Shipping_Service、重发订单备忘与阻断原因，明确「正式重发未开启」。
- API `GET .../orders/{order}/xlwms/reship-preflight` 受 `can:logistics.operate` + `RequireLogisticsExecution` + throttle 保护；没有任何创建路由或 OMS 新单写入权限。
- 因官方公开创建 API 的嵌套字段未呈现备注字段名，不能猜测 `remark`/`notes` 就声称已写入 OMS；**真实重发 CREATE 尚未完成**。

## 下一批开发
1. 与世迈确认 `outboundOrder/create` 的出库单备注精确字段及长度；校验在 OMS 后台完整可见。
2. 创建独立补发意图与独立引用、精确 fingerprint，库存重复扣减/补发成本安全核算；新增二次确认与受控实际 CREATE API 端点，永不按原单自动重发。
3. OMS exact-reference 查询、unknown outcome manual reconciliation、OMS 备注回读、独立履约状态/Tracking/费用回写，不重写原 Woo completed 与发货收入。
4. Dev Dede 浏览器 UAT、MySQL 8/SQLite CI、按一张真实白名单订单独立 Canary 授权、Production 发布及回滚验证。

## 严格门禁
本提交只实现**只读预检**，没有给用户可点击的真实补发 CREATE 按钮。不可把预检通过误写成仓库建单成功。生产变更要求 Owner 单独 pass。

## R8 API 重发货下一批：独立补发申请门禁（Dev only）
- 新增受限 POST `/logistics/oversea/us-3sm/orders/{order}/xlwms/reship-intent`，仅 `logistics.operate` + logistics execution 权限、2/min；需填写 >=5 字的理由及明确确认原订单已经发货。
- 从源订单加锁后再次预检，按每次请求生成独立 UUID、`BYL-RESHIP-` 第三方单号、payload fingerprint 和 `重发订单` memo，记录独立成本待核算、审计事件。
- 已有活跃申请必须阻断第二次申请；该功能**不调用 OMS API，不修改原订单、原 Shipment、Woo 状态、收入或 Tracking**，仅供 Dede 未来两阶段确认流程的底层意图。
- 真实 OMS 创建仍缺官方可核验的备注字段合同、审批后 CREATE 门禁、首次真实白名单；不得将这一段视作真实重发上线。
