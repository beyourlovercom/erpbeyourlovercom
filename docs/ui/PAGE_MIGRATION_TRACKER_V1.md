# BYL ERP 页面 Compact Workspace 改造总表 V1.0

> 规则：[BYL_COMPACT_WORKSPACE_V1](./BYL_COMPACT_WORKSPACE_V1.md) · **唯一范围：ERP UI 布局迁移；不修改业务逻辑**
> 起始日期：2026-11-11 · 来源：menu/APPROVED_MENU.json v2.1（只证明批准的导航入口，不证明页面已运行或已改造）
> **规范已批准 ≠ 页面改造 ≠ Dev 通过 ≠ 合并 ≠ 正式发布。** 本表独立维护各门禁。

## 0. 执行原则与总体进度

- 批准的 ERP 菜单包含 **13 个一级模块、66 个二级入口**；本表以 **66 个二级入口 + 仪表盘首页 1 个条目**建立 **67 项导航入口清单**，部分入口复用组件/URL。页面详情、编辑、弹窗或隐藏工作区必须在基线审计时补录，67 并不是已核实的实际独立页面数量。
- **首批 P0 国内发货 9 个业务视图**，基于一套共享布局逐项验收，不允许复制 9 份 CSS。
- 截至 2026-11-11：**P0 国内发货九视图已完成共享布局实施、Owner 视觉 PASS、PR #712 合并和正式发布；登录后 Production 九视图视觉复核仍待记录**。其他模块另按其自身里程碑推进。
- 每次完成一个真实门禁，填写 Evidence、PR/Exact SHA、实际 Owner PASS 和生产读回；不得仅凭文档宣布页面已上线。

### 分期

| 阶段 | 工作范围 | 初始状态 |
| --- | --- | --- |
| P0 | 国内发货共享布局 + 9 视图 | **已部署 Production；生产登录后视觉复核待补** |
| P1 | 其他物流 6 入口 | 待基线盘点 |
| P2 | 同步 5 + 订单 4 入口 | 待基线盘点 |
| P3 | 客户 5 + 库存 5 + 产品 4 入口 | 待基线盘点 |
| P4 | 营销、网红、采购、会计、指南、设置、仪表盘 | 待基线盘点 |
| X | 非菜单页/弹窗 + 其他项目的独立引用 | 待逐项目审批 |

## 1. P0：国内发货九个业务视图

共同入口：**/logistics/china**。视图名称来自 Owner 确认的真实业务清单；具体 URL 中的 view 参数须实施前审计实际路由，不得猜测。

| 视图 | 视觉方案 | 共享布局代码 | Dev 验证 | Owner 真实 UAT | Git 合并 | Production |
| --- | --- | --- | --- | --- | --- | --- |

| 待审核 | 已批准（共用模板） | 已合并 #712 | 前端 54/54 PASS；浏览器结构 9/9 PASS | **Owner PASS 2026-11-11** | **已合并 281b429** | **已部署；公共健康检查 PASS；登录后视觉待复核** |
| 缺货单 | 已批准（共用模板） | 已合并 #712 | 前端 54/54 PASS；浏览器结构 9/9 PASS | **Owner PASS 2026-11-11** | **已合并 281b429** | **已部署；公共健康检查 PASS；登录后视觉待复核** |
| 待发货 | 已批准（共用模板） | 已合并 #712 | 前端 54/54 PASS；浏览器结构 9/9 PASS | **Owner PASS 2026-11-11** | **已合并 281b429** | **已部署；公共健康检查 PASS；登录后视觉待复核** |
| 今日订单 | 已批准（共用模板） | 已合并 #712 | 前端 54/54 PASS；浏览器结构 9/9 PASS | **Owner PASS 2026-11-11** | **已合并 281b429** | **已部署；公共健康检查 PASS；登录后视觉待复核** |
| 今日速达 | 已批准（共用模板） | 已合并 #712 | 前端 54/54 PASS；浏览器结构 9/9 PASS | **Owner PASS 2026-11-11** | **已合并 281b429** | **已部署；公共健康检查 PASS；登录后视觉待复核** |
| 今日纬狮 | 已批准（共用模板） | 已合并 #712 | 前端 54/54 PASS；浏览器结构 9/9 PASS | **Owner PASS 2026-11-11** | **已合并 281b429** | **已部署；公共健康检查 PASS；登录后视觉待复核** |
| 异常订单 | 已批准（共用模板） | 已合并 #712 | 前端 54/54 PASS；浏览器结构 9/9 PASS | **Owner PASS 2026-11-11** | **已合并 281b429** | **已部署；公共健康检查 PASS；登录后视觉待复核** |
| 速达订单 | 已批准（共用模板） | 已合并 #712 | 前端 54/54 PASS；浏览器结构 9/9 PASS | **Owner PASS 2026-11-11** | **已合并 281b429** | **已部署；公共健康检查 PASS；登录后视觉待复核** |
| 纬狮订单 | 已批准（共用模板） | 已合并 #712 | 前端 54/54 PASS；浏览器结构 9/9 PASS | **Owner PASS 2026-11-11** | **已合并 281b429** | **已部署；公共健康检查 PASS；登录后视觉待复核** |

