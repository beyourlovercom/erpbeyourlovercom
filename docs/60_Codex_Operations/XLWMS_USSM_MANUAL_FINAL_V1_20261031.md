# US-SM / 世迈仓 — 最终人工下单 V1 (2026-10-31)

Owner 已批准的业务模式：人工 Excel 导入优先，取消 OMS Browser Agent 和一键自动下单。

## Dede 日常操作
1. Vita 分仓 → Dede 在原海外仓物流页审核 US-SM 订单。
2. Dede 可在每条已审核订单上选择“下载订单文件”，系统返回单条 WMS-64 XLSX；也可在待发货视图选择“批量下载订单文件”。
3. 手动进入 OMS 当前“草稿－待提交”队列，搜索相同 ERP 订单（注意参考号可能带历史后缀）；已有草稿就不重复导入。
4. 没有当前草稿时，在世迈 OMS 人工导入该文件为草稿；查看仓库 Yangfan、渠道 Upload_Shipping_Label、Carrier USPS、SKU、数量、地址。
5. 仓库工作人员手工上传真实 USPS 面单并校验 Tracking，之后人工提交仓库。不得以 VOID / TEST 占位图实际发货。
6. 物流状态与 Tracking 继续使用 OMS 只读 API 查询；ERP 人工确认已发货以及 AfterShip 回写继续遵循现有独立审核流程。

## 重复下载
- 每次下载产生新的 Reference Number 和 Platform Number，两列同一个唯一值，由 ERP 原仓库单号前缀 + -R + 12 位随机十六进制组成；批量导出同样生成唯一值，避免 OMS 历史参考号重复拒单。
- 不查询或限制历史 OMS 创建次数，也不自动把下载变成仓库发货；仅在 OMS 当前草稿箱手动防重。
- 历史 OMS 订单与 ERP/Woo 发货记录不被更改；历史记录默认折叠。

## 技术安全与现有功能保留
- 旧 API sync, send, web-draft, reship-create 在 HTTP 边界返回 410 XLWMS_MANUAL_ORDER_ONLY，无网络写入；签名 OpenAPI outbound CREATE 仍禁止。
- OMS 库存只读同步、物流状态只读查询及已经验收的单号手工确认/AfterShip 工作流不撤销。
- 现有 Excel 64 列格式沿用，不新建另一套导入模板；其他仓库导出不改变。
- 禁止依据浏览器前台、Chrome 标签页或个人 OMS session token 自动化创建。
- PR 合并不等于 Production 发布；受控正式发布需要 Owner 单独 pass。

## Owner 真实人工导入验收（2026-10-31）
Owner 已反馈 ERP 人工订单导出与世迈 OMS 人工 Excel 导入均成功，人工下单 V1 验收 PASS。本结论以 Owner 的现场确认作为验收依据，不重复导入 C41845B、不重复测试、不再次 CREATE。OMS 后续上传真实面单、人工发货及 ERP 人工确认沿用已验收的既有流程。

**发布隔离：** PR #581 当前已正式发布。PR #582 已合并 GitHub main 但尚未正式发布，未来必须独立确认数量兼容变更及具体 release 文件，避免整包发布时未经签收带入。旧 M2 PR #562 不得合并自动 APPLY/OMS 驱动发货功能。详细交接见 `XLWMS_USSM_MANUAL_M2_CONSOLIDATION_20261031.md`。
