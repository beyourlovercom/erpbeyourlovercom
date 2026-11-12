# ERP 待分仓驳回提醒 R0–R3（独立开发）

- 2026-11-12；基线：GitHub main c1d67ebe（PR #734 已合并）
- Worktree：/Users/a/ai/uat/erp-wait-stock-rejection-alerts-20261112
- 分支：fix/erp-wait-stock-rejection-alerts-20261112
- 发布范围：本地独立分支及 Draft PR；**未合并 main，未部署 Dev 或 Production，未触碰真实订单、库存及 Woo**。

## R0 来源与理由约束

1. 国内商务：订单 domestic_reject_reason/by/at；控制器验证必填 3–500 字符，服务层二次检查至少 3 字。
2. 海外仓：oversea_warehouse_reviews.status='rejected'，note/reviewed_by/reviewed_at；控制器要求非空、上限 1000 字符。状态复位后的历史理由保留在不可变 audit_records。
3. 增加统一只读投影 AllocationRejectionReadModel，不改变现有付款、库存、待分仓、删除/归档和权限门禁；保留既有海外运营通知并新增国内运营通知。

## R1 列表分类与跨页统计

- /logistics/wait-stock?rejected=1 使用**原有待分仓可操作范围**作为基集，服务端跨页筛选、分页、搜索都保留 rejected 条件；不新建业务页面。
- 全部数量仍来自未过滤的待分仓查询，驳回数量从独立数据库聚合获得，不再误用当前分页的条数。
- 增加拒绝事件版本指纹：数量相同但新事件到来时仍会提示；不保存订单敏感字段在浏览器存储中。

## R2 Global Grid 交互

- 顶部第二个 Tab「⚠ 驳回待处理 N」，点击只显示当前未处理的国内/海外退回订单。
- 默认可见「驳回状态」「驳回原因」，隐藏可选「驳回来源」「驳回人员」「驳回时间」。
- 被驳回订单整行/订单号浅黄色警示；原版表格同步显示理由；详情分仓弹窗可见原驳回来源、理由、人员与时间。
- 顶部非强制黄色提醒条：按事件版本每浏览器 Tab 可关闭；刷新不重复提醒同一事件，但新驳回会再次提醒。
- 单笔、批量成功分仓后刷新订单及驳回计数，成功订单不再显示警示。

## R3 服务闭环和本地自动化验收

- 海外仓驳回记录先持久写入不可变审计，再取消旧仓；Vita 成功重新分仓时只重置当前海外复核状态至 pending，使 Dede 能再次审核，历史原因及人员仍可从审计追溯。
- 国内通知沿用 NotificationPublisher，向有效运营角色发站内通知，直达驳回分类。
- PHP 测试：**113 passed / 1,666 assertions**（包括国内商务审核、海外工作台、待分仓、原分仓 API、查询预算、人工履约），零失败。
- Vue Vitest：**2 passed**（第二 Tab、跨页数字展示、可关闭提醒、刷新去重、同数字新事件重提醒）。
- npm run typecheck、npm run build、git diff --check、变更 PHP 文件 php -l 均 PASS。
- **未进行登录真实 ERP Production 页面或真实订单的视觉/操作验收**；正式站视觉复核与生产发布均需 Owner 单独授权。

## 安全与回退

- 不需要新迁移；无订单状态批量回填，无定时任务修改。
- 仅以 Draft PR 提供代码审查，必要时取消 PR 或回退对应 commit；不改变正式站。
