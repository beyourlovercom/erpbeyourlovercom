# ERP US-SM XLWMS R5–R9 重发货验收及 Dede SOP（2026-10-29）

## 发布基线与边界

- PR #545 在 main 中；已部署 R1–R4，5 个受限路由及两项迁移已运行。
- 默认开关：`XLWMS_REISSUE_CREATE_ENABLED=false`、`XLWMS_REISSUE_ORDER_ID=0`、`XLWMS_REISSUE_REQUEST_ID=`（空）；普通 M1 开关不构成补发权限。
- 真实 OMS 只允许创建新独立草稿，`isSubmit=0`、`logisticsChannel=No_Shipping_Service`；不覆盖原 OMS、Tracking、Shipment、Woo 状态或收入。
- 必须严格保护历史原 OMS `OBS0272610290RS`（ERP 7753）和 `OBS0272610290RT`（ERP 7766），不得在本验收中重新下发。

## R5 Dede UAT 操作说明

1. Dede 登录正式 ERP，选择「海外仓审核」→「世迈 US-SM」→「已发货」，在该订单所在行点击「重新下发仓库」。
2. 预检核对原订单、原 OMS、SKU×数量、地址、旧物流凭证、渠道 `No_Shipping_Service` 及“仅草稿”。
3. 如果出现 `source_order_not_shipped`、`original_oms_identity_missing`、库存/产品身份/快照阻断项，不继续申请，不人工绕过。
4. 输入至少 5 字原因，确认原单已发货并提交申请，记录系统生成的 UUID 和 `BYL-RESHIP-<uuid32>`。
5. Dede 二次确认；这一步只记录审批，不调用 OMS CREATE。CREATE 按钮只有独立单笔白名单通过后出现。
6. 重开页面读回申请状态、新 OMS、状态码和新 Tracking；原 Tracking 必须保留。

## R6–R8 实单 Canary 门禁

- 生产只读检查（2026-10-29）：US-SM 仓库 ID 7 当前只有 **2** 笔 `pending` Shipment，**0** 笔 `shipped`；`xlwms_dispatch_attempts` 的 **2** 条原生 OMS 记录恰好属于受保护订单 7753、7766。
- 所以当前**无可批准的真实 Canary 候选**，不可人为将 pending 改成 shipped 来凑测试，更不可用两个历史 OMS 单替代。
- 真实候选需另行给出 `order_id` 和系统新生成的 `request_id UUID`，核对原 OMS 身份及状态（仅原 OMS 状态码 3/4 可进创建路径）、新鲜库存、SKU Alias、订单指纹及仓库绑定。
- 单笔生产 CREATE 必须在授权后设置三重白名单；调用一次 `reship-create`；如结果 `create_uncertain`，不得盲重试，改为人工 OMS 原单/新单身份核实。
- 创建成功后只调用 `reship-status/{requestId}`，验证新 OMS 身份、状态和 Tracking；不得自动写回原订单和 Woo/AfterShip。
- 结束后恢复三个生产开关至默认 OFF/0/空；审计 `xlwms_reshipments` 与 `xlwms_reshipment_events`。

## R9 签收规则

- R5：前端模拟 Dede 预检→申请→审批通过；后端权限/状态/阻断测试通过。正式账号实际浏览器 UAT 待存在真实已发货 US-SM 订单后补签。
- R6：当前实单候选数为零，标记 BLOCKED_BY_REAL_WORLD_PRECONDITION；不能标 PASS。
- R7：只在隔离的 mock/SQLite 环境验证 CREATE；真实 OMS CREATE 尚未批准执行。
- R8：新 OMS Tracking 查询隔离测试有效；真实仓库无新单，不能标真实状态回写通过。
- R9：本 SOP 可用于培训/排错，但实单成功和支线 FINAL CLOSED 必须等 R6–R8 完成。
