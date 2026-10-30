# XLWMS 第二次 Reship：单订单库存门禁例外

ERP 7753 第二次重新下发，UUID 34d69459-51f0-49c4-a443-3a8747b2c843。
世迈最近一次只读全量库存同步的最新快照为 ID9，整仓状态 blocked，原因是多个无关 SKU 的映射或 ERP 仓库库存行缺失。
目标 SKU CL279B 精确匹配 Product ID 1467，OMS 可用 27、锁定 0，订单需要 2。

新代码只在 CREATE 三重白名单（开关 + order_id + UUID）精确匹配、申请为 approved_for_reissue 或 prechecking 且已经审批、唯一最新 V2 blocked 快照拥有最近120秒 OMS 全量复核时间、目标 SKU/product_id 匹配、OMS 数量自洽且充足时允许 Reship 继续。
任何不通过依旧返回 OMS_SNAPSHOT_NOT_FRESH/NOT_RECONCILED。整仓全量应用规则不变，普通单笔/批量也不会使用此例外。
测试 117 / 880 PASS。生产目标库存未修改；需要再次刷新 OMS 全量只读快照，然后立即执行本次一次性 CREATE，回读新 OMS 状态 0 并在草稿箱确认。
如果 OMS 变为状态 2 或 4，禁止重复 CREATE，保留新 OMS 单号并停止。