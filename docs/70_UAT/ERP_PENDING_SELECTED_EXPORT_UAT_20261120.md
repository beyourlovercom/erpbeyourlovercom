# ERP 待发货选中订单下载 — Owner UAT（2026-11-20）

## Scope / state

- Work Package: 国内 + 海外仓「待发货」表格勾选订单后单独下载 XLSX。
- Branch: `feat/pending-selected-export-20261120`，从 `origin/main@13881567` 创建。
- Stage: **OWNER UAT PASS（2026-11-20）**；Owner 在会话中回复“pass，授权合并，然后发布”。逐项 Actual 未单独记录，自动化检查不替代该 Owner 决定。
- 数据：独立 `database/uat-pending-selected-20261120.sqlite`，仅合成数据，无外部仓库请求/派单。
- Dev 地址（US Mac 本机）：`http://127.0.0.1:18842`；正式 `http://erp.b` 不受影响。
- 国内：`http://127.0.0.1:18842/logistics/china?view=pending`。
- 美国 MorePlus：`http://127.0.0.1:18842/logistics/oversea/us-moreplus?view=pending`。

## Automated gates — evidence

- PHP 两份物流回归文件：退出码 0；2363 assertions；98 warnings（未失败）。
- 前端 Vitest：116/116 passed（国内 35、海外 81）。
- `npm run typecheck`：退出码 0。
- `npm run build`：退出码 0；`ERP_WORKSPACE_TABS_BUILD_PASS`。
- 实际 UAT HTTP 登录成功，国内及海外待发货 Inertia 页面均 200。
- 实际 XLSX 内容：国内完整 3 条，选中 2 条，SKU 合并选中 2 条，逐条核对均正确。
- 实际 XLSX 内容：MorePlus 完整 3 条，选中 2 条，逐条核对均正确。
- 越界选择（国内混入海外、MorePlus 混入德国）均 422；旧完整下载保留。

## Owner 人工 UAT — 总体 PASS；逐项记录留存

| Case | 操作 | Expected | Actual | Owner |
|---|---|---|---|---|
| UAT-CN-01 | 国内待发货勾选 A、C，不选 B | 出现“下载已选 2 单” | 未单独记录 | 总体 PASS |
| UAT-CN-02 | 点击“下载已选 2 单”打开 Excel | 仅 A、C 两订单，无 B | 未单独记录 | 总体 PASS |
| UAT-CN-03 | 取消所有勾选、点击原完整下载按钮 | 选中下载入口隐藏，原按钮仍完整下载 | 未单独记录 | 总体 PASS |
| UAT-US-01 | MorePlus 待发货勾选 A、C，不选 B | 出现“下载已选 2 单” | 未单独记录 | 总体 PASS |
| UAT-US-02 | 点击选中下载打开 Excel | 只含 A、C 参考单，无 B | 未单独记录 | 总体 PASS |
| UAT-US-03 | 清除勾选/切换队列或仓库 | 选中状态清除，原下载保留 | 未单独记录 | 总体 PASS |
| UAT-SAFE-01 | 刷新页面后重新选择 | 不会沿用失效的旧选择 | 未单独记录 | 总体 PASS |

合成订单：国内 `UAT-SELECT-CN-A/B/C`（数据库 ID 8/9/10）；MorePlus `007-1120-c90011/12/13`（ID 11/12/13；Excel 参考号 `C90011/12/13-007`）。禁止真实仓库下单或上传。

## Gate

- Browser 插件连接尝试超时，未取得视觉截图；因此不能代签人工视觉 PASS。
- Owner 已于 2026-11-20 回复 **PASS**，并明确授权合并与 Production 发布；逐项 Actual Result 未提供，不伪造截图或分项实测记录。
- Production 发布须独立执行精确 SHA、备份、回滚与发布后只读核验；不允许真实仓库下单或订单状态写入。
