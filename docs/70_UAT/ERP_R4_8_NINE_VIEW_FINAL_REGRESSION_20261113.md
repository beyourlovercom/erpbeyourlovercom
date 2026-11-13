# ERP R4-8 国内发货九视图最终回归验收报告

**最终状态：PASS（隔离自动化 + 最新 Production 只读核查范围）**

- 日期：2026-11-13
- 物流测试基线：39318b6abde26270431aceb657c1ba8a89adb10b（PR #755）
- 验收独立分支：audit/erp-r4-8-nine-view-20261113
- R4-8 的代码没有合并 GitHub main、没有部署 Production；没有真实发货、官网同步或数据库迁移
- 起始 UTC：2026-11-13T07:42:58Z
- 任务有效运行窗口见文末精确时间记录。此时间包含工具、测试和证据整理的实际墙钟耗时，并非人工工时填报

## R4-8A — 九视图查询及归档过滤

九张表：review 待审核；stockout 缺货单；pending 待发货；today 今日订单；today-4px 今日速达；today-weship 今日纬狮；exceptions 异常订单；4px 速达累计；weship 纬狮累计。

新增 PHP 隔离测试：470 项断言 PASS。逐表核对默认 Grid、强制 Grid 与原表的订单编号、ID、物流商、Tracking、发货状态、审核状态、SKU 数量、订单总数；核对 20/50/100 分页、视图计数、历史订单、跨物流商隔离及主动归档/旧归档/软删除排除规则。

最新 Production MySQL 只读事务九视图计数（单次快照；视图有重叠，不能求和）：
- review=0；stockout=7；pending=1；today=1
- today-4px=1；today-weship=0；exceptions=4
- 4px=1021；weship=0

另有两条 FACTORY 纬狮记录，但均带运营归档标记，所以在正常物流视图显示 0 单符合冻结规则。实际查询只读且事务回滚。

## R4-8B — Chrome 跨页面与状态一致性

隔离 Chromium 全部 **23/23 PASS**，E2E_CLEANUP=PASS。
新增九视图连续切换场景：逐表进入 Global Grid、独立持久化个人列布局、右侧固定列数（速达/纬狮累计默认为 0）、返回原表、原表与 Grid 订单编号一一对应，再切回新版。原有 E2E 检查审核、缺货、待发货、今日、异常、双物流商页面、同步总表和海外仓的操作与权限。没有实际发货 POST。

## R4-8C — 模板、发货、审核和同步保护

- PHP 综合回归：**219 PASS / 1 SKIPPED，7293 断言**。跳过项是 SQLite 测试环境中仅适用 MySQL 的时区场景。
- Vue / Vitest：**145/145 PASS（8 文件）**
- TypeScript / E2E TypeScript：PASS
- Vite：PASS；ERP_WORKSPACE_TABS_BUILD_PASS，全员 30-Tab 没有被覆盖
- 速达 27 列 XLSX、纬狮 24 列 XLSX、XLS/XLSX 回传、SKU/价格分行、Tracking 冲突及重复防护、ERP Outbox/官网同步门禁、库存与审核状态、MorePlus 和海外仓业务边界均在对应隔离回归中通过

## R4-8D — 最新 Production 只读检查

执行中并行发生库存模块发布。最新 main 为 d8eadc37d2fd6082b7e9caf197d7a3225c19d7d2，正式站 current 为 erp-inventory-valid-rows-pr756-null-date-pr758-20261113。对比 PR #755 物流测试基线之后的 GitHub diff，仅库存模块的 InventoryBatchSyncService.php、InventorySyncController.php、Inventory/Sync.vue 和相关测试发生变化；国内物流控制器、九视图 Vue、订单模型、发货服务、Global Grid 和 30-Tab 源码 SHA-256 一致。

最新正式站全部九个 view 的 GET 均正确进入登录保护（跟随跳转 HTTP 200）。最新 Production MySQL 只读事务的九视图计数与先前一致。PR #755 发布回执、历史回退版本保留。本次不进行管理员登录态的实际审批、发货或回传操作；九视图的既有 Owner 逐表视觉 PASS 已由前序支线完成。

## 首轮失败诊断（均为独立测试夹具，不是生产业务 Bug）

1. 旧归档字段 archived_at 不接受普通 mass assignment，新增用例的旧归档夹具没有落库。修正夹具使用 forceFill 并确认持久化后，九视图全部 470 断言 PASS。
2. 早期 R4-L1 测试仍预期待分配新版表默认 OFF，与已批准新默认冲突。改成显式 global_grid=0 检查原表回退，PASS。
3. 早期重发场景的测试辅助函数对已审核订单重复调用 approve，后端正确返回 409。修正辅助函数，只对尚未通过审核的订单调用 approve；该业务门禁保持不变，PASS。
4. 新增 Chrome 测试读取被左侧固定的 Order 列时最初只查询 center 区域，又在异步 Vue 渲染完成前读到空文本。修正测试等待渲染并兼容 pinned/center，最终 23/23 PASS。

**无需要修改 Production 功能代码的 R4-8 业务缺陷。** 修正后的测试和本报告只留在独立验收分支，不自动合并或发布。

## 限制与结束条件

以上 PASS 限于隔离自动化及生产只读核对。由于当前 Production 的纬狮正常工作队列 0 单（两单已归档），无法以本次真实在线行来验证纬狮累计的非空渲染；已经用隔离合成数据、原表比对及此前 R4-7B Owner 视觉 PASS 覆盖。任何实单发货验收必须另获明确授权。

**R4-8A–D 在本次授权范围内完成，可以关闭这条只读回归支线。**

## 实际记录时间

结束 UTC：2026-11-13T08:03:47.314761+00:00；本次实际执行窗口 **20.8 分钟（0.35 小时）**。该计时为墙钟窗口，不是人工工时估算；原先 2–3 工程小时是预算而非本次实测。
