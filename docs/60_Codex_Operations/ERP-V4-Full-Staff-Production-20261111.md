# ERP V4 — 全员默认启用工作台｜正式发布记录（2026-11-11）

## Owner 最终决定
Owner 明确确认实际只有约五名员工同时使用 ERP，批准 **直接全员正式开放** R3-D/D5 工作台；遇到问题由员工反馈再修复。此前要求先做五名员工单独灰度、多小时资源监控才允许全员开放的门禁，已被此条明确授权覆盖。不可再把这两个旧门禁描述为本次上线的阻塞条件；可以保留为上线后非阻塞监测建议。

## GitHub 与 Production
- 代码 PR [#705](https://github.com/beyourlovercom/erpbeyourlovercom/pull/705)，合并 SHA `7a32a0f64781de744a6fab7548762a8b54bb0ef9`。
- 正式站原 Release：`/Users/a/ai/production/erp.beyourlover.com/releases/erp-r4-3f-pr703-55846334-over-d5-20261111`。
- 全员 Release：`/Users/a/ai/production/erp.beyourlover.com/releases/erp-v4-all-staff-pr705-7a32a0f-20261111`。
- 2026-11-11 **06:23:55 UTC** 通过 `current` 原子软链接切换上线。原 Release 保留可回滚。完整发布文件 SHA-256、回滚路径和编译参数见新 Release 的 `v4-full-staff-release-manifest.json`。
- 本轮仅叠加 `resources/js/Layouts/AuthenticatedLayout.vue` 一个前端源文件，其余完整保留当时正在运行的 R4-3F + D5。没有全量覆盖 GitHub `main`。
- 与上线前正在运行的 Release 比较：**890 个业务 PHP 文件、2 个路由 PHP、36 个配置 PHP、167 个迁移 PHP** 均完全一致；`composer.json`、`composer.lock`、bootstrap、R4、D5 AG Grid/标签模块均一致。
- 未修改数据库、订单、库存、人员角色或权限；未触发真实业务 POST、仓库接口、队列回放；未重启 Scheduler、Queue、ShipOut Worker、Chrome 或 READY 设备。

## 后续每一个 ERP 生产构建都必须保留的 Vite 参数
```bash
VITE_ERP_WORKSPACE_TABS=1 \
VITE_ERP_WORKSPACE_CANARY_IDS='*' \
VITE_ERP_WORKSPACE_DEFAULT_ON=1 \
npm run build
```
这三个都是**构建时**参数，不是在运行时修改 `.env` 就能对已经构建的 JS 生效。未保留可能导致工作台被无意重新关闭。任何其他支线部署到 Production，都必须继承这些参数或明确取得 Owner 修改授权。

### 员工行为
- 所有**有权登录 ERP 的现有账号**首次加载新版资源后，默认看到同一工作台，不需要单独访问 Canary URL。
- 保留 30 标签入口、3 份最大热页面**数据快照**、最多 3 固定标签、无感数据校验、D5 搜索分页及横向滚动恢复。
- 个人当前浏览器会话关闭：`/dashboard?erp_v4_tabs=0`；重新启用：`/dashboard?erp_v4_tabs=1`。关闭状态在同一浏览器标签会话内保留；新浏览器会话按全员默认开启重新初始化。不是永久账户级开关。
- 现有已经打开的 ERP 页面可能要**刷新一次**，以加载新 JS；不是要求重新安装或重启 Chrome。
- 原先的物流、库存、审批、权限和真实发货安全规则保持原有后端校验，新的工作台只改变导航体验。

## 验收
- 隔离 US Mac mini Dev，独立 SQLite 和 4 个合成账号：运营、物流、网红管理员、超级管理员。
- 4/4 通过 Chromium 浏览器验收：不带任何查询参数进入 Dashboard 默认显示 V4；`erp_v4_tabs=0` 隐藏且刷新后保持；`erp_v4_tabs=1` 重新出现且再次进入普通 Dashboard 后保持；页面 JS 错误 0。
- 合成非管理员角色测试时，在**独立 Dev SQLite** 中补齐其 Dashboard 阅读权限，正式站账号权限未改变。
- 独立 Dev `npm run typecheck` PASS；编译启用参数的 Vite build PASS。正式站 Release 亦 PASS。
- 切换后 `/login`、未经认证 Dashboard 跳转链、当前 `/build/assets/app-CnvDuyCn.js` 全部 HTTP 200。此 HTTP 结果不替代真实员工认证后的操作验收。
- 2026-11-11 **06:24:49 UTC** 只读复核：`current` 指向全员 Release、manifest=`DEPLOYED_ALL_STAFF`，Scheduler/Queue/ShipOut LaunchAgents 均 running；Redis 普通队列与 ShipOut 批次队列采样数均为 0；已有错误日志最近更新时间在本次发布之前，未在发布阶段发现新增错误。
- 独立 UAT Laravel 进程已按 PID/CWD 校验并关闭，测试账号凭据文件删除，端口 56990 已释放。

## 如需回滚
- 首先核对 `current` 是否**仍然**指向 `erp-v4-all-staff-pr705-7a32a0f-20261111`；若已经被其他并行发布更新，停止，不能覆盖别人的版本。
- 如当前仍属于此 Release，核对上一个 `erp-r4-3f-pr703-55846334-over-d5-20261111` 完整存在，再原子切换软链接回旧版本。不要清空生产 Redis、重放 ShipOut、删除订单数据或重启后台 Worker。
- 遇到单个员工浏览器体验问题，优先使用该账号 `erp_v4_tabs=0` 暂时关闭，不影响其他员工，确认原因再做小范围修复。

**当前状态：全员正式开放，Owner 明确同意问题出现后反馈修复；长期 D6 监控为非阻塞运营建议。**
