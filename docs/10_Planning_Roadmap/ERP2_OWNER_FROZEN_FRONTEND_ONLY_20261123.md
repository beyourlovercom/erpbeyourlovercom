# ERP2 最高优先级冻结方案：ERP1 Laravel 后端 + ERP2 Vben 前端（2026-11-23）

**Owner Approved / Architecture Frozen / Source of Truth**
**Rule ID:** `ERP2_OWNER_FROZEN_FRONTEND_ONLY_V1`
**优先级：ERP2 的最高优先级。** 本规则取代此前 ERP2 作为平行 ERP 重建、独立业务规则、第二套 SKU/权限/订单引擎的迁移计划；不改变既有 ERP1 Production 的业务合同、审批与发布门禁。若旧的 ERP2 Roadmap 与本文冲突，以本文为准。

## 1. 唯一目标与正式域名

- **ERP1 Production**：`https://erp.beyourlover.com`，唯一正式订单、产品、SKU、仓库、员工、网红、财务与物流业务事实源。迁移期间员工继续正常使用，禁止因 ERP2 开发而中断。
- **ERP2 Development / UAT**：目标规范域名 `https://erp2.beyourlover.com`。必须作为独立、受控、可登录的 Dev/UAT 环境上线；**不能仅换 URL 文案或将 127.0.0.1 伪称为正式域名部署**。对外开放前须校验 DNS、TLS、身份访问控制、隔离数据及外部写入阻断。
- **未来替换**：只有 ERP2 所有目标功能稳定、员工 UAT、业务回读、完整回滚预案通过且 Owner 明确批准受控上线，才允许将新前端切换到 `https://erp.beyourlover.com`；不得提前覆盖或修改 ERP1 Production。
- 原 ERP1 和 ERP2 **共享同一套 Laravel 业务代码/契约**，但 Dev 使用**独立数据库副本**。禁止 ERP2 Dev 测试订单/修改写入 ERP1 Production 数据库。

## 2. 技术范围：只升级前端，不重造业务

- ERP2 = 原 Laravel Controller/Service/权限/业务状态机 + 新 Vben/Vue/AG Grid UI；保留订单号、原有 SKU/别名/仓库产品映射、KOL、AffiliateWP 镜像、历史表关系和发货协议。
- 原 ERP1 相同的数据库引擎、结构与 schema 版本；初始使用一致性副本。严禁把原本真实 SKU 逐条重建到仅有合成数据的 SQLite，或将“Dev synthetic DB 产品数 0”误判为正式 ERP 数据丢失。
- 新 UI 需要 JSON 时只加薄接口适配层；不重写原 Laravel 业务逻辑、不建立独立发货状态、不重复开发权限/网红/库存/SKU 同步服务。
- 若 WooCommerce、AffiliateWP、OMS、WMS 等资料另存在外部平台，先复用 ERP1 原有快照/镜像及同步模块，不假定 ERP1 DB 已包含外部全部原始数据。必要的数据刷新只读进行，外部写入不得随 Dev 副本启用。

## 3. 007windows：默认唯一开发与测试主机

- **首选开发/UAT 机器：`007windows`**；直接编辑、构建、跑测试和浏览器验收。GitHub 为唯一代码事实源，避免 Windows/Mac 双端未经同步的源码分叉。
- 优先局域网或 **Tailscale tailnet 内网式**远程体验：Windows LAN IP 为 `192.168.200.58`（动态，需定期确认），Tailscale 地址当前为 `100.99.21.57`（动态，需确认），避免给其他电脑提供 `127.0.0.1` 链接。尾网内域名/HTTPS 优先，绝不以未经核验的公网链接声称可用。
- 可借助 US Mac mini 做既有 Laravel 隔离 Dev 和 GitHub 操作，但新前端开发/浏览器 UAT 的默认环境在 007windows；READY 设备和 Chrome 不重启。
- 环境可快速修改、回滚、刷新数据；Dev 服务须有可重复启动和健康检查，不能仅在临时交互会话存活。优先固定域名和访问入口而非连续增加随机本地端口。

## 4. 备份与操作边界

