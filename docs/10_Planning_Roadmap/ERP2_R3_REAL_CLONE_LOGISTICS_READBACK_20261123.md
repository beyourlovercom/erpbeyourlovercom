# ERP2 Frontend-only R3：真实 ERP1 同构 MySQL 副本物流回读与网关修复（2026-11-23）

**Owner 冻结方案**：`ERP2_OWNER_FROZEN_FRONTEND_ONLY_V1`。
**实施结论**：R3 只读原 Laravel 物流接口回归 **21/21 PASS**；七位 ERP1 员工身份及权限模型接口通过真实 Dev MySQL HTTP 检查；独立 Dev 测试用户经 Windows 网关完整密码登录、身份回读、退出的端到端测试 PASS。**原七位员工真实密码逐人浏览器 UAT 仍为 HOLD**，实际发货/Tracking 正向落库及真实外部仓库写入尚未验收。

## 数据及架构事实

- 原 Laravel 当前业务代码通过 `erp2_vben_dev_20261123` 独立 MySQL 8.4 同构库运行，复制自 ERP1 正式一致性只读快照；应用入口 Mac mini Tailnet `100.94.52.109:18822`。
- Vben 前端与 Dev Gateway 在 **007windows**，客户端本地 `127.0.0.1:18810`、私有 Tailnet `100.99.21.57:18810`；公开受保护域名 `erp2.beyourlover.com` 不与正式 ERP1 共用发布隧道。
- 当前数据以 2026-11-23 克隆快照为准。ERP1 Production 仍是唯一事实源，下面的订单数量**不是实时 ERP1 运营数**。
- 身份/物流接口检测使用的是隔离 Dev MySQL 的真实已复制员工模型，在内存中设置 actor 以便验证 GET 路由和权限中间件；**这不是输入原员工密码后的真实登录**。无真实订单、外部 OMS/Woo/WMS 写入。

## 物流原 Controller 全视图 HTTP/数据回读

正确提供 Laravel Inertia Version（`hash_file('xxh128', public/build/manifest.json)`）后，执行 **21/21** `GET` 路由回归，返回 200、页面组件/分页/SKU/数量字段类型一致，错误列表 0。

| 域 | 原 ERP1 视图 | Dev 快照结果 |
|---|---|---:|
| 国内 Vita | 待审核 review | 0 |
| 国内 Vita | 缺货单 stockout | 16 |
| 国内 Vita | 外采单 outsource | 1 |
| 国内 Vita | 待发货 pending | 10 |
| 国内 Vita | 今日订单 today | 10 |
| 国内 Vita | 今日速达 today-4px | 10 |
| 国内 Vita | 今日纬狮 today-weship | 0 |
| 国内 Vita | 异常订单 exceptions | 1 |
| 国内 Vita | 全部订单 all | 1,082 |
| 海外 Dede | 待审核 review | 27 |
| 海外 Dede | 缺货单 stockout | 33 |
| 海外 Dede | 待发货 pending | 19 |
| 海外 Dede | 已下发 issued | 14 |
| 海外 Dede | 已发货 shipped | 936 |
| 海外 Dede | 异常订单 exceptions | 11 |
| 海外 Dede | 全部订单 all | 1,072 |
| 海外 Dede | US-NY 全部订单 | 286 |
| 海外 Dede | US-MorePlus 全部订单 | 617 |
| 海外 Dede | US-3SM 全部订单 | 93 |
| 海外 Dede | DE-Tianyun 全部订单 | 65 |
| 海外 Dede | CA 全部订单 | 11 |

所有实际首屏 `items` 为数组，`sku`/`source_sku`/`final_sku`/`quantity` 字段存在且数量类型有效。前端 `parseOriginalLogisticsInertiaPage` 继续只提取允许字段，避免将原 Inertia 包含的收件人、电话、地址等个人信息写入表格通用数据结构。

对七位员工模型的身份接口返回 200；对应权限下，Kaven/Liang/Dede/Vita 的物流 GET 返回 200，Emma/Guts/Hiko 的物流 GET 正确返回 403。Dede 海外审核真实依据是 `RequireLogisticsReview` → `logistics.operate`，原角色已有权限，无需重建或修改角色。

## 登录和网关真实缺陷修复

在独立 Dev 创建随机一次性用户，经 **Fortify 真正 HTTP 密码验证与 Session**（不是人工伪造 cookie）执行：

`GET /login 200 → POST /login 302 → GET /_erp2/identity 200 → POST /logout 302 → GET /_erp2/identity 401`。

- 直连 Laravel Dev E2E PASS；初次经过 007windows Gateway 时发现 `/logout` 502，原因：Laravel Dev 的 `Location` 可返回与上游完全相等的 URL（**无尾随斜杠**），旧网关仅允许 `MAC + '/'` 前缀，误判为外部跳转。
- 已在 Vben Dev 网关增加 **仅允许上游完全相等 URL 转成 `/`**、仅允许 ERP2 canonical HTTPS 原点转成站内相对地址，Production 或可混淆域名保持 502 拒绝。新增 Node regression `erp2-dev-canonical-redirect.test.cjs`，7/7 路由/安全检查 PASS。
- 007windows 原计划任务通过 PowerShell `$ErrorActionPreference='Stop'` + Node stderr 合流，错误日志可误触发任务退出。已改为子进程 stdout/stderr 分离到文件并由 Windows 任务托管运行；复核任务 `Running`，健康接口 `ISOLATED_MAC_DEV_MYSQL`。
- 更新代码后，同一独立临时 Dev 用户完整登录/退出流程通过 **007windows Tailnet Gateway** E2E 再测 PASS，测试用户事后已删除，遗留账号数量 0。
- 007windows `Vitest` 身份与物流前端单测 25/25 PASS；Mac Node UI/网关原有回归 10/10 PASS；新增同域重定向回归 PASS。
- Vben 源码在 Draft PR #766；**不合并/不部署 ERP1 Production**，正式业务代码继续 GitHub main。

## 尚未完成及不得声明的事项

1. 七位原员工在真实浏览器使用各自原密码建立 ERP2 Dev Session、读取本人菜单/操作及退出：**尚未逐人 PASS**。此前没有绕过密码工具安全限制，也未重置生产员工密码。
2. R3 发货模块真实正向 Dev 写入与 Tracking 回读：**HOLD**；`ERP2_M2_DEV_SHIPPING_ENABLED=0`。外部 Woo、OMS、WMS 等正式写入仍禁用。
3. 原 ERP1 长期 UI/辅助功能 1:1 迁移、移动端 UAT、性能回归：继续按冻结 Roadmap 分阶段推进。
4. 当前 PHP/MySQL Dev 后端暂承载在 US Mac mini，Vben 编辑/构建/Chrome 主机为 007windows；尚不能声称所有运行进程均已迁到 Windows 本机。

**R3 下一优先级**：七人真实登录 UAT → 物流前端核心按钮与原 Controller 权限一致性 → Dev 隔离数据库正向 POST/回读 → Owner 视觉验收；届时才开始评估正式物流模块替换，ERP1 Production 不变。
