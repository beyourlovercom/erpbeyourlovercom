# ERP2 R3 — 第一批订单模块：隔离 Dev 数据源与页面验收（2026-11-18）

## 边界
- 唯一执行设备：007windows。Laravel 测试数据来自独立 SQLite UAT（126 条生成订单），并非 Production。
- 读取仅通过独立 Dev Laravel 原控制器与 007windows 本地网关 `127.0.0.1:18786`；真实员工身份桥 `127.0.0.1:18791` 仍拒绝业务订单读取。
- 不修改 Production 订单/员工权限/仓库、不运行写业务请求、不合并 main、不重启 READY 设备或既有 Chrome。
- Owner 已豁免原 60 天登录期门禁；不重新验证此前五名员工权限 PASS。

## 现场回归结果（007windows，2026-11-18）
1. `node D:\\AI\\erp-siteops\\r2b-current-smoke.cjs`：**PASS**，进程退出 0。
   - 本地网关真实 Laravel 授权来源四角色：Owner **123**、Staff **123**、KOL Specialist **20**、KOL Manager **41**；网关首屏分别 **50/50/20/41** 行。
   - 归档/已删除/业务排除生效；Laravel 动态已启用仓库入口 **11**；KOL 两角色没有库存菜单。
   - 客户订单 /customers/orders 每页 **20**；401/405/400 拒绝、敏感字段剔除 PASS。
2. `node D:\\AI\\erp-siteops\\r2b-current-browser-uat.cjs`：**PASS**，进程退出 0。
   - 007windows 独立 Edge headless 以四个隔离 Dev 测试账户登录真正 Laravel，`/orders` 分页与前台源数据成功读取。
   - 默认 `/orders` 每页 50；Owner/Staff 共 123，page2=50；KOL Specialist 20；Manager 41。
   - `search=EX-R2-00042` 每个角色恰好 1 条；Owner 客户订单每页 20；各角色菜单 7/4/3/3 个根节点。
   - 额外通过 007 本地网关实时 GET 客户订单：`total=82, page=1, perPage=20, lastPage=5`，独立确认客户数据契约。
3. `node D:\\AI\\erp-siteops\\r2b-current-vben-uat.cjs`：**HOLD**，退出 1；未通过新的 Vben 前台登录/页面验收。
   - 失败阶段：等待 `#/erp/overview` 跳转超时 30 秒；未执行后续 AG Grid/客户筛选检查。
   - 现场读取 `D:\\AI\\erp-siteops\\frontend\\apps\\web-antd\\dist\\index.html` 与 canonical identity-only `D:\\AI\\erp-siteops\\p0-canonical-uat-dist-20261117-p0cfix\\index.html` SHA-256 **完全一致**：`494B8CD4434C531AD206E30170AE8377EAE9B9616E5D8E30E797FE60A173270A`。
   - 该证据说明 18786 当前加载了原本用于 18791 的 canonical 身份版构建（与旧 18786 fixture 测试脚本预期不符）。这高度吻合登录跳转失败；不能据此宣称订单后端失效。
   - 本轮没有覆盖、回退或重新构建 18786 前端；保留现有用户预览与 Chrome。

## 第一批页面按证据分层验收
| ERP2 目标页面/功能 | 原 Laravel 隔离 Dev 数据契约 | Vben 独立页面/UAT | 当前结论 |
| --- | --- | --- | --- |
| 订单总表 `/erp/orders/orders-master` | PASS：123 eligible、50/page、搜索 1、分页、4 角色范围 | HOLD：18786 前端构建错配，18791 身份桥拒绝 `/orders` (405) | **数据源 PASS / 页面 HOLD** |
| 客户订单 `/erp/orders/orders-customers` | PASS：`/customers/orders` Owner 82 条、20/page | HOLD：页面尚是 `workspace.vue` 迁移占位，不是独立客户表 | **数据源 PASS / 页面 HOLD** |
| 网红订单 `/erp/orders/orders-kol` | PASS：KOL 专员20 / 管理员41，严格菜单限制 | HOLD：页面尚是占位；不能把主表角色切换算页面迁移 | **角色范围 PASS / 页面 HOLD** |
| 分仓 `/erp/logistics/logistics-wait-stock` | 仅菜单路径及授权投影可查 | HOLD：未提供隔离业务数据查询/表格实现 | **HOLD** |
| 国内/海外发货 `/erp/logistics/logistics-china`, `logistics-oversea` | 仅菜单路径及授权投影可查 | HOLD：未验证订单、发货状态、物流号和操作链路 | **HOLD** |
| 库存仓库入口 | PASS：原 Laravel Dev 动态返回 11 个已启用仓库快捷入口 | HOLD：仓库库存表格和同步/扣减一致性未迁移或未测 | **菜单数据 PASS / 页面 HOLD** |

