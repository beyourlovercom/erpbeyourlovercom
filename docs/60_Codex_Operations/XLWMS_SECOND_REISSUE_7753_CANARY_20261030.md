# XLWMS — 第二次重新下发（已取消链）受控 Canary

日期：2026-10-30；范围：ERP 7753 / 007-1027-c41863，仅此订单。

## 真实只读核对

- 原 OMS `OBS0272610290RS`: API 状态 `4`、身份 `BYL-ERP-7753` 匹配、无 Tracking。
- 第一次 Reship OMS `OBS0272610300RS`: API 状态 `4`、身份匹配、无 Tracking。
- ERP Order 与 Shipment 仍是 pending_shipment / pending；持久 CREATE 门禁保持 OFF。
- 世迈 OMS 当前「自动提交已获取面单和无需面单的出库单」在中国 Mac 现有浏览器窗口内只读检查为 OFF。
- PR #565 创建后草稿回读修复已经合并 main，但截至本次检查尚未在 Production 发布。

## 第二次 Reship 的修复

1. 通过只读 OMS 精确状态与身份匹配，仅当上次 Reship 的 OMS 状态为 4 且无 Tracking，才允许把其 ERP 审计状态从 draft_created/draft_unverified/draft_existing 转成 canceled。保留所有历史 OMS 编号及审计事件。
2. 既有前置校验排除已经严格验证取消的旧申请，从而允许以新的 UUID 创建第二次独立 Reship。原本的首次原 OMS 仍须验证为已取消。
3. 第三次真实 CREATE 前再次逐笔从 OMS 读取所有历史补发单，确认 status 4 + 原生第三方身份匹配 + 无 Tracking。任何一张恢复活动、查单失败或身份错误都阻断 CREATE，不进行盲重试。
4. 新 OMS 仍固定 No_Shipping_Service 与 isSubmit=0；执行后必须由 PR #565 的原生读回校验确认 status=0，才能向 ERP 报告草稿创建成功。

## 实测与门禁

- 隔离 115 项测试、871 条断言通过，包括第二次补发正常草稿、历史单重新激活拦截及不重复 CREATE。
- **未执行第三次真实 OMS CREATE，未修改旧 OMS，也未发布 Production**。
- 后续实际生产执行顺序：Owner 受控发布 PASS → 生产更新并验证 PR #565 与本 PR 代码 → 读回两张旧 OMS 仍 4 → 确认 OMS 自动提交 OFF → 首次 Reship readStatus 审计关闭 → 创建并审批新的请求 UUID → 新库存快照只读同步与预检 → 针对 order_id=7753 + 新 UUID 单笔授权 CREATE → 即时 OMS status 0 回读 → OMS 草稿箱 UI 人工视觉 UAT → 关闭 CREATE 门禁。
- 如新单出现 status 2 或 4，标记异常并立即停下，禁止未经新审批再建单。

注意：用户明确要求本次只修复草稿箱入箱，不调查人工取消原因；OMS 渠道不得改成 USPS。