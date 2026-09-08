# BYL ERP Changelog

## 2026-09-07

今天完成并合并到主线的 ERP 开发：

### Accounting / Order Profit

- 完成 Accounting A8 Profit Dashboard 与证据化订单成本物化。
- 完成 Woo 财务证据 bootstrap，并补齐 legacy refund 兼容。
- 完成 Accounting V1.1B UTC session、preview 与 Woo JSON 幂等性加固。

### Logistics / Fulfillment

- Historical Logistics R0–R7 完成最终收口，并补齐历史订单、Shipment Attempt 与 Owner UAT 控制中心。
- 修复人工选择仓库后的库存 Reservation 链路。
- 完成仓库路由策略集中化，以及美国承运商 / USPS Zone、加拿大、德国分层路由规则。
- 增加 Global Fulfillment Plan Preview，用于比较履约方案后再由人工决策。

### Inventory

- 增加 Warehouse Fulfillment Readiness Gate，用于判断仓库是否具备真实履约条件。

### 记录规则

- 这里只记录已经完成并进入主线的成果。
- 仍在开发、讨论或等待 UAT / Release Gate 的内容不写入正式版本记录。