**Global Grid 单独发布门禁（与上方九视图 Compact Workspace 视觉门禁不同）：**
2026-11-11 已有六个国内发货视图完成独立 Production Owner PASS：
待审核、缺货单、待发货、今日订单（PR #706）、今日速达（PR #713）、
今日纬狮（PR #721）。R4-5B 于正式站六项验收全部 PASS 后关闭。
R4-6「异常订单」仅在独立分支实施现有表格迁移、异常原因可视化、
只读「查看订单」操作；完成 PHP、Vue、隔离 Chrome 回归后提交独立 PR。
R4-6 的 Global Grid 未合并及未发布，Production 仍默认原表。
速达订单与纬狮订单历史视图继续原表默认 OFF，等待 R4-7。
该进度与上述九视图紧凑布局验收相互独立，不能混为一谈。

**P0 当前量化进度（2026-11-11）：** 九视图共享布局 9/9 已实施；Owner 原型/UAT PASS；合并 SHA `281b429`；类型检查、前端 54/54、Laravel 19/19（230 断言）PASS；Production 原子切换和新 JS 资源 HTTP 200；九个路径的**未登录重定向后** HTTP 检查 9/9 通过。**Production 真实账号内九视图视觉/交互 UAT 尚未完成，不标记全量 PROD_PASS**。详见[发布报告](../60_Codex_Operations/CW-P0-CHINA-Production-Release-20261111.md)。

**实施顺序：** G0 核对九视图和共享组件 → 首视图 Dev 实施/自测 → 复用其余八视图 → 逐视图回归 → Owner 真实 Local/UAT PASS → PR/CI → 合并 → Production 单独授权/验收。

**初步工程估计：** 共享布局/首视图 3–6 有效小时，其余八视图连贯适配与验收 3–6 有效小时，合计约 **6–12 有效小时**（以当前已有共享布局为前提，G0 后修订）。这不是已开始执行或上线承诺。

## 2. 已批准导航入口：滚动改造总清单

下表百分比只描述本次 Compact Workspace **UI 迁移验收进度**，绝不可与菜单的功能实现百分比混为一谈。

| 批次 | 模块 | 入口 | 菜单路径（待运行时核查） | UI 迁移状态 | 下个门禁 |
| --- | --- | --- | --- | --- | --- |

