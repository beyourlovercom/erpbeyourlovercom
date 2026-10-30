# US-SM / 世迈 OMS 草稿工作流 V2 — 2026-10-30 Owner PASS

**最高优先级业务要求**：所有从 ERP 分配到 US-SM 的订单（正常单、批量、Reship）必须在世迈 OMS 的 **草稿－待提交** 队列停留，物流专员替换/上传真实 USPS 面单并核对 Tracking 后，才由人工提交仓库。不得用 `outboundOrder/create` 直接进入仓库处理中。

## 真实已验收路径

- OMS 网页直接选择仓库 Yangfan、原生渠道 `Upload_Shipping_Label`（USPS 上传物流面单）、承运商 `USPS`，上传红色 VOID / DO NOT SHIP 测试占位 PNG，点击 **保存草稿**；第 4 张实际测试单 `OBS0272610300RU` 在 OMS 草稿列表，真实 API 状态 0、无 Tracking。本次仅验收 OMS 网页保存草稿，不是 OpenAPI 自动创建。
- 官方 OMS Excel 批量导入同样支持 `Upload_Shipping_Label` 渠道，导入后停留待提交，并由人工后续导入面单。Excel 导入本身通常不需要假面单，不能自动提交。
- 旧 OpenAPI `isSubmit=0` 被服务端后续自动流转至状态 2，OMS UI 自动提交开关 OFF 仍不能阻止。世迈官方明确无法关闭这个机制。故生产不得再调用 `outboundOrder/create` 创建 US-SM 订单。

## ERP V2 实现

1. 保留现有 OMS 官方 Excel 导出表结构；US-SM 渠道固定输出 `Upload_Shipping_Label`、承运商 `USPS`，其余仓库导出不受影响。继续人工进入 OMS 执行「导入操作」并核对草稿箱。
2. Dede 页面隐藏自动 API 发货、自动 Reship CREATE、整仓 API 同步入口；提供「下载 USPS 草稿导入表」「进入 OMS」「下载 VOID 测试占位图」。VOID 文件仅适用于 OMS 网页手动创建时必需上传文件的测试；它不能成为有效面单。禁止将它用于仓库实际交运。
3. API 网关的 `outboundOrder/create` **生产硬拦截**，包括任何经单笔、批量、Reship 或旧路由抵达的路径。库存、SKU、OMS 状态及 Tracking 只读 API 继续保持。
4. Reship 申请/审批仍保留审计，独立新 UUID 用于 Excel 参考编号，禁止重复复用原订单参考；人员在 OMS 导入后输入 OMS 单号，ERP 严格查询当前草稿状态 0、仓库、USPS 上传面单渠道、平台及参考编号、SKU 与数量一致才允许绑定该申请。绑定不标记 ERP、Shipment、Woo 为发货。
5. 后续 OMS 状态读回兼容人工导入时 `thirdOrderNo` 为空，必须由已经完成的人工绑定审计事件作为身份信任依据，并继续核对 `platformOrderNo` 与 `referOrderNo`。
6. 真正上传的 USPS 面单不得为测试占位图，必须由物流专员补充并确认 Tracking 后，由人工确认提交仓库；出库、物流回写仍按原独立流程。

## 门禁

- 生产发布需要 Owner `pass`（本轮 Owner 已批准这条工作流研发；真实 OMS 创建、取消、提交均不包含在本轮代码发布内）。
- 修复后的正式环境需实际 UAT：Excel 渠道、承运商值；OMS Excel 导入进入草稿；人工绑定新 OMS 状态；未提交不产生 Woo/AfterShip 更新。
- 已有的 OMS `OBS0272610300RU` 是实际验证样本，已存在且为草稿；不得再次复制、创建或提交这张测试单。

参考：https://help.xlwms.com/d458/fcbb/7023/48cf 和 https://help.xlwms.com/d458/fcbb/7023/8910