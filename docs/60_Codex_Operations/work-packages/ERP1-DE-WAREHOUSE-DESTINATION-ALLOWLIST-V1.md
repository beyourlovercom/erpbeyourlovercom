# ERP1 德国天运仓目的国白名单 V1

状态：Owner 提供名单，已记录；德国本土例外存在明确的现行规则冲突，待澄清后实施和验收。ERP1 only.

## Owner 原始名单（31 个国家，ISO 3166-1 alpha-2）
AT 奥地利、BE 比利时、CZ 捷克共和国、DK 丹麦、EE 爱沙尼亚、ES 西班牙、FI 芬兰、FR 法国、GR 希腊、HU 匈牙利、IT 意大利、LT 立陶宛、LU 卢森堡、NL 荷兰、PL 波兰、SE 瑞典、SI 斯洛文尼亚、SK 斯洛伐克、PT 葡萄牙、LV 拉脱维亚、BG 保加利亚、MT 马耳他、RO 罗马尼亚、HR 克罗地亚、IE 爱尔兰、CY 塞浦路斯、GB 英国、LI 列支敦士登、MC 摩纳哥、SM 圣马力诺、NO 挪威。

当且仅当订单收件国家在最终确认的白名单内，德国仓（DE / de-tianyun）才出现在分仓弹窗的库存数量、人工备选、系统推荐、发货仓下拉、费用参考和仓库地图候选列表中。国家代码统一 trim/uppercase，英国按 GB 处理，UK 兼容归一化。缺失国家不可推断为允许。按订单重新计算，避免上下单切换继承旧候选。

白名单是目的国资格，不等于库存充分、渠道可寄送、报价有效或开启自动分仓。保留现有实际履约 SKU、库存、渠道限制、费用确认及权限门禁。历史分仓快照/已发货单不隐藏、不改写。新手工/快捷/推荐/批量分仓入口统一服务端检查，不能仅靠隐藏 UI；不扩大至历史订单重分仓、物流商实际推送或其它仓库规则。

## 发布阻塞（不擅自改写 Owner 名单）
原始清单没有德国 DE；现行 config/warehouse_routing.php 的 DE 固定本土路由启用了 automatic=true，原 ABC UAT 使用德国收件地址。严格照清单会禁用德国本土分仓，需 Owner 明确该国是否加入。旧 DK/FI/SE 属默认中国发货/自动路由 blocked；新白名单允许展示库存及人工候选，不意味着解除自动推荐成本、安全及执行门禁。禁止把区域白名单视为自动发货授权。

实现优先复用 config/warehouse_routing.php / WarehouseRoutingPolicy 作为唯一事实源；后端投影到分仓页面和 AllocationRatePreviewService、FulfillmentAllocationSubmissionService，检查 FulfillmentAllocationDecisionService 与现有规划器是否漏掉新资格约束。测试覆盖名单内外、缺失国家、GB/UK、上下单、库存/礼品/替换、手工/快捷/推荐/批量、费用、历史记录及原有国内仓逻辑。独立增量发布，不回滚 ABC，不修改 ERP2。
