# 海外仓「待发货」批量确认发货 — 单独 PR，安全门禁

Owner 2026-11-12 G 第4张「海外仓」视觉 PASS 后，要求在截图左下角
「已选 N 条 / 批量操作」同一个菜单加入 **批量确认发货**。
不新建上方批量工具栏；不改变原有「批量归档」。

## 业务及安全范围

- 仅限具备 logistics.operate 与 RequireLogisticsExecution 的已登录
  物流人员、view=pending。一次选择 1–50 条；页面渲染绝不自动提交。
- 用户先点击「应用」进行 **只读预检**，逐单显示订单原始编号、仓库、
  Tracking、是否允许和阻断原因。必须由操作者明确勾选“已核实真实出库”
  后，才能单击确认可执行的 N 条。
- 单次预检令牌随机 48 字符，与真实用户 ID 绑定，10 分钟有效。
  服务端使用锁消费一次性令牌；重复点击、跨账号重放、过期都拒绝。
- 预检与执行前均重新读取真实订单状态、审核仓、运营归档、拆分/历史物流、
  Carrier/Service、Tracking 有效及重复使用；执行前比对
  order/Shipment/review 的持久化数据指纹，变化则仅隔离该单。
- 仅对 US-NY、DE、CA 使用原 OverseaManualShipmentService.confirmNonOms；
  US-SM 使用原 confirmUsSm。两者最终调用现有 ShipmentService.markShipped
  的 DB 行锁、付款及审核准入、审计日志和同步模块 handoff。
- US-LA/MorePlus **永久不走本批量接口**；必须核实真实 OMS 状态、
  实际出库单和 Tracking，通过已有专属手工/OMS 流程确认。
- 未审核、缺真实 Tracking、Tracking 重复、已发货、取消/退款/归档、
  仓库不匹配、异常和预检后的变化逐单阻断，不影响其他订单。
- 接口按单提交并返回 success_count/failed_count/results，
  失败不自动重试；网络回执未知时必须先核对订单真实状态。
- **本功能不是自动出库，不向 ShipOut/OMS 重新下单，不直接调用 Woo，
  不修改原官网 Completed 规则。** 只确认仓库真实发货的 ERP 状态，
  原有 30 分钟同步模块负责后续 Woo Tracking/状态。
- R4-7B「纬狮订单」继续 OFF，原 30-Tab、Sync 总表直接按钮、
  G 可取消操作列固定均不变。

## UAT 门禁
- Vue：左下角单一批量操作菜单；只在有权限待发货视图显示；
  仅预检不会执行；未确认真出库的按钮 disabled；零合格单不可提交；
  预检、执行均使用原有 CSRF 和受控 JSON 接口；逐单回执可见。
- PHP Feature：viewer 403；预检 0 订单写入；US-NY/DE/CA，
  US-SM 原服务复用；MorePlus 隔离；Tracking 缺失/重复/过期/并发变化；
  二次消费 409；审批/服务无效、状态/付款门禁保持原有。
- Playwright：一次性的临时 US-NY 合成订单（仅隔离 SQLite）；
  仅预览请求被 mock 响应；确认 apply 路由被拦截，
  **不允许真实发送任何批量发货请求**。测试数据库全部清理。
- GitHub PR/CI 与 Dev 浏览器测试通过后提交 Owner 验收；
  **未得到单独授权前，不合并/不发布 Production**。
- 真正发布时以最新 Production 基线做 allowlist 增量包、
  保留回退版本、复核所有后台发货/单号/审核队列保护规则。
