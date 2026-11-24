# ERP2 物流 P0 集中交付 — 第一批证据（2026-11-24）

**Owner 范围：仅关闭物流 P0（原估算 18–32 有效工程小时），暂停 R4 大规模其它页面迁移。** 遵循 `ERP2_OWNER_FROZEN_FRONTEND_ONLY_V1`：原 ERP1 Laravel 业务规则与 MySQL schema 不重建；007windows 为新前端开发、构建、浏览器 UAT 主机；US Mac mini 临时托管隔离 MySQL Laravel Dev 及受保护公网静态入口。

## 当前 Dev 运行版本及保护

- 007windows Vben 构建：`D:\ai\erp-siteops\erp2-p0-logistics-dist-20261124`；运行网关 `127.0.0.1:18810`，经 Tailnet 到独立 Laravel Dev `100.94.52.109:18822`。
- Dev MySQL 唯一目标 `erp2_vben_dev_20261123`，与 ERP1 Production MySQL 独立；网关健康 `ISOLATED_MAC_DEV_MYSQL`、`production_connected=false`。
- 受保护公网 `https://erp2.beyourlover.com` 由美国 Mac mini 的 Static Edge 服务 Vben 构建产物，已完成 SHA256 同步。匿名访问仍返回 HTTP 401、TLS 校验 0。
- `ERP2_M2_DEV_REVIEW_ENABLED=1`，仅原 Laravel 审核流程对隔离 Dev 库开放。**`ERP2_M2_DEV_SHIPPING_ENABLED=0`**，真实发货、Woo/OMS/WMS 外部写入未开放。
- 一次 Dev 端浏览器操作不构成真实 ERP1 员工登录 UAT；ERP1 正式系统仍在旧前端运行。

## 已实际 PASS 的物流 P0

1. **真实登录及角色页面链路（合成 Dev 测试用户）：** 独立 Edge headless (不重启用户 Chrome)，Fortify 密码登录后 `/_erp2/identity` 200，导航进入 `#/erp/overview`，可进入 `#/erp/logistics/logistics-oversea`。原七位员工本人的真实密码与菜单验收仍 HOLD。
2. **ERP1 GlobalGrid 原组件迁移：** 原 `GlobalGrid.vue/OmsGrid.vue` 接入国内及海外物流，最大化按钮实际点击、`Escape` 退出、列设置、密度切换均在 Edge UAT PASS。保留列拖动/固定列/列宽/搜索/排序/当前页多选与 20/50/100 底部分页；排序/筛选只能按原 Laravel 现有支持的白名单字段服务端处理，不伪造全库字段支持。
3. **真实 MySQL Dev 页面数据：** 海外待审核 27 条、第一页返回 20 条（AG Grid 真实 DOM 有虚拟化）。Laravel 业务数据查询响应 HTTP 200，加载最终消失，无业务异常。
4. **账号列布局设置持续性：** 在 Edge 切换紧凑 → 标准，原 Laravel `PUT /ui/preferences/grid.erp2.logistics.overseas.v1` 200，刷新后密度仍为标准；无独立表格配置数据库。
5. **海外仓 Dev 正向审核：** 合成 Dev 物流角色在列表选择一条待审核订单，点击「审核通过」，原 Laravel `POST /logistics/oversea/batch-review` HTTP 200；经原控制器回读，待审核队列 **27 → 26**。操作仅修改 ERP2 MySQL 副本，未触达 ERP1 Production 或外部 WMS。
6. **国内 Tracking 草稿真实往返：** 合成 Dev 物流角色使用原 Fortify 会话，`POST /logistics/china/orders/{id}/tracking-draft` 保存随机 DEV-only 测试单号 HTTP 302；MySQL 记录确认，随后同接口清空 HTTP 302 并回读为空；`shipment_status=pending`、`shipped_at=null` 全程不变，没有真实出库。原业务对归档订单 HTTP 409、缺货订单 HTTP 422 的拒绝也被真实观察到。
7. **环境稳定性：** 007windows 发现并清理 52 个 11/20–21 已结束但滞留的 ERP2 Node 自动化测试进程，释放约 3,586 MB RAM；未停止 ERP2 网关、Desktop Commander 或 Chrome，健康检查仍 PASS。释放后 `vue-tsc --noEmit` PASS，Vben 构建 PASS。
8. **自动测试：** 007windows Vitest 登录/身份/物流 25/25 PASS，Mac Node Gateway/Grid/路由 11/11 PASS，独立 Edge UI 最大化/布局恢复、身份/队列及正向审核 PASS。

## 未完成，禁止提前宣布 PASS

- R2 原七位员工 Kaven、Liang、Dede、Vita、Emma、Guts、Hiko 的**本人浏览器账号密码、本人菜单、直接 URL 与越权验证**尚需实际 UAT；工程模型/权限回读不能冒充真实员工验收。
- 国内审核正向场景需准备隔离 Dev 合格待审核订单（本次复制库国内 review 队列为 0）；不能拿缺货或归档旧订单强行伪造正向 PASS。
- **确认发货（quick-ship/manual-ship）Dev POST→ shipment/Tracking/审计/出站队列回读仍 HOLD**。发货网关写入标志仍 OFF，外部出库接口保持隔离；不能将 Tracking 草稿 PASS 当成出库 PASS。
- 国内 4PX/纬狮 Excel 导出、回传预览、上传匹配与子订单 A/B/C 联动的 Vben 前端需继续 1:1 接入原 Laravel 路由并完成隔离 Dev UAT。
- Dede/梁/Vita 物流完整浏览器操作的 Owner 视觉验收与大批量回归仍 HOLD。
- 原 `workspace.vue` 订单总表的 GlobalGrid 预先移植工作不列为此轮物流 P0 新增范围，继续冻结 R4 功能扩展。

**下一步按 P0 单线程：** 先关闭真实员工登录与权限 UAT；后针对已确认的现成原 Laravel 发货/Excel 路由做 Dev 接入、正负事务/回读；最后国内/海外实际角色 UAT。所有写入限定 Dev。不得以此文作为 Production 发布授权。