## 必须满足后才能升格为“页面迁移 PASS”
1. 为 18786 的隔离业务数据测试建立**独立且固定版本的 Vben Dev 构建/预览**，避免复用 18791 canonical 身份版 dist；禁止覆盖 Owner 已验收的 18790/18791 构建。
2. 007windows 真实浏览器重新验证 `orders-master` 的表格、搜索、分页、权限与无旧数据残留。
3. 将客户订单、网红订单各自实现为目标路由真实页面，绑定隔离 Dev 原 Laravel 合法 GET，后端字段与前端列做逐项契约断言；不能用原 ERP 的 `status=implemented` 充当 ERP2 已完成。
4. 待分仓、国内/海外发货先完成只读状态/仓库/SKU/物流号数据契约及测试夹具；后续业务写入需单独明确授权并在隔离 Dev 校验。
5. 任何业务数据一致性报告标注来源（isolated fixture vs Production）、版本、角色和计数，禁止将本轮 126 条测试单描述为真实订单。

**结论：隔离 Dev Laravel 订单测试源正常运行，原 Laravel 角色/检索/分页一致性 PASS；新版 ERP2 第一批独立业务页面仍未达到迁移 PASS。**

## R3 follow-up — Owner 已批准继续 1→4（2026-11-18）
- **已解除此前 18786 身份版资源错配**：007windows 独立业务只读预览 18792，固定资产目录 `D:\\AI\\erp-siteops\\r3-business-dist-20261118`；未覆盖或重启 18786/18790/18791。
- 18792 使用独立网关，007 Edge headless 复测原 `r2b-current-vben-uat.cjs`：**PASS / exit 0**，123/20/41、客户82/page20、搜索恢复、11仓及移动端不溢出。
- Dev Vben 源码 `apps/erp-siteops-vben/overrides/src/views/erp/workspace.vue` 已实现**独立客户订单和网红订单路由**，支持 `orders-customers`、`customers-orders`、`orders-kol`、`kol-orders`。共用只读 AG Grid 组件，但各路由严格独立的 scope 与角色集合；路由切换清空上页行并作 requestSequence 失效控制。
- 客户页只向原 Laravel `/customers/orders` 请求、仅 Owner/Staff 测试身份；网红页只用 KOL 专员/经理测试身份下的原 Laravel `/orders` 服务端授权范围，不允许 KOL 页面选择 Owner/Staff，也不复用客户 scope。
- 007 前端 `vue-tsc --noEmit` **PASS**；独立 Vite 新构建 `D:\\AI\\erp-siteops\\r3-business-dist-p3-20261118` **PASS**。单独固定预览端口 18793（18792 作为上阶段基线保留）。
- 007 Edge headless `node D:\\AI\\erp-siteops\\r3-dedicated-pages-uat.cjs` **PASS / exit 0**：客户 82、20/page、下一页；KOL 专员 20 / 经理 41；不可切换 Owner/Staff；切回客户页自动排除 KOL 行；两处别名路由均 PASS。原总表完整回归 `r3-business-vben-p3-uat.cjs` **PASS / exit 0**。所有记录仍为隔离合成订单。

## R4 — 发货页面数据契约的只读核对
- 使用仅本地 UAT 网关 GET，全 123 条唯一订单跨3页（50+50+23），客户82、专员20、经理41，`r4-isolated-logistics-contract.cjs` **PASS / exit 0**。
- 状态分布：`pending_allocation=17`、`pending_review=16`、`sending=16`、`shipped=6`、`completed=23`、`cancelled=24`、`out_of_stock=5`、`progress_conflict=16`。
- 仓库显示：`中国工厂=20`、`美东纽约仓=20`、`de-tianyun=20`、`UNASSIGNED=63`（这些是生成数据、不可推断正式订单分仓比例）。
- 字段覆盖：123/123 有 SKU；28/123 有 Tracking；`final_sku` / `warehouse_final_sku` **0/123**；不允许将当前 SKU 宣称为最终仓库发货 SKU。
- 原 Laravel 源码具备 `GET /logistics/wait-stock`、`GET /logistics/china`、`GET /logistics/oversea/{warehouse?}` 业务控制器；但 **本轮安全网关尚未提供这三个页面独立授权 Inertia 数据投影**，原控制器视图字段、子单、实际发货仓、final SKU 与运单同步均未完成前台等价核对。
- **第4项结论：基础订单状态/仓库/SKU/Tracking 测试夹具与只读契约 PASS；三个物流业务页面仍 HOLD，不能将订单总表行筛选当成真实发货页面迁移。** 后续应为三类各增加经 Laravel 权限校验的专用只读 Dev 投影与 fixture case，再逐页做浏览器和权限 UAT。
- 全程仅 007windows / 127.0.0.1 / 隔离 Laravel 生成记录；不读取 Production 订单、不写业务数据、不动 Ready 设备或 Chrome、不合并 PR #766。