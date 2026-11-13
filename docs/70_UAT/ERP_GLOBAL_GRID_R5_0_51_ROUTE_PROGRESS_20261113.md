# ERP Global Grid R5-0 · 原51入口实测状态盘点（2026-11-13）

事实源：GitHub main e57cd4814ebdca2c58f9674297d30a452493df0f（建分支时）及 Production erp-factory-demand-pr759-20261113；各独立已验收 PR #736/#752/#753/#754/#755 和 R4-8 终检报告。原51项来自冻结文件 ERP_GLOBAL_GRID_R4_51_PAGE_SCOPE_20261110.md。

**统计口径**：Owner 验收 = 已完成；出现 Grid 源码 ≠ Owner 已验收；未找到明确凭据 ≠ 代码一定不存在，均列为待核对而不是声称“未开发”。同一路由多视图按既定51项编号，不与九张国内视图重复相加。

**本次盘点**：已验收 5 项；已有 Grid/试点代码但缺独立 Owner 验收凭据 5 项；本批 L2 开发 3 项；其余 38 项待逐页核对。生成 UTC：2026-11-13T08:44:01.479063+00:00。

| # | 冻结页面 | 冻结范围 | 最新审计状态 |
|---:|---|---|---|
| 1 | /orders | 全部订单总表 | 已有 Grid/试点代码，需独立 Owner 验收 |
| 2 | /customers/orders | 客户订单 | 已完成 Owner 视觉验收 |
| 3 | /kol/orders | 网红订单 | 已有 Grid/试点代码，需独立 Owner 验收 |
| 4 | /orders/procurement | 外采订单 | 已有 Grid/试点代码，需独立 Owner 验收 |
| 5 | /orders/archive | 归档订单 | 未取得完成凭据（不得标记已完成） |
| 6 | /products/list | 产品总表 | 未取得完成凭据（不得标记已完成） |
| 7 | /products/skus | SKU 清单 | 已有 Grid/试点代码，需独立 Owner 验收 |
| 8 | /products/sku-aliases | SKU 别名管理 | 未取得完成凭据（不得标记已完成） |
| 9 | /purchase | 采购订单 | 未取得完成凭据（不得标记已完成） |
| 10 | /inventory/products | 产品库存 | 未取得完成凭据（不得标记已完成） |
| 11 | /inventory/list | 仓库清单 | 未取得完成凭据（不得标记已完成） |
| 12 | /inventory/overdue | 90 天超期库存 | 未取得完成凭据（不得标记已完成） |
| 13 | /inventory/sync | 库存同步记录 | 未取得完成凭据（不得标记已完成） |
| 14 | /inventory/packaging | 包装与测量清单 | 未取得完成凭据（不得标记已完成） |
| 15 | /inventory/{warehouse} | 仓库详情库存子表 | 未取得完成凭据（不得标记已完成） |
| 16 | /logistics/china | 国内发货七视图 | 已完成 Owner 视觉验收 |
| 17 | /logistics/oversea | 海外仓发货清单 | 已完成 Owner 视觉验收 |
| 18 | /logistics/wait-stock | 待分配 / 待库存确认 | 已完成 Owner 视觉验收 |
| 19 | /logistics/review | 物流复核单 | 已有 Grid/试点代码，需独立 Owner 验收 |
| 20 | /logistics/awaiting-tracking | 待回填物流单号 | R5-L2 Dev 技术验收 PASS（待 Owner/Production） |
| 21 | /logistics/in-transit | 运输中订单 | R5-L2 Dev 技术验收 PASS（待 Owner/Production） |
| 22 | /logistics/exceptions | 异常物流订单 | R5-L2 Dev 技术验收 PASS（待 Owner/Production） |
| 23 | /logistics/cancelled | 已取消物流订单 | 未取得完成凭据（不得标记已完成） |
| 24 | /logistics/completed | 已完结物流订单 | 未取得完成凭据（不得标记已完成） |
| 25 | /logistics/carriers | 承运商清单 | 未取得完成凭据（不得标记已完成） |
| 26 | /logistics/historical-import | 历史物流导入 | 未取得完成凭据（不得标记已完成） |
| 27 | /kol/library | 网红资料库 | 未取得完成凭据（不得标记已完成） |
| 28 | /kol/collaborations | 合作记录 | 未取得完成凭据（不得标记已完成） |
| 29 | /kol/content | 内容与发布 | 未取得完成凭据（不得标记已完成） |
| 30 | /kol/affiliatewp | AffiliateWP 清单 | 未取得完成凭据（不得标记已完成） |
| 31 | /kol/commissions | 佣金清单 | 未取得完成凭据（不得标记已完成） |
| 32 | /kol/payouts | 佣金付款 | 未取得完成凭据（不得标记已完成） |
| 33 | /kol/owner-mapping | 网红负责人映射 | 未取得完成凭据（不得标记已完成） |
| 34 | /kol/affiliatewp/reconciliation | AffiliateWP 数据核对 | 未取得完成凭据（不得标记已完成） |
| 35 | /kol/affiliatewp/binding-activation | 绑定激活记录 | 未取得完成凭据（不得标记已完成） |
| 36 | /kol/import-preview | 网红导入预览 | 未取得完成凭据（不得标记已完成） |
| 37 | /kol/import-activation | 网红导入激活 | 未取得完成凭据（不得标记已完成） |
| 38 | /accounting | 财务总览明细 | 未取得完成凭据（不得标记已完成） |
| 39 | /accounting/order-costs | 订单成本与利润清单 | 未取得完成凭据（不得标记已完成） |
| 40 | /sync | 同步中心清单 | 未取得完成凭据（不得标记已完成） |
| 41 | /sync/orders | 订单同步 | 已完成 Owner 视觉验收 |
| 42 | /sync/warehouses | 仓库同步 | 未取得完成凭据（不得标记已完成） |
| 43 | /sync/kol | 网红同步 | 未取得完成凭据（不得标记已完成） |
| 44 | /sync/woocommerce | WooCommerce 同步 | 未取得完成凭据（不得标记已完成） |
| 45 | /sync/wpapi | WordPress API 同步 | 未取得完成凭据（不得标记已完成） |
| 46 | /settings/fx | 汇率设置中的表格 | 未取得完成凭据（不得标记已完成） |
| 47 | /customers/library | 客户资料库 | 未取得完成凭据（不得标记已完成） |
| 48 | /customers/companies | 客户公司清单 | 未取得完成凭据（不得标记已完成） |
| 49 | /marketing/subscribers | 营销订阅用户 | 未取得完成凭据（不得标记已完成） |
| 50 | /settings/users | 员工用户管理 | 未取得完成凭据（不得标记已完成） |
| 51 | /marketing/traffic | 流量分析明细表 | 未取得完成凭据（不得标记已完成） |

## R5-L2（#20/#21/#22）门禁

- 只迁移主列表，保持 StatusBar、权限、安全 Tracking 保存、复制单号、异常标记及异常处置原 API。
- Global Grid 在 Dev/测试由原 pilot 可显式切换；Production 不能因代码合并自动向未获批准的员工放开，保留 global_grid=0 原表回退。
- 服务端真实全局搜索及受控 20/50/100 分页。原表和 Grid 的查询必须同源，已归档/旧归档/删除、历史物流、待复核排除不变。
- 绝不自动重发、自动标记 Completed、自动写 Woo；真实出库需原门禁。验收/CI PASS 后独立 PR 提交，Production 发布必须另获明确授权。
- 后续批次先根据这张表复核剩余页业务路由和 Owner 凭证，再重估工时。
