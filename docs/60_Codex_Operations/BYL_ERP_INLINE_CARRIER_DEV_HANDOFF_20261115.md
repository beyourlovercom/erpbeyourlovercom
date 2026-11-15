# BYL ERP 履约发货：弹窗新增物流商（Dev Handoff）
日期：2026-11-15 · Scope：订单详情“填写物流并发货”弹窗 · Worktree：`feat/fulfillment-inline-carrier-20261115`
## Owner 冻结要求
- 物流商下拉增加“＋ 添加物流商…”，例如“德邦物流”可直接创建；对应运输/服务类型自动选择“默认”。
- R0→R4 仅 Dev 实施；007windows Chrome Owner 真实验收 PASS 前，**禁止合并主干、禁止 Production 发布**。
- 保留仓库/Tracking/发货时间/备注及现有履约入口；无需新菜单和新发货工作流。
## 实施清单
- R0：复用已有 `logistics_carriers` 主数据、`carrier_services` 关联表和 `POST /logistics/carriers`；不另建平行字典。
- R1：订单弹窗物流商下拉增加入口，二级紧凑弹窗录入名称、英文名称（可选），默认服务只读显示“默认”。
- R2：复用有权限的原新增 API；同一事务创建物流商、默认服务及默认服务外键；校验同名重复及操作权限。
- R3：新增完成即时选中当前订单；每个物流商提供“默认”选项，但对既有 USPS/DHL 等保留其真实服务值，避免履约记录降级。
- R4：前端 92/92；后端 16/16（189 assertions）；TypeScript 类型检查、Vite Production build、Git diff --check 全部通过。
## 验收隔离边界
- 使用 US Mac 专用 worktree、独立 SQLite 测试库及 LocalUatSeeder，绝不使用 Production 数据库与真实单。
- 验收账号由 LocalUatSeeder 创建；测试单号 `DEV-CARRIER-001`，不调用“确认发货”真实操作。
- UAT 路由：`/customers/orders/1`（该隔离库中的示例测试单）；Dev 主机 Tailscale `100.94.52.109`。
- 007windows 浏览器当前因 Tailscale DERP 中继流量停滞，页面资源尚未完整加载；**R4 浏览器与 Owner 视觉 PASS 未完成**。
## Owner UAT（尚待执行）
1. 007windows Chrome 进入测试单详情 → “履约发货 / 填写物流并发货”。
2. 物流商下拉选择“＋ 添加物流商…”；输入“德邦物流”（或 Dev 专用测试别名），核对默认服务只读“默认”。
3. 点击“添加并选中”；核对物流商自动被选中、服务为“默认”；回到订单重复打开后仍可查询该物流商。
4. 重名拦截、取消后保留先前选择、旧 USPS/DHL 既有默认服务不被篡改。
## 发布门禁
代码/自动化测试 PASS **不等于** Owner Chrome UAT PASS；先取得 PASS，再推进 PR 合并及有授权的 Production 受控发布。
