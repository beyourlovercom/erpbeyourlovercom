# R4-7A 中国工厂速达订单（累计列表）Global Grid

Owner 2026-11-11 通过 R4-6 六项正式视觉验收后，批准 R4-7A
优先于 R4-7B 的独立研发和验收。此阶段仅分支研发、PR/CI 和
可回退候选准备，不代表直接合并或发布 Production。

## 与今日速达的关键区别
- view=4px 是原生中国工厂承运商累积视图，只按
  LOWER(shipment.carrier) = 4px 和现有 baseQuery 筛选，
  不是仅今日单、仅已发货、仅已审核或按订单创建日筛选。
- 保留审核通过待发货、未审核、历史已发货及发货异常订单。
  仍排除现有 eligibility 不准入、已取消、分仓母单、历史物流导入
  引用与非中国工厂仓订单；保留原来订单日期/ID 排序。
- R4-7A 只允许 4px 默认使用 Global Grid；weship 累积视图
  保持原表默认 OFF，直到 R4-7A Owner 正式站视觉 PASS
  并分别批准 R4-7B。

## UI 与功能复用
- 默认 Global Grid、原有 20/50/100 分页、搜索、
  SKU/数量/金额、Tracking/官网同步、独立账号级列偏好。
- view=4px&global_grid=0 一键回退；通过 Tab 再进入恢复默认 Grid。
- 固定右侧直接复用已正式验收的 DomesticGridPendingCell，
  让可发货的审核通过待发货订单按权限直接执行
  填写物流、确认发货、重新审核；已发货且符合原
  eligibleForTracking 条件的可同步官网物流。前后端原有
  canQuickShip、carrierReady、Review/Tracking 权限、付款及安全
  阻断逻辑不变，无新增服务、路由或自动发货。
- 仅保留现有唯一底部批量操作区；可批量归档及符合原条件
  的网站物流同步，沿用已有角色与单号门禁。移除这页难懂的
  原操作回跳和重复原表/原批量工具区。
- 4PX 27 列导出和 XLS/XLSX 回传仍由现有待发货或今日承运商
  入口负责，不能在累计清单上重复增加按钮或回传接口。
- 共用 BYL_COMPACT_WORKSPACE_V1，不另加页头、提示、样式和 CSS。

## 独立验收
1. PHP：历史 60 天已发货也存在、未审核/发货异常也存在，
   仅 4PX、不混纬狮；原表/Grid 与搜索相同；Weship 默认 OFF。
2. Vue：累计速达默认新版，无原操作或重复旧表，
   一键原表回退；每个行内修改均调用现有受控 API。
3. 隔离 Chromium：模拟 60 天前订单；待发货的直接
   填写物流弹窗可打开，但未满足门禁时确认发货仍 disabled；
   唯一批量区、原表回退、纬狮仍 OFF；测试数据库彻底清理。
4. 最新 main/CI、Production 当前源码哈希、独立候选与回退
   门禁；Owner 单独授权发布；正式站视觉 PASS 后 R4-7A 才关闭。
5. R4-7B 仅在 R4-7A 完成视觉 PASS 后独立启用与验收。

## 状态
R4-7A 分支实施中。Production 尚未启用速达历史 Global Grid。