| P1 | 物流 | 待分仓 | /logistics/wait-stock | G0 完成 / G2 代码分支实施中；未验收、未发布 | G3 自动回归 → G4 Owner 真实 UAT → G5 生产单独批准 |
| P0 | 物流 | 国内发货 | /logistics/china | **已部署 · 公共健康 PASS** | 生产登录后九视图视觉复核 |
| P1 | 物流 | 海外仓发货 | /logistics/oversea | 0% / 待盘点 | G0 页面基线 |
| P1 | 物流 | 已发待单号 | /logistics/awaiting-tracking | 0% / 待盘点 | G0 页面基线 |
| P1 | 物流 | 运输中 | /logistics/in-transit | 0% / 待盘点 | G0 页面基线 |
| P1 | 物流 | 异常订单 | /logistics/exceptions | 0% / 待盘点 | G0 页面基线 |
| P1 | 物流 | 承运商 | /logistics/carriers | 0% / 待盘点 | G0 页面基线 |
| P2 | 同步 | 同步订单 | /sync/orders | 0% / 待盘点 | G0 页面基线 |
| P2 | 同步 | 同步仓库 | /sync/warehouses | 0% / 待盘点 | G0 页面基线 |
| P2 | 同步 | 同步网红 | /sync/kol | 0% / 待盘点 | G0 页面基线 |
| P2 | 同步 | 客户导入 | /sync/customers/import | 0% / 待盘点 | G0 页面基线 |
| P2 | 同步 | 导出平台订单 | /sync/exports | 0% / 待盘点 | G0 页面基线 |
| P2 | 订单 | 客户订单 | /customers/orders | 0% / 待盘点 | G0 页面基线 |
| P2 | 订单 | 网红订单 | /kol/orders | 0% / 待盘点 | G0 页面基线 |
| P2 | 订单 | 外采订单 | /orders/procurement | 0% / 待盘点 | G0 页面基线 |
| P2 | 订单 | 归档清单 | /orders/archive | 0% / 待盘点 | G0 页面基线 |
| P3 | 客户 | 客户列表 | /customers/library | 0% / 待盘点 | G0 页面基线 |
| P3 | 客户 | 客户分组 | /customers/groups | 0% / 待盘点 | G0 页面基线 |
| P3 | 客户 | 公司 | /customers/companies | 0% / 待盘点 | G0 页面基线 |
| P3 | 客户 | 客户订单 | /customers/orders | 0% / 待盘点 | G0 页面基线 |
| P3 | 客户 | 客户导入 | /customers/import | 0% / 待盘点 | G0 页面基线 |
| P3 | 库存 | 库存总览 | /inventory | 0% / 待盘点 | G0 页面基线 |
| P3 | 库存 | 仓库清单 | /inventory/list | 0% / 待盘点 | G0 页面基线 |
| P3 | 库存 | 产品库存 | /inventory/products | 0% / 待盘点 | G0 页面基线 |
| P3 | 库存 | 批量同步 | /inventory/sync | 0% / 待盘点 | G0 页面基线 |
| P3 | 库存 | 包装与测量 | /inventory/packaging | 0% / 待盘点 | G0 页面基线 |
| P3 | 产品 | 产品列表 | /products/list | 0% / 待盘点 | G0 页面基线 |
| P3 | 产品 | SKU | /products/skus | 0% / 待盘点 | G0 页面基线 |
| P3 | 产品 | SKU别名 | /products/sku-aliases | 0% / 待盘点 | G0 页面基线 |
| P3 | 产品 | 重量证据 | /products/weight-evidence | 0% / 待盘点 | G0 页面基线 |
| P4 | 营销 | 邮件订阅 | /marketing/subscribers | 0% / 待盘点 | G0 页面基线 |
| P4 | 营销 | 群发邮件 | /marketing/newsletters | 0% / 待盘点 | G0 页面基线 |
| P4 | 营销 | 营销活动 | /marketing/campaigns | 0% / 待盘点 | G0 页面基线 |
| P4 | 营销 | 自动化营销 | /marketing/automations | 0% / 待盘点 | G0 页面基线 |
| P4 | 营销 | 优惠促销 | /marketing/promotions | 0% / 待盘点 | G0 页面基线 |
| P4 | 网红 | 红人库 | /kol/library | 0% / 待盘点 | G0 页面基线 |
| P4 | 网红 | 网红订单 | /kol/orders | 0% / 待盘点 | G0 页面基线 |
| P4 | 网红 | 物流进度 | /kol/logistics | 0% / 待盘点 | G0 页面基线 |
| P4 | 网红 | 新增网红 | /kol/library/create | 0% / 待盘点 | G0 页面基线 |
| P4 | 网红 | KOL 审批 | /kol/approvals/kols | 0% / 待盘点 | G0 页面基线 |
| P4 | 网红 | 付费合作审批 | /kol/approvals/collaborations | 0% / 待盘点 | G0 页面基线 |
| P4 | 网红 | 佣金付款审批 | /kol/approvals/payouts | 0% / 待盘点 | G0 页面基线 |
| P4 | 网红 | 合作机会 | /kol/opportunities | 0% / 待盘点 | G0 页面基线 |
| P4 | 网红 | 合作记录 | /kol/collaborations | 0% / 待盘点 | G0 页面基线 |
| P4 | 网红 | 沟通跟进 | /kol/communications | 0% / 待盘点 | G0 页面基线 |
| P4 | 网红 | 样品与发货 | /kol/samples | 0% / 待盘点 | G0 页面基线 |
| P4 | 网红 | 内容与发布 | /kol/content | 0% / 待盘点 | G0 页面基线 |
| P4 | 网红 | 佣金与结算 | /kol/commissions | 0% / 待盘点 | G0 页面基线 |
| P4 | 网红 | AffiliateWP | /kol/affiliatewp | 0% / 待盘点 | G0 页面基线 |
| P4 | 采购 | 采购总览 | /purchase | 0% / 待盘点 | G0 页面基线 |
| P4 | 采购 | 采购文件 | /purchase/files | 0% / 待盘点 | G0 页面基线 |
| P4 | 采购 | 记录 | /purchase/records | 0% / 待盘点 | G0 页面基线 |
| P4 | 会计 | 概览 | /accounting | 0% / 待盘点 | G0 页面基线 |
| P4 | 会计 | 订单利润 | /accounting/order-costs | 0% / 待盘点 | G0 页面基线 |
| P4 | 会计 | 亏损订单 | /accounting/order-costs?profit_status=loss | 0% / 待盘点 | G0 页面基线 |
| P4 | 指南 | 全部教程 | /guides | 0% / 待盘点 | G0 页面基线 |
| P4 | 指南 | 视频教程 | /guides/videos | 0% / 待盘点 | G0 页面基线 |
| P4 | 指南 | 操作手册 | /guides/manuals | 0% / 待盘点 | G0 页面基线 |
| P4 | 设置 | 状态设置 | /settings/statuses | 0% / 待盘点 | G0 页面基线 |
| P4 | 设置 | 仓库设置 | /settings/warehouses | 0% / 待盘点 | G0 页面基线 |
| P4 | 设置 | 用户 | /settings/users | 0% / 待盘点 | G0 页面基线 |
| P4 | 设置 | 角色与权限 | /settings/roles-permissions | 0% / 待盘点 | G0 页面基线 |
| P4 | 设置 | 汇率计算 | /settings/fx | 0% / 待盘点 | G0 页面基线 |
| P4 | 设置 | 操作日志 | /settings/audit-logs | 0% / 待盘点 | G0 页面基线 |
| P4 | 设置 | 版本更新 | /settings/changelog | 0% / 待盘点 | G0 页面基线 |
| P4 | 设置 | 备份与迁移 | /settings/backup | 0% / 待盘点 | G0 页面基线 |
| P4 | 仪表盘 | 仪表盘 | /dashboard | 0% / 待盘点 | G0 页面基线 |

