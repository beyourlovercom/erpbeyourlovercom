# 国内发货：Carrier / 服务人工确认不可回退规则 V1

**OWNER REQUIREMENT / PERMANENT REGRESSION CONTRACT · 2026-11-09**

范围：ERP 国内发货的中国工厂仓 FACTORY，现有与将来的 Global Data Grid/统一表格迁移。

- 新订单：待审核 → 审核通过 → 待发货；审核本身不确定 Carrier 与运输/服务类型。
- 中国工厂仓待发货行：Carrier 与 服务均以空白下拉框起始。无论国家、物流商目录默认服务或分仓策略估算，都不得自动选择 4PX、Weship 或其他物流商/服务。
- 必须人工先选真实 Carrier，再选该 Carrier 已启用的服务类型，然后单独确认，保存 carrier_selection_source = manual。切换物流商必须清空服务重新选。
- 只有具备 logistics.operate 权限、完成商务有货审核、尚未发货的工厂订单可以修改。保存只是 Shipment 草稿，不触发发货或官网同步。
- 确认发货、服务商导出、批量回传应用均须遵守人工确认门禁。保留有货/缺货/驳回业务规则。
- 老数据自动默认值在待发货表的可编辑控件显示空白；审核通过时仅清除未经人工确认的旧默认值。已人工确认的合法选择和已发货历史不得因表格升级而改写。
- 新版统一表格必须复用后端数据、选择来源及保存/发货门禁，绝不能恢复前端 4PX 默认值或绕过权限。
- 海外仓的推荐路由、默认服务不变。工厂路线成本估算并不构成实际物流选择。

自动化门禁：Domestic/Index.test.ts，DomesticInlineTrackingTest.php，DomesticBusinessReviewTest.php，FulfillmentAllocationV1Test.php，PendingFulfillmentReallocationTest.php，DomesticLogisticsProductionClosureTest.php。必须同一 PR HEAD 通过测试、typecheck、build、真实 UAT。Production 仍需 Owner 单独授权。
