# ERP 待分仓驳回原因列必显修复 · 2026-11-12

## 用户问题
Production /logistics/wait-stock?rejected=1 的 12 条驳回订单有黄色行与「驳回状态」，但缺少「驳回原因」可见列。Vita 无法直接判断为什么驳回。

## 原因
PR #739 已正确读取并映射 allocation_rejection.reason 至 reject_reason，全局表格也定义了该列；然而驳回 Tab 与普通「待分仓」共享 grid.logistics.allocation.global.v1 的用户级列偏好，用户先前保存的列可覆盖最新默认显隐及排列，导致驳回原因被隐藏/挪出视口。

## 最小 UI 修复
- 驳回 Tab 独立使用账号级布局命名空间 grid.logistics.allocation.rejected.v1；其它 Tab 保留原 grid.logistics.allocation.global.v1 偏好，避免员工已有设置丢失。
- 驳回 Tab 将 reject_reason 纳入该视图 requiredColumnIds，防止历史配置/新手误关；普通 Tab 中仍可自由选择是否显示。
- 驳回原因列加宽至 320px（最小 220px），保留 tooltipField 以便悬浮查看完整长原因，紧邻「驳回状态」且靠近订单号。
- 原数据契约不变：所有原因读取已有 allocation_rejection.reason（国内商务/海外仓），不增加数据库字段、接口、插件、审批或更新历史订单。

## 测试证据（独立 UAT 机器）
- Vue 功能与告警测试 21 passed；
- PHP 驳回信息/跨页/归档及待分仓查询预算 4 passed / 142 assertions；
- TypeScript npm run typecheck PASS，npm run build PASS；
- Chrome 桌面 1440×900 + 手机 390×844 E2E 2 passed，E2E_CLEANUP=PASS。
- E2E 模拟向旧的普通表格偏好存入 reject_reason.hide=true；点击驳回 Tab 后表头「驳回原因」可见且位置在 SKU 前；列设置内该列勾选且禁用关闭；黄色行、两种原因、操作列 132px、手机版无页面横向溢出、提示关闭刷新正常。
- 所有浏览器测试使用 loopback + testing 一次性 SQLite 和合成订单，不访问/写入 Production。

## 发布边界
本工作只批准正常开发、测试与 Git PR 交付；Production 发布仍需 Owner 单独明确授权。发布应基于切换时实时 Production 基线，以最小差分/构建资源方式发布，保留独立回退，不改动数据库。
