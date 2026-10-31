# XLWMS US-SM — 人工导入 V1 与 M2 支线整合（2026-10-31）

## Owner 冻结的唯一生产流程

**ERP 下载 Excel → 物流人员在世迈 OMS 人工导入 → 人工上传真实面单/提交/发货 → 物流人员在 ERP 人工确认已发货 → Vita 既有 Woo/AfterShip 同步。**

- **PR #581：正式 Production V1 基线，Owner 签收 PASS。** 已核对生产 current symlink 指向 `xlwms-manual-v1-pr581-20261031-e641791b`。
- **人工 XLSX 导入：Owner 2026-10-31 确认已在真实 OMS 成功，PASS；**此结论来源于 Owner 的现场验收反馈，不重复导入 C41845B，也不以旧文档的待验收描述覆盖新的反馈。
- 使用现有 WMS-64 模板与已经签收的字段（仓库 Yangfan；人工 Excel **Upload_Shipping_Label**；USPS；真实 SKU 与数量），不再改变模板或开发浏览器自动下单。
- 现有单条/批量下载、人工发货确认、原有 OMS 状态/Tracking 只读查询、Vita 官网同步均保留；订单未具备真实发货单号前不能将 ERP 标为已发货。
- OMS 旧单（包括已取消的测试单）不重复 CREATE；使用人工流程时由物流人员在 OMS 当前草稿箱先查重，之后再人工导入。

## 支线整合审计

| 来源 | 处理决定 | 原因 |
| --- | --- | --- |
| PR #565（API CREATE 后草稿状态核验） | 历史安全保护保留，但 **HTTP 自动 CREATE 永远受 410 阻断** | 新 V1 不再依赖自动下单 |
| PR #581（人工导出/OMS 人工导入） | **正式运行唯一基线，冻结** | Owner 已现场 PASS |
| PR #582（数量格式兼容） | 已合并 main，**未在正式站发布；禁止未经新 UAT 的整包发布带入** | 已成功的人工导入格式不主动改变 |
| PR #562（旧 M2 自动 OMS 证据 → ERP 发货 APPLY） | **不合并；归档旧 Draft PR** | 与人工在 ERP 确认发货相冲突，不引入新 M2 APPLY、三重开关或额外 UI |
| Issue #538（统一履约大范围计划） | 新 V1 日常流程不阻塞；未来要求需单独立项/审批 | 原大范围 R8/M2 不构成新授权 |

## 具体保留与不保留

**保留：** 在现有 US-SM 物流页下载订单、访问 OMS 草稿箱、历史 OMS 状态只读查询、现有受限接口 `xlwms/status`、`reship-status` 与只读更新、原有手工 Tracking ERP 确认、原有 Woo Tracking Outbox/AfterShip 工作流。

**不保留旧 PR #562 的新能力：** `ReshipmentFulfillmentService::apply`、`m2.apply` API、根据 OMS code=3 自动标记 ERP shipped、与新旧重发 UUID 强绑定的额外发货路径。此旧 PR 中的商品/身份/承运商校验思路仅作历史参考，不作为正式版新功能。

**信息边界：** 已保留的 OMS 状态接口以**已有 ERP–OMS 关联**为前提。人工新导入单如果尚未关联原生 OMS 编号，不应宣称它已自动回写状态/Tracking；本次不擅自增加新数据写入或改动成功的人工导入流程。

## 重要：main 与 Production 存在已知差异（PR #582）

- `main` 在 PR #581 后已合并 PR #582 数量字段的“整数文本兼容”修改。
- **正式站仍基于 PR #581**，未包含 #582。任何后续从 `main` 构建并发布的包都会默认带入 #582，不能只凭新 PR 改动较小就声称安全。
- **下次 Production 发布前**必须以批准的目标 release 对比 #581，明确选择：(A) 用已批准且验收通过的 #582 文件；或 (B) 以 #581 为导出模板基线做单独受控补丁发布。不默默覆盖、不倒退已经验收成功的人工导出。
- 生产变更须独立 Owner `pass`，可编写 PR、跑 CI，但本次整合**不合并任何旧 M2 自动 APPLY，也不部署 Production**。

## 验收与关闭边界

1. 复核 HTTP 自动 CREATE 单笔、批量、Web Draft、Reship 均通过 `RejectXlwmsAutomaticCreate` 阻断。
2. 确认人工导出、人工确认发货、既有只读状态/Tracking、手工 Woo 同步路由均保留。
3. 用测试防止旧 PR #562 的 `m2.apply` / `m2.preflight` 被误引入主流程。
4. GitHub/CI 绿灯后本支线只做**规则及审计整合**；已经通过的 OMS Excel 实单人工验收不重测。
5. 后续真实订单的履约操作继续由物流人员执行，不自动触发出库、ERP 记发货、Woo 或 AfterShip 写入。
