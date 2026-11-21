# ERP2 迁移支线 — P0 身份与权限验收 Handoff

**恢复时间：2026-11-17（Asia/Hong_Kong）**  
**项目：ERP2 / Vben SiteOps，独立 007windows Dev**  
**唯一当前工作包：P0 真实员工身份、菜单与权限一致性；后续顺序 R1 → R2 → R3 → R4 → R5。**

> 恢复结论：**R1 6/6 PASS；R2 工程回归 PASS、Vita 真实前台登录 PASS；R3–R5 未关；整个 P0 仍 HOLD。**
> 未经新的 Owner 批准，不合并 ERP2 Draft PR #766、不部署 ERP2 Production、不读取 Production 订单数据、不改员工密码或正式权限。

## 一、权威来源与边界

| 来源 | 状态 | 精确链接 / 版本 |
|---|---|---|
| 原 ERP Production 最小身份只读接口 | **已合并、已部署、健康检查 PASS** | [PR #790](https://github.com/beyourlovercom/erpbeyourlovercom/pull/790)，`main` 合并 SHA `42eaa60d1d2247069b11f6c3f02841af63e50bcd` |
| ERP2 独立 Dev 迁移工作包 | **Draft，未合并，未发布 Production** | [PR #766](https://github.com/beyourlovercom/erpbeyourlovercom/pull/766)，分支 `feat/erp-siteops-007-r0-orders-readonly-20261114` |
| 原 P0 验收规范 | 原身份、保存角色、有效权限、菜单与业务边界一致 | `docs/10_Planning_Roadmap/ERP2_IDENTITY_PERMISSION_PARITY_OWNER_FREEZE_20261116.md`，位于 Draft PR #766 |
| 远程执行机器 | 007windows 专用于 Dev；美国 Mac mini 用于原 ERP Production 工程验证 | 自建 BYL DC OAuth；不得重启 READY 设备、Chrome 或现有预览 |

Production 已上线 `GET /_erp2/identity`：仅向当前 Laravel 已登录且有效的员工返回本人用户 ID、名称、邮箱、`permissionMode`、保存的 `roleKeys`、实时 `effectivePermissionKeys` 与 `ErpShellNavigation`。接口只读、无用户 ID 查询参数、无订单/客户/物流数据、无授权修改。未登录 JSON 请求 HTTP **401**；正式站 `/up` 和 Workspace Tabs 静态资源 HTTP **200**。旧 Production 发布目录仍保留用于回滚。

## 二、已验证结果（禁止将工程 PASS 冒充全部 P0 PASS）

### R1：六位原 ERP 员工真实身份桥验证 — 6/6 PASS

在 007windows 上使用临时密码经**进程标准输入**送入独立身份测试，未写入代码、Git、日志或文档。逐人建立原 Laravel 会话；核对用户 ID、角色模式、真实保存角色及有效权限数量；核对菜单数据存在；本地生产订单路由拒绝 **405**、匿名身份请求 **401**；逐人注销 **200**，注销后身份请求 **401**。

| 员工 | 原 ERP user ID | 保存角色 | 权限数 | 菜单根节点 | R1 |
|---|---:|---|---:|---:|---|
| Vita | 18 | `role_preset_operations` | 20 | 10 | PASS |
| Liang | 17 | `role_preset_logistics` | 9 | 6 | PASS |
| Dede | 19 | `role_preset_logistics` | 9 | 6 | PASS |
| Emma | 3 | `role_preset_kol_manager` | 13 | 5 | PASS |
| Guts | 2 | `role_preset_kol_manager` | 13 | 5 | PASS |
| Hiko | 6 | `role_preset_kol_specialist` | 9 | 5 | PASS |

实测终态：`AUDIT_SUMMARY 6/6`，007windows 进程 PID `44436` 的历史输出。**只证明身份/授权投影桥，不证明业务订单可见性或 ERP2 菜单按钮全部一致。**

### R2：ERP2 Dev 前台接入 — 工程 PASS、真实浏览器部分 PASS

1. 原 Dev 解析器仅接受 `ERP_CANONICAL_LARAVEL_DEV_SESSION_V1`，而新 Production 接口返回 `ERP_ORIGINAL_LARAVEL_PRODUCTION_SESSION_V1`。已修复为**仅接受这两个确切来源**，身份解析与缓存权限指纹保留来源差异，未知来源拒绝。
2. Draft PR #766 已更新：
   - `runtime/p0-production-identity-bridge.cjs` 与 `.test.cjs`：统一 Production 来源标识；Node **3/3 PASS**，007windows 同样 **3/3 PASS**。
   - `overrides/src/api/erp-identity.ts` 与 `.test.ts`：两个受信来源、拒绝伪来源、来源变更清理授权指纹；007windows Vitest 联合 **11/11 PASS**，`vue-tsc --noEmit` **PASS**，Vite 独立构建 **PASS**。
3. 007windows 启动全新隔离前台：`http://127.0.0.1:18789/#/auth/login`；真实客户端在 007windows **Edge headless** 完成 Vita 登录：`POST /login=200`、`GET /_erp2/identity=200`（三次），路由进入 `#/erp/orders/orders-master`，显式注销 `200`。本地 `/orders` 被桥阻止 `405`。
4. 现有 007windows 18786/18787/18788 服务和用户 Chrome 保持不动；**尚未完成 007windows 用户 Chrome 手动视觉验收，不能写 Owner PASS。**

本次修改的 Dev 分支末次已知提交：`c6001bda9eb18596d3283e5d7eeeaef1bbdb5fce`（实际分支 HEAD 以 GitHub 为准）。

## 三、当前运行 / 复现落点

- Dev 工程目录：`D:\AI\erp-siteops\frontend`。
- 真实身份桥源码：`D:\AI\erp-siteops\p0-production-identity-bridge.cjs`。
- 新版 Vben 构建目录：`D:\AI\erp-siteops\p0-canonical-uat-dist-20261117`。
- 独立预览启动器：`D:\AI\erp-siteops\start-p0-identity-frontend-preview.cjs`。
- 独立预览地址：`http://127.0.0.1:18789/#/auth/login`（**只能从 007windows 本机访问**）。最近一次启动工具 PID 为 `27352`；恢复时先核对端口存活，不能把旧 PID 当成现时状态。
- 只读六员工身份脚本：`D:\AI\erp-siteops\p0-live-identity-audit.cjs`；前台真实登录脚本：`D:\AI\erp-siteops\frontend\p0-live-browser-auth.cjs`。后者使用 `playwright` + 本机已安装 Edge headless；Playwright 自带 Chromium 未安装。使用前先读取脚本，密码须临时从标准输入传递，不以命令行参数或文件形式保存。
- 用户前台验收目标仍为 **007windows Chrome**，不是中国 Mac 浏览器。不要重启 Chrome、READY 设备或已有服务。

## 四、未完成问题与严格门禁

1. **R2 尚余：**另外五位员工的 ERP2 前台登录、菜单挂载、身份变化及注销，至少要从 007windows 浏览器/隔离自动化获得真实证据。
2. **R3：**拿原 ERP 当前 `navigation` 和有效权限作为权威，逐人比较新 Vben 一级/二级菜单及按钮；只能显示已授权操作。14/15/16 年历史回忆不能覆盖当前权限数据。全部未迁移页必须有明确占位提示，不允许假装可操作。
3. **R4：**跨角色直接 URL、非法 API、会话过期、切换身份后的 Workspace Tabs/3 Pins/Hot Snapshot 清除，及权限即时变更后的安全失败。不得为测试擅自修改 Production 员工权限；撤权场景必须用隔离 Dev 授权环境或模拟变更，标注其证据等级。
4. **业务范围 blocker：**Owner 明确只批准从 Production 读取身份与 ACL，**禁止接入 Production 订单数据**。因此当前身份桥刻意将所有业务 `/orders` 和 `/api/*` 请求拒绝为 405，不能在此桥上证明某员工拥有订单数据范围、搜索、导出等正向业务访问。需要单独配置具备相同身份/权限的**隔离 Dev 业务数据**，或经 Owner 明确修改 P0 范围；不得把合成订单测试写成真实 Production 员工业务闭环 PASS。
5. **R5：**007windows Chrome Owner 视觉 UAT、六人证据矩阵、自动化回归、无越权结论、Dev Draft PR 记录；满足全部门禁后才可宣布 P0 关闭。

## 五、后续 Roadmap 与剩余估时

| 阶段 | 任务 | 当前 | 剩余有效工程小时 |
|---|---|---|---:|
| R1 | 六员工真实 ID/角色/有效 ACL/注销验证 | **6/6 PASS，关闭** | 0 |
| R2 | Dev Vben 原身份登录、来源兼容与前台会话 | **工程 PASS，Vita 浏览器 PASS，5 人待跑** | 1–2h |
| R3 | 六人一级/二级导航、按钮和字段权限逐项对齐 | **待执行** | 2–3h |
| R4 | 越权、失效、切换角色、Tabs 与缓存清理；Dev 业务范围验证 | **待执行，Dev 数据源仍受限制** | 3–5h |
| R5 | 007windows Chrome UAT、回归报告与 P0 关单证据 | **待执行** | 1–2h |
| **合计** | **只计本次未完成工作，不重算已完成 R1** | **P0 HOLD** | **7–12h** |

工时为估计的**有效工程小时**，不等于真实已消耗时长；若需额外构建隔离 Dev 业务授权数据源，应在范围盘点后单列工时并取得 Owner 方向。

## 六、恢复时的第一步（给下一条 ChatGPT/Codex 支线）

1. 先检查 GitHub `PR #766` 最新状态、当前 Draft HEAD 与 007windows 18789 健康；不回退到早期 R0/R1 重做。
2. 在 007windows 继续 `p0-live-browser-auth.cjs` 对 Liang、Dede、Emma、Guts、Hiko 的真实登录/注销与菜单渲染检查。密码仅通过安全标准输入传递，严禁输出或提交。只读当前员工自身身份与权限。
3. 逐一获取原 ERP `navigation` 并与 Vben 渲染菜单比对，发现角色/菜单差异后定点修复 Draft PR #766，跑 Vitest/TypeScript/Vite/浏览器回归。
4. 针对记录范围、禁用与撤权场景确认隔离 Dev 数据方案。**Production 业务数据和正式权限操作都不在当前授权之内。**
5. 汇总 6/6 ERP2 前台矩阵、禁用/越权/缓存证据、Owner UAT，条件满足后才能关闭 P0；在此之前保持 HOLD，不合并、不部署 ERP2 Production。

**安全提醒：**六名员工共用的临时密码应在 P0 验收结束后由各人改为独立密码；本 Handoff 不收录密码、会话 Cookie 或 Token。
