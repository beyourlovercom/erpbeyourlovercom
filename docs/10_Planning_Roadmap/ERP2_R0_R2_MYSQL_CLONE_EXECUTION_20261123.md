# ERP2 Frontend-Only R0–R2 实际执行记录（2026-11-23）

**Rule**: `ERP2_OWNER_FROZEN_FRONTEND_ONLY_V1`
**环境**: ERP1 Production 保持原状；ERP2 Dev 使用独立 MySQL 数据库和原 Laravel 源码，Vben UI 运行于 007windows。
**结论**: R0 基线 PASS；R1 结构及记录复制 PASS；R2 身份数据、权限与登录接口 READBACK PASS，七位员工真实密码建立浏览器会话尚未执行，因此最终员工 UAT **HOLD**。不把前端视觉 PASS 充当实单发货 PASS。

## R0 原后端版本对齐

- ERP1 当前正式发布候选代码源 SHA: `44a244cebf3932dc707bfd667c439a24d8e72f3a`，PR #831 已于 2026-11-23 部署，运行 Laravel **13.23.0** 与 MySQL **8.4.11**。
- ERP2 Laravel 同构工作树：`origin/main` SHA `d2772e43c6e81a82882af8a8ba711169f151f5d7`。生产发布代码至此 HEAD 仅新增 `AGENTS.md` 与 ERP2 冻结方案文档，共 2 个文件，无 Laravel 业务代码差异。
- 后端位置（US Mac mini，仅供隔离运行）：`/Users/a/ai/007/_worktrees/erp2-backend-mysql-20261123`；Laravel 独立 Dev 环境（.env mode 0600）通过 launchd 监听 Tailnet `100.94.52.109:18822`。
- 007windows 仍为主要 Vben 源码编辑、构建和 Chrome UAT 机器；常驻 Windows 任务 `ERP2-Dev-007-Gateway` 现代理 **18822**（不是以前 18812 SQLite），HTTPS 入口 `erp2.beyourlover.com` 的 Cloudflare 隧道独立于 ERP1。
- 无任何 ERP1 Production 代码、域名或正式业务库切换。

## R1 MySQL 一致性克隆

- 只读 MySQL 8.4 `mysqldump --single-transaction --quick`，全部 **185** 表均为 InnoDB；跨 schema 外键 0、触发器 0、定时事件 0。
- 隔离 Dev 库：`erp2_vben_dev_20261123`，专用账户 `erp2_dev_runtime`，权限仅限该 schema。跨 schema 探针已验证 Dev 账户**不能读取 Production users**。
- 初始只读快照（私有目录 0700、文件 0600，禁止传 Git）：`/Users/a/ai/erp2-r0r2-20261123/erp1-consistent-20261123.sql.gz`，压缩字节数 **35,976,896**，SHA256 `c0a7c529ab3745b66ba209d45cb54aacc964f943706c15334a1bfed8312dc645`。
- 目标 MySQL 恢复 PASS：**185/185 表，字段无差异，352/352 外键**；原现场计数与 Dev 快照逐项核对无漂移。原订单 8,255、订单明细 10,886、发货 2,288、产品 256、仓库 9、KOL 1,443、用户 18、角色 14、权限 51、财务记录 7,259、迁移记录 191、同步出站队列 231。
- **重要提醒**：`product_warehouse_skus` 源正式表当前本来为 **0**，Dev 也是 0；并非迁移遗漏，具体仓库 SKU 由原 ERP 其它库存/别名机制保留。不得虚构仓库映射数据。
- 所有 18 位员工现有密码哈希按二进制逐个对比，**18/18 完全一致**；原用户账号没有重建/重置。
- Dev 的 `APP_KEY` 与 Session Cookie 专用；`QUEUE_CONNECTION=database` 但没有启动 Dev 队列 worker；`MAIL_MAILER=log`、外部 Woo 连接信息为空，`xlwms.oms_web_enabled=false`、原仓库写入禁用；Windows 网关 Dev 仅开放经过白名单的页面/API，手动 shipping write OFF。
- Dev 数据库每日一次 MySQL 全量一致性快照使用现有 launchd `com.byl.erp2.dev.dailybackup`；2026-11-23 首份 MySQL Dev 快照成功（约 36MB）。之前合成 SQLite 的每日任务已改为 MySQL 模式，原合成记录不再作为迁移依据。

## R2 原员工登录与权限

- Laravel Fortify 原有 `IdentityFortify::resolveLoginUser` 已支持原邮箱、`用户名@beyourlover.com` 以及直接用户名；未新建身份桥或复制第二套员工账号。
- 七位当前已存在且 Active（Dev MySQL 原始记录）：Kaven `role_preset_domestic_business`、Liang/Dede `role_preset_logistics`、Vita `role_preset_operations`、Emma/Guts `role_preset_kol_manager`、Hiko `role_preset_kol_specialist`。原密码哈希保留。
- Laravel 原模型实际权限和菜单 readback PASS：Kaven `domestic.review`；Liang/Dede `logistics.operate`；Vita `domestic.review`；网红经理/专员按原业务角色展示菜单。
- **Dede 海外审核权限纠正**：正式审核路由挂 `RequireLogisticsReview`，它检查 `logistics.operate`，**不是** `oversea.review`。Dede 已拥有 `logistics.operate`，无需额外加权。
- 007windows 实际 Chrome 只读 UAT：新的 ERP2 登录页正确提示使用原 ERP 账号；旧的 synthetic-only 文案已移除；Dev gateway 健康字段 `ISOLATED_MAC_DEV_MYSQL`；`GET /login` 200；未登录 `GET /_erp2/identity` 401。登录页 Vben typecheck + Vite build PASS。
- **HOLD**：此前工具环境阻止了自动填充员工凭据，本轮未绕过阻断；七位真实密码、session cookie、身份投影、逐人前端登录浏览器 UAT 尚未 PASS。需要实际由员工/Owner 使用原密码登录 Dev，逐人核对身份与菜单；未执行正式 ERP 登录写入、未修改密码、未生成伪造员工会话。
- 已将 Vben 登录提示修正推到 Draft PR #766（保持未合并、未发布 Production）。

## 生产保护与边界

- 对 ERP1 正式库只进行一致性只读导出和前后对账；Dev 仅在独立数据库恢复、写入配置、备份和自己的日志。
- 未执行 Dev 实际发货、Woo/OMS/WMS 真实外部写入，未启动 Dev 队列 worker，禁止误把 R2 READBACK 当作发货上线 PASS。
- 以后 Laravel 业务规则以 ERP1 为唯一代码事实源，新前端只做 JSON 薄适配和 UX 替换；007windows 负责前端，Mac mini 暂时承载独立同构 Dev 数据和 PHP 后端。真正的“单机局域网性能”还需要以后在 007windows 部署本地 PHP/MySQL，不可声称现在已经全本地化。

## 下一步验收清单

1. 原员工账号的七个真实浏览器登录、会话回读、角色菜单与退出登录逐人 PASS（Dev-only）。
2. 在 R3 中完成原审核、发货、Tracking 页面数据契约与 Dev 写入闭环。真实发货到仓库/Woo 之前必须另外授权 Production 发布。
3. ERP2 Dev 每天自动快照并维持同源业务代码；GitHub Draft PR #766 持续开发，但不擅自并入 Production。
