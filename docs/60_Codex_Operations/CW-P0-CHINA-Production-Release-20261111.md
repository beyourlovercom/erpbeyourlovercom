# CW-P0-CHINA：国内发货九视图 Compact Workspace V1 正式发布记录

- **Date:** 2026-11-11
- **Owner:** 已明确 PASS，授权正式发布；特别要求保护顶部全局 Workspace Tabs
- **Rule:** `BYL_COMPACT_WORKSPACE_V1`
- **Source PR:** [#712](https://github.com/beyourlovercom/erpbeyourlovercom/pull/712)
- **Merged SHA:** `281b429238f71982688c17be0190a8b1fa8fd519`
- **Production active since:** 2026-11-11 07:57:47 UTC
- **State:** `DEPLOYED / PUBLIC_HEALTH_PASS / AUTHENTICATED_PRODUCTION_VISUAL_REVIEW_PENDING`

## 1. 发布路径及回滚

- 当前 Release：`/Users/a/AI/production/erp.beyourlover.com/releases/erp-cw-p0-china-pr712-281b429-20261111`
- 原 Production Release（保留，供回滚）：`/Users/a/ai/production/erp.beyourlover.com/releases/erp-wait-stock-pr711-15942e34-over-pr715-20261111`
- 原子切换：`/Users/a/AI/production/erp.beyourlover.com/current` 软链接
- 机器上完整可读发布元数据：当前 Release 根目录 `cw-p0-china-pr712-release-manifest.json`；其中含精确叠加文件哈希、编译参数、原版回滚地址
- 在回滚前**必须**只读检查 `current` 仍指向本次 Release；若已由其他支线接管，则停止、重新评估，不得覆盖其他发布。
- 回滚只涉及将软链接原子切换回仍然完整存在的原 Release；不操作数据库、不重放队列、不重启 Chrome/READY 设备。

## 2. 最小变更、顶部 Tabs 防回归

从**当时正在运行的正式 Release**复制完整候选目录，而不是全量覆盖 GitHub main；仅叠加以下三个前端源文件：

1. `resources/js/Pages/Logistics/Domestic/Index.vue`
2. `resources/js/Components/UI/CompactWorkspaceToolbar.vue`
3. `resources/js/Components/UI/CompactWorkspaceHelp.vue`

逐文件 SHA-256 比较：其他 **282 个已有 resources/js 文件**与原 Production 完全一致；尤其 **WorkspaceTabs.vue、AuthenticatedLayout.vue、workspaceTabs.ts、workspaceHotCache.ts 均字节完全一致**。另外 **890 app PHP、2 routes PHP、36 config PHP、167 migration PHP** 均一致。无数据库迁移，无真实订单/物流/库存数据写入，无 Worker/Chrome 重启。

**必须保留的 Vite 编译环境：**

```bash
VITE_ERP_WORKSPACE_TABS=1 \
VITE_ERP_WORKSPACE_CANARY_IDS='*' \
VITE_ERP_WORKSPACE_DEFAULT_ON=1 \
npm run build
```

新发布产物 `AuthenticatedLayout-BEBSCnFb.js` 的编译内容保留了 `byl:workspace:canary:user:`、`*` 全员白名单、`erp_v4_tabs` 显式开关及默认开启条件。只增国内发货的紧凑布局，不改变导航、全局 Tab、热缓存、固定标签和员工权限。

## 3. 验收与可靠性边界

**Dev / UAT：** TypeScript PASS，54/54 Vue 测试 PASS（国内发货、顶部 TopBar、GlobalNavigation、AuthenticatedLayout），Laravel 国内业务回归 19/19、230 断言 PASS，Vite 生产参数构建 PASS。隔离 US Mac mini SQLite 真实 Chrome 浏览器中，顶部 Workspace Tabs 在国内视图切换前后保留，`erp_v4_tabs=0` 隐藏和 `erp_v4_tabs=1` 重新出现均 PASS；九个国内视图结构 PASS。

**Production：**
- 上线后回环 Nginx /login HTTP 200，新的工作台 JS HTTP 200。
- 公网 IPv4 /login HTTP 200（约 0.51 秒），新的 Domestic Index JS 和 AuthenticatedLayout JS 均 HTTP 200。
- `/logistics/china?view=...` 九个视图的未登录跳转链均返回最终 HTTP 200。这仅证明 HTTP/路由可达，不构成登录后九视图的视觉或按钮验收。
- Scheduler、Queue、ShipOut、Nginx 的 LaunchAgent 条目仍在，未由本次发布重启。
- 上线后首次未强制 IPv4 的公网 /login `curl` 曾在 20 秒超时；紧接着本机 Nginx、后续公网 IPv4 和九路由检查均成功。不能把首次失败隐瞒，也不能在缺少证据时认定根因与页面改动相关。

**当前未完成的单独门禁：** 用真实员工账户确认 Production 九视图整体视觉以及顶部标签的实际使用体验，至少检查进入国内发货、切换两个视图、返回其他工作标签、固定标签/休眠等行为。如有异常，在 Issue #709 记录并保留回滚入口。

## 4. 治理

- [页面改造台账](../ui/PAGE_MIGRATION_TRACKER_V1.md)
- [Issue #709](https://github.com/beyourlovercom/erpbeyourlovercom/issues/709)
- [Owner UI 规范](../ui/BYL_COMPACT_WORKSPACE_V1.md)

本次**只声明已部署并通过公网基础健康检查**，不冒充 Production 已登录人工九视图 PASS。其他模块 P1–P4 按单独工作包继续，不因本次发布自动升级。
