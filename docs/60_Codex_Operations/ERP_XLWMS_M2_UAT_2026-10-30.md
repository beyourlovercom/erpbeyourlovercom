# ERP US-SM 世迈 M2-C → M2-E：UAT 验收交接

**日期：2026-10-30** · **状态：隔离自动化 UAT 完成；正式发货与真实 Dede 浏览器 UAT 未执行**
关联：Issue #538 / #544；已关闭 API 重发货 V1 PR #545 / #557 / #554。独立 M2 分支：`feature/erp-xlwms-m2-fulfillment-uat-20261030`。

## 1. 当前真实生产事实（只读证据）

- ERP 订单：`007-1027-c41863`，ID 7753，US-SM 世迈。
- 原 OMS：`OBS0272610290RS`，状态 4（取消），原 ERP Shipment 保持 pending。
- 新 OMS：`OBS0272610300RS`，申请 UUID `dd6751d9-2aac-4a0f-83ef-907c51b11969`，实际 `productList: CL279B × 2`。
- 新 OMS **outboundOrder/detail** 返回 `status=2` / `statusName=仓库处理中`，`outboundTime` 为空，`logisticsTrackNo` 为空；不能给 ERP 写 shipped、Woo/AfterShip Tracking。
- 新 OMS 创建后状态曾为 0，现为 2；不能猜测是自动还是人工触发提交，仅可确认仓库已经处理。
- 未再次调用 OMS CREATE、SUBMIT、CANCEL；所有生产 CREATE 门禁仍关闭。

## 2. 研发内容

### M2-C 只读查询

- **新增** `XlwmsOrderGateway::readExactOrderDetail()`，调用白名单的官方 `outboundOrder/detail`，校验出库单号、第三方订单标识、平台订单标识、仓库，必须唯一一条。仅向 ERP 操作层返回脱敏业务字段（状态码、`statusName`、SKU 数量、Tracking、实际承运商、是否存在出库时间/费用证据）。
- **新增** `ReshipmentFulfillmentService::preview()`：原申请和新 OMS 必须存在，原 ERP Shipment / Dede 审核不变，SKU×数量精确一致；只有 `status=3` 且 `statusName=已出库`，有效 `outboundTime`、非测试 Tracking、可信且启用的实际承运商才允许进入 ERP 发货审核。对状态 0 / 2 / 4 / 身份不一致/空 Tracking/未知 carrier 全部 fail-closed。

### M2-D 受控 ERP 回写（默认 OFF）

- 新增受限路由：
  - `GET /logistics/oversea/us-3sm/orders/{order}/xlwms/m2/{requestId}/preflight`
  - `POST /logistics/oversea/us-3sm/orders/{order}/xlwms/m2/{requestId}/apply`（需要 `review_token`）
- `config/xlwms.php` 三重门禁：`XLWMS_M2_APPLY_ENABLED=false`、`XLWMS_M2_ORDER_ID=0`、`XLWMS_M2_REQUEST_ID=`；这**与** M1 / V1 CREATE 开关独立。禁止批量或跨订单写入。
- 确认操作重新读取实时 OMS，核对 64 位预览 token；再锁定 ERP Order、Shipment 和重发申请。复用现有 `ShipmentService::markShipped()`、`OverseaWarehouseReview` 进度和 `ManualSync` 待同步状态，避免重复库存/销售收入、重复 Tracking。记录 `m2_erp_shipment_applied` 审计事件并把子履约状态标为 `shipped_applied`。
- Dede 执行 ERP 回写**不会自动写 Woo / AfterShip**。后续仍由 Vita 在既有同步入口复核 Tracking、Woo 和 AfterShip；勿新造并行通道。
- 不信任 OMS 未核实的时间区，ERP 发货确认时间使用 UTC `now()`，原 OMS 出库时间用于资格审核而非伪造精确 UTC 时间。

### M2-E 补发成本与审计边界

- `xlwms_reshipments.cost_status` 保持 `pending`；`additional_cost_cny` 不赋值。OMS 存在报价/费用字段不等于已核实结算费，未提供账单币种换算、汇率与成本归属凭证前不编造支出。
- 新的履约事件有独立审计，原销售订单不增加第二笔订单收入。最终发货成本核销必须在真实出库及对账证据可得后单独验收。

## 3. UAT 验收矩阵

| 场景 | 预期/结果 |
| --- | --- |
| OMS `2` / 仓库处理中，Tracking 空 | 阻断 ERP 回写，**自动化通过** |
| OMS `3`、已出库、出库时间、真实 USPS Tracking、SKU 对齐 | 允许预览，但正式门禁 OFF 不应用，**自动化通过** |
| OMS `3`，SKU 数量变化或承运商不认识 | 阻断，**自动化通过** |
| OMS 详情身份不是申请的新 OMS | 拒绝，**自动化通过** |
| OMS 已出库但没有出库时间 | 拒绝，**自动化通过** |
| 单笔准许，review token 错误 | 拒绝，不写 Shipment，**自动化通过** |
| 单笔准许，正确预览与真实数据（隔离 mock） | ERP shipped、Tracking 写入、额外成本仍 pending、无 Woo 网络写入，**自动化通过** |
| 再次执行已应用的 token | 阻断第二次回写，**自动化通过** |
| 非 logistics.operate 用户查询 M2 | 403，**自动化通过** |
| Dede 桌面端查看仓库处理中 / 已出库但门禁关闭 | 组件级 Vue UAT 通过；**实际 Dede 登录浏览器尚未通过人工视觉验收** |

测试命令：
`./vendor/bin/phpunit tests/Feature/Logistics/XlwmsSingleOrderDispatchTest.php`
`npm run test -- --run resources/js/Pages/Logistics/Oversea/Show.test.ts`
`npm run typecheck`
`git diff --check`

## 4. Dede 后续人工 UAT（未来受控 Dev 或 Production 验证）

1. 在 Dede 海外仓 US-SM 待发货视图，找到 ERP 7753，点 **重新下发仓库**。
2. 确认只有唯一新 OMS `OBS0272610300RS`，没有再次 CREATE 按钮。
3. 点 **M2 核对仓库出库与 Tracking**，看见 **仓库处理中 (2)**、Tracking 尚未产生、新增成本待结算、无“确认 ERP 已发货”按钮。
4. 如果仓库真实出库并有可信 Carrier+Tracking，重新核对 `outboundTime`、SKU 和 Dede 审核。需额外 Owner 单笔授权开启 M2 三重门禁才能按真实订单确认 ERP 发货。
5. ERP 发货确认后，仍由 Vita 通过现有官网同步链路完成 Woo/AfterShip 读回，核对失败/重试；费用等待真实结算证据。

## 5. 发布限制 / 后续依赖

此分支只能经 CI/UAT 之后申请受控发布审批；没有 Owner 明确 `pass`，不合并或写生产，不修改世迈仓库订单。即使部署只读部分，M2 APPLY 始终 OFF。真实 OMS 7753 当前为处理中，无法声称真实发货全链路 UAT 已完成。

**下一现场依赖**：OMS `OBS0272610300RS` 出现 `已出库 (3)`、非空真实 `outboundTime`、可信承运商和 Tracking。若出库已到却没有 Tracking，应保留原单待审核，并让 Dede 人工核查仓库，不回写 Woo。
