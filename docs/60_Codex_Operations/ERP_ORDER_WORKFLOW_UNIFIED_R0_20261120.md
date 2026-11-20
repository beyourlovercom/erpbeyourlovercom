# ERP 全订单工作状态统一 V1 — R0 源码盘点与实施边界

- Work Package: ERP-ORDER-WORKFLOW-UNIFIED-CONTROL-V1
- Owner visual proposal: PASS（2026-11-20）；仅授权 R0–R4 开发/UAT，不授权 Production。
- GitHub baseline: `beyourlovercom/erpbeyourlovercom@297bbd7e1cb43db1002648bff5703da05f798083`。
- Isolated US Mac worktree: `feat/erp-order-workflow-unified-control-20261120`。
- R0 execution provider: GPT Web + US Desktop Commander（只读源码盘点/本文档）。
- R1–R4 provider status: US Claude Code 登录预检 `loggedIn=false`；须 Owner 手动 OAuth；US Codex CLI 受独立 Owner rewrite-only 规则限制，不用于本工单编程。
- Current status: R0 inventory captured; R1–R4 NOT STARTED; no UI/backend implementation, tests, PR, merge, deployment or live order mutation.

## R0 已证实的不统一根因

1. `OrderWorkflowStatus::registry()` 是共同状态代码表，但 **不是** 共同的“当前订单/用户可执行命令”策略。
2. `OrderController::index()`、`show()` 和 `OrderWorkflowGridOptions::customer()` 各自组装 `workflowStatusOptions`，按页面/订单类型/权限采用不同可选条件。
3. `Orders/Index.vue` 传统表使用 `OrderWorkflowStatusPill`；客户/合作商新版 GlobalGrid 的 `status` 用只读 `GlobalGridCell(mode:'badge')`；OMS Grid 显式 `can-edit-workflow-status=false`。
4. `OrderWorkflowGridCell.vue` 将行级 `can_edit_workflow_status`、页面标记及订单类型条件叠加，导致同一订单在不同页面可编辑性不同。
5. `DomesticLogisticsController` 和 `OverseaWarehouseController` 行级允许编辑条件排除 KOL；`LogisticsController` 的分仓工作台仅放行 Customer；`TingonFulfillmentController` 另有独立规则。
6. `Orders/Show.vue` 使用详情页独立生成的 options/permissions，因此截图订单 `007-1120-k8230` 可在详情调整，却在列表呈只读“待发”。
7. `Finance/OrderAnalysis.vue` 显式 `can-edit=false`；`Orders/Master.vue` 当前展示“真实订单状态”，不是同一工作状态控件。
8. `OrderWorkflowStatusProjection` 对客户订单基于真实进度生成 `canonical_projection`，对 KOL 生成 `staff_workflow`；这两种来源不可混淆。

## 统一目标及不可突破的业务边界

- 统一 **工作状态**：各订单页使用 `OrderWorkflowStatusPill`（Grid 经薄适配 `OrderWorkflowGridCell`），同一 server-side policy 提供当前订单和当前 actor 的选项、可编辑性及禁止原因。
- 不再由具体页面为相同订单二次定义“待发/缺货”是否可选；同一 actor、order revision、权限与业务条件下，列表、详情、物流工作台结果一致。
- 允许有权限员工调整**可人工标记的工作进度**。业务上只读、由真实事件推导的状态可展示在同一下拉内（disabled + 原因），不能仅为了“全可选”放开真实事件写入。
- **真实订单状态**、真实 Shipment/Tracking、承运商回执、签收、支付、退款、Woo/OMS 记录、库存占用不因工作状态展示名称被伪造、回填或绕过对应业务流程。
- `Orders/Master.vue` 保留独立的“真实订单状态”只读列；新增/呈现“工作状态”列供符合策略的 actor 操作，不能合并两个含义不同的字段。
- 保持 `KolOrderAccessScope`、`orders.edit` 及受限 `domestic.review` / 海外 review 路径、归档/回收站限制、expected_workflow_revision 乐观锁、DB 事务、status history、audit 和仓库工作台路由跳转。
- `cancelled` 等有业务副作用的状态、`pending_allocation`、`out_of_stock`、解除异常/缺货/人工暂停等专用命令，必须继续通过后端 `OrderProgressTransitionService` 前置校验和原子事务；前端不能扩大后端权限。
- UI 只提供后端允许的动作；所有被禁止的选项应显式解释原因（权限/真实状态/业务条件），不要以无下拉/同名双标签冒充权限。

## 页面盘点矩阵（R1–R2 必须逐页落地）

