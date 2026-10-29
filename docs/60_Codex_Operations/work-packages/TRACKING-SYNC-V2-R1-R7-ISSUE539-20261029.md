# Tracking Sync V2 — R1–R7 受控验收记录（Issue #539）

**范围**：修复历史 Completed Outbox 掩盖官网状态漂移，复用实际 ERP 发货、发货页单笔同步、独立 WooCommerce / AfterShip 插件；不部署正式站。

## 工时预算（工程小时）

| 阶段 | 任务 | 预算 |
|---|---|---|
| R1 | Outbox 已完成后的远端实时核对与受保护状态拦截 | 2–3h |
| R2 | 独立 Woo 测试订单与安全隔离 | 1–2h |
| R3 | ERP 真实发货服务与待同步记录 | 2–3h |
| R4 | 运营端受限单笔推送与 Woo 实际写回 | 1–2h |
| R5 | AfterShip 插件真实展示与顾客端模板 | 2–4h |
| R6 | 重复点击、历史状态漂移与幂等 | 1–2h |
| R7 | 退款状态、冲突、纠正单号、CI/发布准备 | 2–4h |

**合计：11–20h**。AfterShip 云端商户认证/云端收录不包含其中，若需另计 2–4h。

## 实现边界

- 只有显式运营选单 `trackingSelected` 才启用 `verifyRemote=true`。自动补漏和到期重试继续使用旧幂等路径，不增加自动改写。
- 历史 Tracking Outbox 已 `completed` 时，先读取 Woo 当前订单状态；对于退款、取消、失败、挂起和回收状态拒绝追踪写入与订单状态恢复。
- 允许的显式手动核对使用真实 Tracking Gateway 再验一次当前包裹/商品行，校验完毕才核对订单状态，必要时纠正 Woo `Processing` 漂移。
- 历史订单状态 Outbox 已 `completed` 时不能凭旧记录直接宣告成功；必须通过远端同步接口确认 `verified=true`。
- 首次同步中，若 Tracking 成功但订单状态失败，显式运营单笔流程报告失败/冲突而非一概成功；Tracking Outbox 不被伪造回滚。
- 网关级别保护已退款/取消/失败/挂起订单的 Tracking 和状态，无论来自手动还是自动入口均禁止覆盖。

## R2–R6 真实隔离链路（已通过）

- 美国 Mac 本地 WordPress 7.1.3、WooCommerce 11.2.1 和真实 AfterShip Tracking 1.18.5 插件，绑定 127.0.0.1:18763。
- 独立 MySQL 数据库 aicc_tracking_v2_20261029，虚拟 Woo 订单 #14、商品行 3 和 4。
- ERP 测试端使用隔离 SQLite，调用真实 ShipmentService::markShipped，录入 USPS、测试 Tracking 和发货日期，确认 Shipped 和 Pending。
- 通过 Vita 对应的受限权限、仅本单白名单以及真实 Laravel HTTP POST 发货页同步路由，向本地 Woo REST 发送 Tracking。
- Woo 状态成功变更 Completed；重复点击不增加 Outbox；再次退回 Processing 后，明确手动同步恢复 Completed。
- 独立 Woo 被设置 Refunded 后再点击同步，返回 warning、保持 Refunded、不重复修改 Tracking。
- 真正 AfterShip 插件的 get_tracking_items、get_tracking_items_for_display 与 display_tracking_info 顾客模板均读取到唯一包裹及两个正确商品行。
- MU 安全插件阻断外部 HTTP、邮件与 Woo Webhook。没有向 AfterShip Cloud 发送请求，云端收录不在本轮通过范围内。

## R7 回归、发布门禁

- R1/R7 PHP 回归：44 tests、198 assertions PASS。
- 物流/海外仓/现有 Woo 入站回归：53 tests、561 assertions PASS。
- R2–R6 真实独立 Woo 端到端：1 test、27 assertions PASS。
- 国内和海外发货页 UI：22 tests PASS；TypeScript 和 Vite build PASS。
- 纠正 Tracking 后重新 Pending；既有远端商品行冲突必须返回 warning，不能覆盖原物流信息。
- 本地虚拟订单 #14 被验收流程设置为 Refunded；再次跑测试须创建新的隔离订单，禁止复用生产订单。
- GitHub CI 与合并后，正式 ERP 生产部署仍需 Owner 独立 PASS。
- 生产 Tracking 三项功能开关继续 OFF，不允许自动补推历史失败任务。
- C40373 的真实 Woo Canary 已在前一支线完成，本分支不再改它。