- ERP2 **每天一次计划快照即可作为常规最低基线**：至少 Dev DB + 配置（排除明文密钥）+ 当前 Git SHA/构建身份；记时间、校验结果、恢复方法。每天的普通前端修改无需多次完整数据库备份。重大破坏性 schema 变更另保留快速回滚点。
- GitHub 提交替代频繁代码 ZIP 备份；Dev bug 快速修复，不因隔离 Dev 界面崩溃而增加 Production 级繁琐发布流程。
- 只设置一次隔离边界：`ERP2 Dev DB ≠ ERP1 Production DB`；所有实时 WooCommerce、OMS、仓库 API、支付、邮件、外部 webhook 等真实写入默认禁用/模拟，并由自动化测试验证。否则复制正式数据也可能触发真实外部动作。
- 复制正式数据库时保留数据结构/关系，但必须妥善保护客户、员工、网红个人资料与凭证哈希：Tailnet / 访问控制、非公开环境、最小权限、不输出密钥到 Git 或日志。
- 业务状态/发货写入 Dev 可以测试到隔离 DB，但不能声称真实供应商、官网或 OMS 已同步；上线另走 ERP1 Production 的受控发布验收。

## 5. 实施顺序与验收（逐页推进）

1. **R0 / 架构与环境**：ERP1 当前版本、DB 引擎/schema、ERP2 Dev 部署路径和网络 DNS/HTTPS/访问策略确认；07windows 持久 Dev 入口。
2. **R1 / 同构数据库副本**：从 ERP1 只读一致性导出到独立 Dev；订单、SKU、用户、KOL、仓库、角色、权限、子物流、成本等表计数和引用完整性检查；确保外部写入禁用。
3. **R2 / 原 Laravel 会话与权限**：相同 Dev 数据中直接核验原员工账号密码哈希及权限，证实 Kaven/Liang/Dede/Vita/Emma/Guts/Hiko 页面权限一致。严禁人工收集或存储明文密码。
4. **R3 / 物流前端核心**：保留国内九队列、海外七队列和独立仓库筛选；接入原 Laravel 审核、Tracking 草稿、发货、回传状态读取。真实 Dev POST→DB 回读/审计正负场景必须 PASS；发货仅作用于隔离副本。
5. **R4 / 其它前端页面**：按 ERP1 已批准的原菜单与角色进行 1:1 迁移，优先订单总表、产品、库存、KOL、算账。
6. **R5 / 切换判定**：每天 ERP2 Dev 验证 + 员工 UAT；正式域名 `erp.beyourlover.com` 切换须额外 Owner 明确授权、版本校验、回滚基线和 Production 验证。旧版 ERP1 在新版本稳定前保留可回退。

## 6. 关键反例与禁止事项

- 不再按“第二套 ERP、第二套产品/SKU/权限/物流引擎”估算工时。
- 不把 `127.0.0.1` 分享给在另一台设备浏览器打开的 Owner，除非明确仅供该设备本地使用。
- 不把一份含 129 条合成订单、0 产品的 SQLite 视作 ERP1 同构真实数据副本。
- 不把页面视觉 PASS 误称为真实物流发货闭环 PASS。
- 不因 ERP2 Dev 崩溃而反复进行昂贵的 Production 发布/备份手续；但不降低 Production 与外部写入隔离要求。
- 没有经过 DNS/证书/健康/登录验证，就不能说 `erp2.beyourlover.com` 已配置成功。

## 7. 当日事实状态（2026-11-23）

- ERP2 Draft PR #766 正在开发，既有 Vben 页面与孤立 Dev 工作已有进展；**当前不是正式 ERP1 业务数据库的同构副本**。
- 007windows 已具备 Node/Vben 环境，并使用计划任务自启隔离 Dev Gateway + Tailscale 网关；中国 Mac 和美国 Mac mini 通过 `http://100.99.21.57:18810` 实测 HTTP 200。
- `erp2.beyourlover.com` 已创建 **单独的** Cloudflare Tunnel/DNS CNAME，外部验证 HTTPS 证书有效、未认证请求返回 401；入口额外加了 Dev BasicAuth，正式 ERP1 Tunnel 未改。授权用户的浏览器完整登录 UAT 尚未 PASS，公开域名不能算业务验收通过；Dev 仍然只有合成数据。
- Tailscale HTTPS Serve 功能在当前 tailnet 尚未授权，短期直连 IP 是受 Tailscale 加密保护的 HTTP Dev 入口；公网 Dev 域名优先采用独立 Cloudflare Tunnel，真实数据副本导入之前仍需落实访问权限清单。
- 本文记录新架构批准，不代表数据库已复制、域名已上线、所有员工登录/发货回读 PASS。
- 旧 roadmap 中“全部模块重建”“200h+ 一体化重造 ERP”与本文冲突的部分从此停用。

**Owner 目标：ERP2 Vben 只升级前端，ERP1 成熟 Laravel 业务能力全部复用；007windows 快速开发 + 内网式 UAT，ERP2 稳定后再批准替换原 ERP 域名。**