| 页面/入口 | 当前组件/表现 | R2 目标 |
|---|---|---|
| Orders/Index 传统表（全部、KOL、客户、合作、外采） | `OrderWorkflowStatusPill` + Index 本地 options | 统一策略 + 单下拉 |
| Orders/Index 客户/合作 GlobalGrid | 只读 `GlobalGridCell(mode:'badge')` | `OrderWorkflowGridCell` + 同一策略 |
| Orders/Index OMS Grid | prop 被强制 `false` | 按后端策略启用单下拉 |
| Orders/Show（含 KOL/客户） | `OrderWorkflowStatusPill` + detail 独立 options | 统一策略 + 单下拉 |
| Orders/Archive | `OrderWorkflowStatusPill` + Archive 独立权限 | 展示一致，保留归档/恢复业务边界 |
| Orders/Master | 真实状态只读 badge | 保留真实列，另加工作状态单下拉 |
| Logistics/Domestic/Index | Pill/GridCell + Customer/Derived 限制 | 按统一策略 |
| Logistics/FulfillmentAllocation | Pill/GridCell + 行级编辑条件 | 按统一策略 |
| Logistics/Oversea/Show | Pill/GridCell + Customer/Derived 限制 | 按统一策略 |
| Logistics/TingonFulfillment | GridCell + 独立行级条件 | 按统一策略 |
| Finance/OrderAnalysis | Pill 被强制只读 | 展示一致，按授权策略决定是否可修改 |

## R1–R4 执行验收表

| 阶段 | 必须实现/验证 | 完成判定 |
|---|---|---|
| R1 | 后端单一 status option/permission policy；订单级 allowed/disabled/reason；沿用唯一 PATCH `/orders/{order}/workflow-status` | 详情和列表同一 order/actor 得到同一权限与选项；API 负例严格拒绝 |
| R2 | 一套 `OrderWorkflowStatusPill`，Grid 只做薄适配；覆盖上述所有页面、传统/新版表和工作台 | 所有工作状态列均为同一下拉，已禁用的真实事件不能被伪造 |
| R3 | PHP Feature、Vue/Vitest、TypeScript、路由/UI contract、`npm run build` 和 `node ops/production/verify-workspace-tabs-build.mjs` | 成功/拒绝/并发 409/归档/角色/事实保护均 PASS；30 tabs、3 pinned 无回归 |
| R4 | 美国 Mac Dev 打开全部实页：订单列表（每种 Grid）、订单详情、物流工作台、Master/Archive/Finance；针对 KOL `007-1120-k8230` 或安全同类测试单核对 | Owner 实际 Dev 页面 UAT PASS 后才可标记 complete/merge-ready |

## 必测的服务端事实与安全边界

- HTTP 正向：被授权 actor 修改可人工编辑的工作状态后，响应投影、DB 持久化、revision+1、history/audit 记录一致。
- HTTP 反向：viewer / KOL scope outsider / 错仓 review / 受限状态 / archived 或 trashed / 旧 revision / 无效状态，拒绝且 DB、Shipment、Tracking、order_status、Woo/OMS 无半写入。
- 手工选择物流/签收/退款类名称，不得建立 Shipment、Tracking、签收、付款、退款、Woo/OMS 成功的虚假证据；需要真实业务操作时只展示或指引对应流程。
- 同一订单在 `Orders/Index`、`Orders/Show`、物流列表中的 `code`、选项可执行性、actor 权限及刷新后值一致；必须测 `sending`（待发）和 `out_of_stock`（缺货），并覆盖全部 registry 代码。
- 验证刷新、跨页保存后更新、失败回滚、保存中禁用、CSRF、目标工作台跳转和实时权限降级；前端禁用不取代后端 403/409/422。
- Owner Dev UAT 不操作 Production、不使用真实发货或签收接口制造测试事实；Production 部署须单独明确 PASS。

## 阻塞与恢复条件

- 2026-11-20：`/opt/homebrew/bin/claude` 实测 `CLAUDE_AUTH_PREFLIGHT_FAILED: loggedIn=false`，强制要求真人完成 OAuth。
- US Codex CLI 虽显示已登录，但执行时依据 Owner `OWNER_CODEX_REWRITE_ONLY_2026_11_13` 拒绝编程；不得用重复请求绕开该限制。
- Owner 在美国 Mac mini 终端自行运行 `claude auth login` 并完成 OAuth，回复“Claude 登录 PASS”，即可在这个已隔离的 worktree 从 R1 继续；不得把验证码/密钥发给 GPT。
- 若无 Owner 登录，保持 R1–R4 BLOCKED；不得报告通过测试、UAT、PR、merge 或 Production。
