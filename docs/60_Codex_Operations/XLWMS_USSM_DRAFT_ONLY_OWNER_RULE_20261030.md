# 世迈 US-SM / XLWMS 出库草稿规则（Owner 2026-10-30）

**冻结规则：所有 ERP 世迈正常单笔、批量及重新下发订单，统一先进入世迈 OMS「草稿箱」，物流专员上传面单并人工确认后才提交仓库。**

1. OMS 原生物流渠道始终为 `No_Shipping_Service`；ERP USPS / Priority Mail 的本地选择不改变该渠道。
2. `SingleOrderDispatch`、`WarehouseBatchDispatch`、`XlwmsReissueAdapter` 必须通过 `createDraftSingle` 发送 `isSubmit=0`，禁止任何自动 SUBMIT、重复 CREATE。
3. 世迈 OMS「一件代发出库」中的自动提交设置保持 **OFF**，但 **OFF 并不能阻止某些 OpenAPI 来源订单由后台自动提交**。本订单 OBS0272610300RT 使用 isSubmit=0、首次返回状态 0，随后变为状态 2「仓库处理中」，同时界面开关为 OFF。没有厂商 AppKey 草稿模式正式契约前，不允许继续以 OpenAPI 创建新真实订单。
4. 只有状态码 0 可标注草稿；状态码 2 在这张真实单上由 OMS 详情证实为「仓库处理中」，状态码 4 为「已取消」。对非草稿必须显式预警，不得当作成功待上传面单。
5. OMS 设置修改、生产部署、第三次真实 CREATE 需要 Owner 独立授权。没有可查询租户设置的 OMS API 时，保留后台只读人工核验关口。

## 实际审计（2026-10-30）

- 中国 Mac 已登录 OMS 的设置复选框当前未勾选。仅证明现在关闭，不证明历史时点的开关状态。
- 原 OMS `OBS0272610290RS` 在 2026-10-29 16:11:29 已取消。
- 新 OMS `OBS0272610300RS` 在 2026-10-30 12:26:29 用 `No_Shipping_Service + isSubmit=0` 创建，首次状态 0，后来状态 2「仓库处理中」，在 14:10:45 变成状态 4「已取消」；无 Tracking。
- 新单确实到达 OMS，非“没有下发”，但仓库在正常待处理列表不再看到它。取消人员、为何从 0 进入 2 尚无充分的操作审计证据。
- 不得将 `No_Shipping_Service` 当作这次错误的原因，Owner 已确认此渠道为固定规则。也不得在没有独立批准时创建第三张 OMS。

官方流程：
https://help.xlwms.com/d458/fcbb/7023/48cf
https://help.xlwms.com/d458/fcbb/aed1/0c14

## 验收要点

- 单笔、批量、Reship 三条 CREATE 路径均验证 `isSubmit=0`。
- OMS 自动提交租户开关 OFF，物流专员在 OMS 草稿箱完成面单操作后人工提交。
- 状态漂移（0→2/4）被提示并审计，不能盲目重复 CREATE 或提前同步 ERP/Woo。

## 2026-10-30 15:43 实际复现及更正

- OMS OBS0272610300RT 在 15:39:41 创建；15:40:32 ERP 最近读回状态 0，数分钟后世迈 OMS 已将其归入「仓库处理中」（状态 2），草稿箱 0 单。当前无 Tracking 或出库时间。
- ERP 源码确认使用 outboundOrder/create、isSubmit=0，没有后续提交接口。OMS 页面「自动提交已获取面单和无需面单的出库单」未勾选。
- 领星官方《OMS 系统报错排查方法》指出 OpenAPI 来源订单一般由系统自动提交：https://help.xlwms.com/d458/dc94/25ac ；妙手 ERP 授权文档指出外部接口自动提交可能不受 OMS 页面开关约束：https://help.xlwms.com/d458/aabc/b26d/f2d3 。这与实际现象高度吻合，具体 AppKey 服务端规则仍需厂商核实。
- **不能再将 isSubmit=0、瞬时状态 0 或 OMS UI 开关 OFF 视为已完成长期草稿箱验收。** 暂停额外真实 CREATE；保留现有订单，不创建、不取消、不提交。
- 两条可验证修复路径：世迈关闭该 AppKey 的服务端自动提交并提供支持草稿的正式 API 契约；或者改用 OMS 支持的本地批量导入草稿流程（官方说明 No_Shipping_Service 导入进入草稿-待提交：https://help.xlwms.com/d458/fcbb/7023/48cf）。
