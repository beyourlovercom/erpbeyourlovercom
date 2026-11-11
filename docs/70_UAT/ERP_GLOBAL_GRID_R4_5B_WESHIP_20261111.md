# R4-5B 今日纬狮 Global Grid — 独立视图启用

Owner 已于 2026-11-11 完成 R4-5A「今日速达」正式站 6/6 视觉验收 PASS，
并要求继续 R4-5B 的独立迁移。本改动只启用 view=today-weship
默认 Global Grid；不会改变其他国内视图，也不会触发任何真实发货。

## 冻结业务约束
- 原 OrderProgressQuery::domesticTodayProvider(..., 'weship') 查询不变：
  商务确认有货且待发货的纬狮订单、今天实际已发出的纬狮订单；
  历史订单创建日期不应作为额外限制，速达订单不得混入。
- 纬狮下单 XLSX 的 24 列模板和现有导出 URL 保持不变：
  /logistics/china/export/weship；不在今日纬狮页面增设上传入口。
- Global Grid 原有字段、SKU/69条码、账户级列设置、20/50/100 分页、
  搜索、右侧有权限的重新审核 / 同步官网物流 / 重新同步、
  底部唯一批量操作区全部复用 R4-5A。
- 独立回退：?view=today-weship&global_grid=0 使用原生表格；
  通过业务视图 Tab 再次进入纬狮，默认恢复 Grid。
- BYL_COMPACT_WORKSPACE_V1 页头和底部帮助复用已发布实现，
  不添加重复 CSS、顶部提醒或额外批量工具条。
- 中文显示与数据库中英文名称分别保留；不变更工厂分仓、
  Carrier master、Tracking Apply、OMS、WooCommerce 同步或权限模型。

## 需要通过的门禁
1. PHPUnit：纬狮与速达隔离；Grid/原表相同订单；明确关闭与重新开启；
   搜索、分页、订单状态均正确；现有 24 列导出契约回归 PASS。
2. Vue：纬狮已用 Grid，保留唯一下载链接、单一批量工具与原表回退；
   既有角色操作单测及中文显示回归 PASS。
3. 隔离 Chrome：真实登录的测试角色，在两视图之间 Tab 切换；
   纬狮仅出现纬狮模拟单，下载、选行、底部工具、原表回退一致；
   不执行生产环境真实 POST，测试数据清理 PASS。
4. GitHub final-head CI、Production 最新基线源文件指纹校验、
   独立发布回执、保留上版 rollback；仅在明确授权后部署。
5. Owner 在正式站独立视觉 PASS 后关闭 R4-5B，再推进 R4-6。

## 当前状态
独立开发分支，Production 尚未启用 R4-5B；最终 CI 和发布审批待检查。
