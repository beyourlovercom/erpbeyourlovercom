# 世迈 US-SM / XLWMS 出库草稿规则（Owner 2026-10-30）

**冻结规则：所有 ERP 世迈正常单笔、批量及重新下发订单，统一先进入世迈 OMS「草稿箱」，物流专员上传面单并人工确认后才提交仓库。**

1. OMS 原生物流渠道始终为 `No_Shipping_Service`；ERP USPS / Priority Mail 的本地选择不改变该渠道。
2. `SingleOrderDispatch`、`WarehouseBatchDispatch`、`XlwmsReissueAdapter` 必须通过 `createDraftSingle` 发送 `isSubmit=0`，禁止任何自动 SUBMIT、重复 CREATE。
3. 世迈 OMS「一件代发出库」中的「自动提交已获取面单和无需面单的出库单」设置必须为 **OFF**。即使 `isSubmit=0`，若 OMS 租户此项启用，后续仍可从「草稿-待提交」自动流转至「仓库处理中」。
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