## 3. 里程碑状态定义

| 字段 | 必须记录的内容 |
| --- | --- |
| Scope ID | 如 CW-P0-CHINA、CW-P1-OVERSEA，稳定不变 |
| Baseline | 路由/视图、旧版核心操作、字段、规则和布局 |
| Design | PENDING / OWNER_VISUAL_PASS + Owner 确认日期 |
| Implementation | NOT_STARTED / IN_PROGRESS / DEV_READY / REGRESSION_PASS |
| UAT | PENDING / OWNER_UAT_PASS（不可由 Agent 代签） |
| Git | PR、exact head SHA、CI、合并 SHA |
| Production | NOT_AUTHORIZED / AUTHORIZED / DEPLOYED / PROD_PASS |
| Audit | 最近日期、执行人/Agent、验证证据、影响页面 |

只有取得真实 Production 验证证据才允许标记 PROD_PASS/CLOSED；文档合并只是让设计规则进入仓库，不代表任一页面已改造。

## 4. Roadmap 与后续 Work Packages

1. **CW-GOV-001（本次）**：文档化 Owner 规范，AGENTS.md 引用，建立 67 导航条目 + P0 九视图台账；纯文档，无运行时修改。
2. **CW-P0-CHINA**：国内发货九视图共用 Header/Tabs/Help Footer，功能与视觉逐项回归。必须提前提供真实页面 Owner UAT。
3. **CW-P1-LOGISTICS**：其余 6 个物流入口，同类组件统一处理。
4. **CW-P2-ORDERS-SYNC**：订单 4 + 同步 5 入口，确保状态/权限/同步动作不回归。
5. **CW-P3-CORE-DATA**：客户 5、库存 5、产品 4 入口，配合 Global Grid VI 和表单规范。
6. **CW-P4-REMAINING**：其他入口按使用频率与重要性细分，多批次迭代。
7. **CW-X-CROSS-REPO**：其他项目按自己的 AGENTS.md 明确引入，且不得覆盖自身安全和品牌需求。

任意批次：G0 路由盘点 → G1 Owner 视觉 PASS → G2 Dev → G3 回归 → G4 Owner 真实 UAT PASS → Git 合并 → 单独授权 Production → Prod 验证。禁止跳步和误报。

## 5. 变更日志

- 2026-11-11：Owner 要求待分仓默认启用已存在的 Global Grid、所有业务标签适用，右上角保留旧版切换；对照国内发货补齐可自定义列。实现限于该页面，保留原审批/库存/分仓权限，正式环境需另行授权发布。

| 日期 | 变化 | 结果 |
| --- | --- | --- |
| 2026-11-11 | 以批准的 ERP 菜单生成 67 项导航清单，单列 P0 九视图，明确门禁和优先级 | 规则已合并，分期跟踪有效 |
| 2026-11-11 | CW-P0-CHINA PR #712 合并、全员顶部 Tab 参数保留、九视图正式发布 | 已部署；公网登录/静态资源/未登录路径健康检查 PASS；生产登录后实际视觉待复核 |
