# R4-6 国内发货异常订单 Global Grid

Owner 于 2026-11-11 独立验收 R4-5B 六项全 PASS，并批准
R4-6「原有表格迁移 + 异常原因可视化」最小范围研发。
没有授权自动合并或发布 Production。

## 冻结业务范围
- 原有 DomesticLogisticsController applyView(exceptions) 条件
  严格保持不变：Factory 发货状态为 exception；或
  OrderProgressQuery 的 progress_conflict。原有业务过滤规则不变。
- 仅新增只读投影 exception_type、exception_reason，两字段
  在异常视图才计算并传出，不添加新数据库状态、不触发写入。
  有物流异常备注时优先显示原备注（最多 200 字）；没有备注则
  明确提示查看原记录。履约冲突只说明已有事实；未知类型提示
  订单详情核对，不能假设异常已解决。
- Global Grid 增加「异常类型」「异常原因」两列，其余共享 SKU、
  状态、收件人、Tracking、搜索、20/50/100 分页、账号列偏好。
  右侧固定操作仅「查看订单」现有订单详情链接，
  不提供新的异常解除、重发、审核或发货操作。
- 原有底部统一选择/批量归档仍按现有权限执行，不新增批量工具条。
  不重复展示旧表「原批量操作」入口。
- view=exceptions&global_grid=0 一键回退原表；R4-7 历史速达订单、
  纬狮订单继续原表默认 OFF。
- 独立全局 /logistics/exceptions 页面及其异常处理流程不在本范围。

## 验收门禁
1. PHPUnit：发货异常及原物流备注；进度冲突细分；原表与
   新表同一订单范围；搜索、分页、不匹配订单排除；只读无写入。
2. Vue：两个异常列及固定右侧只读动作；原表回退；
   不生成新按钮/上传入口；权限与底部单一批量区维持。
3. 隔离 Chrome：合成异常订单，真实 Grid 显示、原因可读、
   只读「查看订单」链接、选择、原表回退，测试库清理 PASS。
4. GitHub final-head CI；Owner 单独审批合并与受控发布；
   以当时最新 Production 为基线，哈希检查并保留上版回退。
5. 发布后 Owner 真实站视觉 PASS，才关闭 R4-6 并推进 R4-7。

## 开发分支验收证据
- PHP 82/82 PASS（1526 断言），覆盖两类异常、两种进度冲突、
  原表回退、20 条分页、搜索及其它历史视图不变。
- Vue 35/35 PASS；TypeScript、Playwright 类型、Vite build PASS。
- 美国 Mac mini 隔离 Chromium 16/16 PASS，测试数据库清理 PASS；
  新增用例确认异常原因来自合成数据库事实，「查看订单」为唯一新表行操作，
  无原操作/发货按钮，原表回退以及历史速达默认原表。
- 独立分支及生产文件哈希门禁待 PR/CI 后核对；不执行自动发布。

## 状态
开发与隔离回归完成。等待 PR/CI，R4-6 Production 尚未启用。
