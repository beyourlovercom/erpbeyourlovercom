# ERP 驳回订单旧 submitted 状态兼容修复 · 2026-11-12

## 问题与真实线上证据（只读）
- 原交付：PR #739 merge aa2226c3；随 PR #741（0385f513）部署到 Production，实际 46/46 相关源码文件对齐 latest-main 发布时版本，生产 JS HTTP 与本机 SHA-256 一致。
- 上线后初检：现有 PendingAllocationQuery 为 0，但海外仓 rejected 审核记录共 17。
- 原因：其中 13 个主订单为 pending_shipment / 未分仓 / shipment pending；其中 11 个 Woo Processing 仍保留此前 fulfillment_allocations.status=submitted，另外 2 个 Woo Completed 不符合待分仓准入；剩余 4 个为 fulfillment_derived 子单。所有 11 个可恢复主单均没有 Tracking，也没有 shipped_at。
- PR739 的旧版测试仅模拟 rejected Review，没有模拟已提交旧 Allocation，因此漏过了真实数据组合。红灯确认：PHP rejectionCount 预期 1 / 实际 0；Vue 驳回 Tab 为空。不可直接将 17 个历史记录都放入队列。
- 不修改任何生产订单、审核记录、支付、库存、物流状态或订单日期。

## 最小修复契约
1. 只针对 active overseas rejection 且未分仓、shipment pending、无真实 Tracking、无 shipped_at 的订单，允许历史 submitted allocation 重新进入 Vita 待分仓；所有其他订单继续原有 payment/source/role/archived/historical/root 门禁。
2. 前端若尚有 active allocation_rejection，则旧 submitted 不再视为已处理；员工可以查看方案并通过原有后端分仓门禁操作；成功单 localSubmitted 仍立即被标识为已完成，服务端重载后重置审核状态。
3. 不重置旧 submitted 字段、不批量清理旧记录、不引入 migration 或脚本，不绕过付款/发货/拆单限制。后续真正重新分仓成功由现有 FulfillmentAllocationSubmissionService 审计和持久化完成。

## 隔离测试
- Red before fix：PHP 0 vs expected 1；Vue 中驳回 Tab 为空。
- Green after fix：PHP 105 passed / 1,803 assertions（7 组物流/订单/库存预算/归档权限相关测试）。
- Vue 21 passed（包含旧 submitted + rejected 可操作 / 已处理不误判），TypeScript typecheck PASS，Vite build PASS。
- Chrome Desktop+Mobile synthetic E2E：2 passed / 0 failed，E2E_CLEANUP PASS；合成海外驳回 fixture 已显式携带旧 submitted + pending shipment 组合。
- 额外旧测试 ManualFulfillmentIntegrationTest 的 overseas approve 409：最新 main ce1f90e0 未修改基线可复现同样失败；不属于本次修复，不宣称该全量套件 PASS。
- 生产只读 SQL 同等谓词预览：约 11 条待处理海外驳回可见；其它 Completed Woo、拆分子单不被放行。结果是瞬时快照而非稳定任务清单。

## 发布边界
- 开发分支：fix/erp-rejection-submitted-queue-20261112；基线 ce1f90e0（main 中已有 PR738 等并行工作）。
- Production 当前至少已包含 PR739 与 PR741，但不保证同步最新 main。OrderProgressQuery.php 在 main ce1f90e0 和 Production PR741 版本间已有其他支线差异；**禁止直接整库把 latest-main 覆盖 Production**。
- 本 PR 为在同一批准需求范围内的缺陷修复，正常 Git PR/merge 可按照 Owner Continuous Authorization 进行。**新的 Production 实际切换应在单独 Owner 明确授权该 Hotfix 后，按当时 live pointer 重新构建差分版本、验证后回退可用再发布。**
