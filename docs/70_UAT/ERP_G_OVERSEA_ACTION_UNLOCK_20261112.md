# G-P3 补齐海外仓 Global Grid 操作列取消固定和隐藏设置

2026-11-12 Owner 已确认 G 的速达订单、待分仓、同步总表
正式站视觉验收 PASS（3/5），准备第4张海外仓视觉验收。

## Preflight 发现
当前 Production /logistics/oversea 的 Show.vue 仍对操作列设置：
- pinned:right, lockPinned:true, lockVisible:true, suppressMovable:true
- required-column-ids 为 order 和 actions
这会让共享 OmsGrid 无法接受右侧固定 0 或隐藏操作列的请求。
所以第4张海外仓不能在这一问题解决前宣布 PASS。

## 最小范围
- 只解除海外仓「操作」列的 lockPinned、lockVisible、
  suppressMovable，保留原有 pinned:right 默认和 194px 宽度。
- 只把 required-column-ids 从 order,actions 减至 order。
- 保留 existing DomesticGridActionCell 的审核通过、缺货、
  驳回原因、重新审核、原操作回跳及全部后端 API/门禁。
- 保留仓库切换、表格筛选、批量归档、Shipping、Tracking、
  Tab 30 保护、其他视图权限与数据，绝不在测试中发货或同步网站。
- 账号偏好/固定列数的保存与恢复由现成 G 共享 Grid 承担。
- 纬狮累计视图 R4-7B 仍 OFF。

## UAT
- Vue Show 及批量列表测试：确认操作默认右侧但无 lock，
  操作允许取消勾选/移动，实际审核回调不变。
- PHP 物流相关回归：只读、隔离 SQLite。
- 独立 Chrome：打开海外仓全订单；操作列可显示/隐藏、
  右侧固定=0、刷新后保持、恢复默认成功；清理测试环境。
- 最新 GitHub CI 通过后，单独合并及受控 Production 发布；
  保留最新正式站回退版本，由 Owner 重新检查第4张表。
- 未完成 Production UAT 之前不计入 G 4/5。

状态：独立 Dev 分支；Production 未变更。
