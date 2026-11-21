# ERP 全订单工作状态统一 V1 — R0 源码盘点与实施边界

- Work Package: ERP-ORDER-WORKFLOW-UNIFIED-CONTROL-V1
- Owner visual proposal: PASS（2026-11-20）；仅授权 R0–R4 开发/UAT，不授权 Production。
- GitHub baseline: `beyourlovercom/erpbeyourlovercom@297bbd7e1cb43db1002648bff5703da05f798083`。
- Isolated US Mac worktree: `feat/erp-order-workflow-unified-control-20261120`。
- R0 execution provider: GPT Web + US Desktop Commander（只读源码盘点/本文档）。
- R1–R4 execution provider: Owner 于 2026-11-20 明确禁止 Claude，要求自建 DC；实际使用 GPT Web + 正式 OAuth US Desktop Commander，仅在隔离工作树编辑、构建、测试。未使用 Claude / Codex CLI。
- Current status: R0–R3 implementation and focused verification completed in isolated branch; R4 real US Mac Dev page preparation and Owner UAT **PENDING**. No Production deployment or live order mutation; no Owner UAT PASS claimed.

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

## R1–R3 隔离实施与验证证据（2026-11-20）

- 新增 `OrderWorkflowStatusPolicy`：同一订单/actor 的 registry 完整选项、逐项 `mutable` / `disabled_reason` 和 `can_edit`；页面不再本地创造可执行解除命令。
- 各订单入口、总表/归档/国内与海外分仓/天运/财务行载荷接入该策略；统一 `OrderWorkflowStatusPill`（Grid 薄适配）。工作状态可人工调整，真实订单、Shipment、Tracking、签收及付款事实仍分离。
- Rebase 最新 `origin/main@79d09408` 无冲突；核心 PHP Feature + 上游批量待分仓 Feature 复测 PASS（399 assertions；36 framework warnings）。HTTP 含授权/403、版本/history、无 Shipment 伪造和列表/详情权限投影。
- Vue 状态控件和订单/物流页面合计 244/244 PASS；分仓新工作状态列单测 1/1 PASS；国内列表、海外仓及上游 Global Grid 回归在同一版本通过。
- `npm run typecheck` PASS；`npm run build` PASS；workspace verifier 输出 `ERP_WORKSPACE_TABS_BUILD_PASS`（30 tabs / 3 pinned 合约保持）；`git diff --check` PASS。
- 分仓完整 Vitest 仍有 5 个原有分仓决策/礼品断言失败；在未经修改的精确 baseline `297bbd7e` 独立工作树同样复现 5 个失败，不是本次状态列引入。不要误报整仓测试为绿色。

## R4 实页 UAT 与交付门禁

- 已通过自建 DC 在 US Mac 建立独立 Dev runtime `http://127.0.0.1:18894`；应用通过临时环境变量连接 `/private/tmp/erp-order-workflow-unified-r4-20261120.sqlite`，该库从本机现有**合成 UAT 快照**复制，含 129 条模拟单、Vita/Liang/Dede/Emma/Guts/Hiko 测试角色；未连接 Production DB。
- 已在独立 Playwright Chrome 实例（不重启或关闭原 Chrome）登录 Vita、Dede、Emma、Owner、Liang、Guts 测试会话，打开总表、客户/KOL 列表与详情、归档、国内/海外仓、待分仓、天运、财务、合作商等实页。Owner 财务页 200 / 50 个状态下拉；Vita 总表 200 / 24 个、客户传统表 200 / 50 个、KOL 详情 200 / 1 个；部分无可见行的 Grid/权限受限角色本来可能是 0，下拉数量不等于页面失败。
- 客户新版 Global Grid 的状态列遵循个人列显示偏好与 AG Grid 横向虚拟化：Vita 测试账号通过“列设置”启用工作状态并滚动至该列后，真实页面呈现 **21 个**统一可编辑下拉。国内待审核合成数据在正确加载后亦显示统一下拉；不把屏幕外的虚拟列误判为权限只读。
- **R4 自动交互 PASS（合成订单 `007-1115-00006`）**：Vita 在 KOL 旧列表把 `sending` → `out_of_stock`（PATCH 200），详情页读回同一 29 项目录后改回 `sending`（PATCH 200）；revision 0→2、2 条 history；Shipment 原本已有且仍为 `pending`，`tracking_number`/`shipped_at` 仍为空。没有创建真实发货事实。
- 用户截图真实订单 `007-1120-k8230` 不在隔离合成数据库中；未对该 Production 单做任何修改。Owner 仍须在已打开的真实 Dev 页面检查视觉与权限，并明确返回 `PASS`；否则 R4 不得标记 complete/merge-ready。Production 仍需独立明确发布授权。
- 2026-11-20 早期 Claude OAuth 预检失败是历史记录；Owner 随后明确改用自建 DC，故 Claude 登录不再是本工单的前置条件，也不允许使用 Claude。

## R4-UX 补充：Owner 视觉 PASS（2026-11-21）

- Owner 指出完整 29+ 项且附长禁用原因的下拉过长，批准默认只列当前可操作状态及当前状态。2026-11-21 实页复核进一步明确：所有订单列表、订单详情、物流页全部移除状态旁「…」，不再展示额外只读项；只有「设置 → 状态设置」设计页保留「…」。绝不伪造真实发货/签收/付款事实。
- Owner 批准「设置 → 状态设置」可统一修改全部状态的中文/英文名称与数字序号，序号按分段整数比较（如 1.2 在 1.10 前），不允许重复序号、不改变内部 code、不可动态创建业务状态。
- Owner 补充视觉 PASS：状态设置默认仅展示「数字序号」「状态名称」，「…」临时展开功能、适用页面、英文名称及内部代码；刷新后自动折叠。复用现有设置导航，权限限定 `system.permissions.manage`。序号修改离开输入框重新排序，保存为整批审计变更，可恢复默认。
- 实施：新增仅存覆盖值的 `order_workflow_status_display_settings` 表，`OrderWorkflowStatusDisplayCatalog` 在请求内一次性读取并统一排序；所有 Registry 投影、列表/详情/物流/财务选项自然共用；`OrderWorkflowStatusPill` 不再硬编码阶段号和冗长禁用文本。
- 技术边界：新表不存 Order/Shipment/Tracking/Payment 等业务真相；状态修改 PATCH/API/权限、业务审核工作流、既有 audit/status histories 均不被此显示设置替换。创建迁移并仅对 **US Mac 合成 UAT SQLite** 执行；Production 迁移/发布仍需 Owner 明确授权。
- R4-UX 验收应覆盖：admin 管理页 GET/保存/禁用重复序号/默认恢复/错误回滚/越权 403；同一账号跨列表与详情新名称和序号一致、全部订单状态组件没有多余「…」、权限只读状态不能成为可选操作；订单及 Shipment 表内容前后保持不变。自动 Dev 浏览器回归通过。2026-11-21 Owner 在 US Mac mini 隔离 Dev 实页确认 **PASS**，包含仅设计页保留「…」的最终修订；本次签收覆盖此前 R4 真实 Dev UAT 待验收记录，但**不包含合并或 Production 发布授权**。
