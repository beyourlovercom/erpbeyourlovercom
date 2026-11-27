# ERP2 P0：公网 Vben 长时间停留启动动画（2026-11-23）

## 问题与真实证据

Owner 在 `https://erp2.beyourlover.com` 看到「BYL ERP SiteOps UAT」启动画面长期不进入登录页面。

- 007windows 本机 Dev `http://127.0.0.1:18810/?splashcheck=20261123#/auth/login`：原员工登录页面正常、138 个请求正常，0 JS 控制台异常、0 网络失败。
- 同 Chrome 经独立 BasicAuth 的公网入口：**37 秒仍停留启动动画**；124 请求中 123 已加载，没有 4xx/5xx 或 JS 错误，关键 JS chunk `vue-router` **28.7 秒**、`style` 26.9 秒、`card` 26.2 秒。Cloudflare Tunnel 日志同时出现大量已取消 JS 资源流。
- 原架构每一个 Vben JS/CSS 都走 **Cloudflare → US Mac mini → Windows Tailnet → 007windows Node gateway**，后端操作又可经 Windows 返回 US Mac mini Laravel。这条链路适合小流量 JSON，不适合约 140 个碎片静态资源。
- 因此这是公网 Dev 静态资源分发 P0，**不是 SKU/数据库副本丢失、Laravel 登录失败或要求重建 ERP2 后端**。

## 修复架构与实际实施

- **007windows 仍为前端开发/构建/Chrome UAT 主机**。当前构建目录从 `ERP2-Dev-007-Supervisor.ps1` 配置读取；当前 Windows 发布工具 `D:\ai\erp-siteops\ops\ERP2-Static-Dist-Package-20261123.ps1` 将 Vben 资源压缩成 ZIP，产生 SHA256 摘要文件。
- 007windows 通过 Tailscale 地址 `100.99.21.57:18810` 暂时提供 ZIP 和 SHA256，仅供 US Mac mini 私有网络获取；对应包内容为 234 个前端文件，解压约 5.8 MB，ZIP 约 2.0 MB。未复制任何 ERP1 数据库或密钥。
- US Mac mini 的私有 `/Users/a/ai/erp2-dev-ingress/static-vben-20261123` 静态目录通过 digest 验证进行镜像；`erp2-static-mirror-sync.py` 在 launchd `com.byl.erp2.dev.staticmirror` 下 **每 300 秒**比对 SHA256，只有变化才传输 ZIP，全部文件校验后再原子更新 index.html；旧哈希资源保留避免已打开页面瞬断。
- 公网入口的 `erp2-dev-authproxy.cjs` **先检查 Dev BasicAuth**，成功后仅对确实存在的前端文件走 Mac 本地镜像；`/login`、`/_erp2/identity`、`/logistics` 等仍经 007windows 的隔离 Laravel Session Gateway 与 MySQL Dev。
- 静态资源仅允许已知 MIME 后缀、阻止遍历，镜像同步 ZIP/manifest 不从公网代理；Cloudflare Tunnel 和域名保持原配置，没有碰 ERP1 正式隧道。
- 007windows 的 `ERP2-R2-Publish-Frontend-Dev.ps1` 成功 Dev 重启后调用上述 ZIP 打包脚本，Mac 自动拉取版本，未来不能再仅重建 Windows dist 却不更新公网静态镜像。
- US Mac mini 的原代理回滚副本 `/Users/a/ai/erp2-dev-ingress/erp2-dev-authproxy.before-static-20261123.cjs` 已保留；新脚本在 Draft PR #766 中留档，Dev 机器本地文件仍为当前实际运行版本。

## 修复后验收

- 007windows CDP Chrome 新独立浏览器环境（Dev BasicAuth）访问 `https://erp2.beyourlover.com/?check=20261123#/auth/login`：**13 秒内（脚本包括固定 10 秒等待）已进入「原 ERP 员工用户名 / 邮箱」登录页**。
- 前端请求 **143/143 成功**，无 pageerror、console error、requestfailed、HTTP 4xx/5xx。外部分析脚本 `hm.js` 曾耗时约 3.2 秒，但不影响进入登录页。
- 007windows 原本地 Dev 源健康检查仍 `ISOLATED_MAC_DEV_MYSQL`，Dev Gateway Windows 任务运行；US Mac mini Laravel MySQL 独立 Dev 不变。
- 公网 `https://erp2.beyourlover.com/` 未提供 BasicAuth 时仍 **401** 且 HTTPS TLS PASS；请求未授权数据仍被挡在网关外。
- 静态同步首次手动执行通过 `ERP2_STATIC_MIRROR_SYNC_PASS sha=6a4d192f94bb`；LaunchAgent 再次定时运行输出 `UNCHANGED`，无重复大流量 ZIP 下载。
- **真实 ERP 员工逐人登录 UAT、发货 POST/Tracking 回读仍未完成**，与本次「公网登录页加载 P0」无关，不得因启动画面恢复便标记完整 ERP2 业务 PASS。

## 持续运维与回滚

- 平日先由 **007windows** 构建前端并通过 `ERP2-R2-Publish-Frontend-Dev.ps1` 提交 Dev 版本；新的 digest 由 Mac mini 5 分钟内自动检测和同步。开发人员无需重复做 Production 级备份。
- Mac 私有同步可手动调用 `/usr/bin/python3 /Users/a/ai/erp2-dev-ingress/erp2-static-mirror-sync.py`；输出只含版本摘要，不写密码日志。
- 若镜像下载、SHA 校验失败，旧静态资源继续在线；若新代理出错，恢复前述代理回滚文件并仅重启 Dev authproxy 的 launchd 服务。**禁止重启 Mac/Windows 设备或 Chrome，不修改 ERP1 Production**。
- GitHub 留档文件：`apps/erp-siteops-vben/runtime/erp2-public-static-edge.cjs`、`erp2-static-mirror-sync.py`、`erp2-007-static-dist-package.ps1`；运行中的敏感 Dev 访问哈希保存在 Mac 私有 `auth.json` 中，绝不提交 Git。
