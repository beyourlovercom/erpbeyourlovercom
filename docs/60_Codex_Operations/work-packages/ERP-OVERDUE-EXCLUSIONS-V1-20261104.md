# ERP 海外仓超期库存 · 手动屏蔽 SKU V1

**2026-11-04 — 独立 Draft PR，仅隔离开发与 UAT；未发布 Production。**

## 业务规则

- 「库存概览 → 超期库存」增加轻量化「屏蔽设置」入口；
  超期明细每行可直接开启该 SKU 的排除开关。
- 根据**稳定 ERP product_id** 排除（不是名称关键字或入库批次 ID），
  对**全部海外仓**同商品生效。MorePlus alias 与 Woo canonical 都
  指向同一 product_id，不会双份计算或错排。
- 默认 **无任何屏蔽项**。润滑油、Logo、礼品盒仅是建议搜索词，
  **不自动排除**；操作员自行搜索/选择后才生效。
- 排除商品从 **库存概览的超期件数、SKU/批次统计、超期明细、
  当前筛选的 XLSX 导出** 一致地消失。
- 「已屏蔽商品」清单**永久可找回**，包含 ERP SKU、名称、
  可选原因和最后更新时间；关闭开关后立即恢复历史批次报告。
  同一商品不因在多仓而产生重复屏蔽项。
- 不更改 Woo/ERP 主商品、真实库存、预留、入库日期、FIFO 批次、
  Shipment、实际仓储账单或 **P9 库龄财务费用**。
  这只是报表展示口径，如将来希望财务费用豁免需独立决定。

## 权限与持久化

- 页面查看 / XLSX 下载：`inventory.view`（保持原权限）。
- 新增 `PATCH /inventory/overdue/exclusions/{product}`：需登录、
  `inventory.overdue.manage`，Server 校验布尔 `excluded`
  和可选 ≤160 字原因，CSRF/Session 中间件保持。
- 独立权限 `inventory.overdue.manage` 分配给现有管理员、运营预设角色，
  **不**开放仓库真实库存编辑权限，也不授予物流、网红等只读角色。
- 新增 `overdue_inventory_exclusions` 表：
  `id, product_id UNIQUE FK, is_excluded BOOLEAN DEFAULT false,
  reason VARCHAR(160), updated_by_user_id FK, created_at, updated_at`。
  产品层单一标识；恢复后保留 false 状态及数据库历史。
- 事务先锁产品主键，再更新设置并写 AuditRecorder：
  `inventory.overdue.product_excluded` /
  `inventory.overdue.product_restored`，包含原 SKU、旧状态、新状态
  和原因。重复提交无效操作，不产生重复审计。
- 搜索海外仓现有 QOH>0 的产品，支持 ERP SKU、产品名称与 alias，
  每次最多展示 30 条，避免增加大页面滚动负担。

## 部署约束

1. 新增两条独立 DB migrations：`2026_11_04_210000_create_overdue_inventory_exclusions.php` 和 `2026_11_04_210010_register_overdue_inventory_manage_permission.php`；
   **不**修改旧表结构，也不进行任何数据回填。
2. 通过 GitHub PR exact-head + CI、Owner `pass` 后才可 Production 发布。
3. 受控发布先验证当前线上基线与相邻 ERP 支线，构建并验收
   PHP+Vue；进入受控窗口后 **单独执行本迁移**，核对空屏蔽表，
   再将新 release 原子切换。若不能无损创建新表则停止发布。
4. 验证原表历史数据 hash/stock totals 不变，
   Stock/FIFO apply flags 仍 OFF，
   原超期件数保持最新实时计算值直到人工选择屏蔽项。
5. 权限验证：只有管理员/运营获得新的独立权限，可以屏蔽、搜索及恢复。
   提供 rollback；若已执行新表迁移，回滚代码可以保留空/已录规则表，
   不得以恢复旧代码为由自动删除规则数据。

## 本地 UAT

- 86 相关 PHP 测试 PASS（542 assertions），
  16 Vue 页面测试 PASS；TypeScript / Vite / Pint 均 PASS。
- 自动化覆盖：多仓产品同时排除与恢复；库存与 lot 数量保持；
  概览/网页/XLSX 三处同步过滤；权限拒绝；原因保存；
  SKU 搜索与排除清单回显；非法入参拒绝；
  幂等且不重复写审计；审计失败 DB 事务自动回滚。
