# ERP-PCOST-PRODUCTION-GATE-20261119 · Production 候选发布控制

状态：方案 B 受控实现；**禁止仅凭此文件直接切换 Production**。正式切换前必须由 Owner 审阅最终预检，并再次明确授权。

## 发布边界

- 仅包含 ERP 月度进销存 SKU 成本导入、美元管理参考成本表及管理员确认写入机制。
- 不包含与成本功能无关的新增订单、协作人或液体/材质功能。
- Production 默认禁止确认写入：`ERP_PRODUCT_COST_IMPORT_PRODUCTION_APPLY_ENABLED=false`；Dev 标志绝不在 Production 环境开启写权限。
- 开启独立 Production 标志之后，仍需登录、`products.operate` 路由权限、活跃 `super_admin` 或 `role_preset_administrator` 角色。
- 每次确认必须基于上传指纹和最新预检指纹，填写至少 8 字的审核理由，并逐字输入 `确认导入N条成本`；N 为新增已确认成本条数。

## 成本和审计不变量

- 基础 SKU 规范化（例如 `VA617A（国内新版）` → `VA617A`）；冲突/待匹配在页面指定真实产品和实际人民币成本。
- 同产品、同生效月份的已有确认成本与新成本不一致时阻断；重复同价按幂等处理；不更新或删除旧的成本来源记录。
- 数据库事务内锁定涉及产品、重新核查各月确认计划后写入。
- 每批通过 `product.cost_import.batch_confirmed` 留下文件/计划指纹、月份、计数、汇率参考、操作人及固定审计原因码；填写的业务理由保存于审计 after 元数据。
- 人民币保留为原始成本凭证；美元产品成本表为可追溯的管理参考；订单核算按生效日期读取已确认成本与记账汇率，不追改历史订单。

## 已完成的隔离验证

- 美国 Mac mini，`127.0.0.1:18877`，独立分支、独立依赖目录、独立 SQLite 文件 `/Users/a/AI/007/uat/erp-cost-prod-gate-20261119.sqlite`，`APP_ENV=production` 只作本地 Production 模式模拟；`MAIL_MAILER=log`、`WOO_PRODUCT_SYNC_ENABLED=false`。
- 浏览器使用隔离测试管理员登录；上传 2026 年 11 月 VA617A 两条价差源数据，快速确认 ERP SKU 与手动实际成本 ¥32.70；新增 1 条，9 月 ¥28 和 10 月 ¥30.50 原始记录保持不变。
- 美元管理参考以本地测试汇率 1 USD = ¥7.10 显示 11 月 $4.6056、10 月 $4.2957、9 月 $3.9436；成功保存批次审计原因码。
- `./vendor/bin/phpunit tests/Feature/Products/MonthlyInventoryCostImportTest.php tests/Feature/Finance/ConfirmedProductCostSourceTest.php`：20 tests / 187 assertions PASS。
- `npm run typecheck` PASS；`npm run build` PASS。具体 CI 和发布候选 SHA 在最终预检时登记。

## Production 切换前必须复核

1. 确认当前 Production `current` 发布版本 SHA、主站 Nginx 站点配置、备份和回退目录；不复用 Dev 账号、密钥或隔离 SQLite。
2. 校验候选发布的变更白名单：仅成本功能文件和本控制文件。确认没有混入主干新增功能。
3. 在正式数据库上执行**只读**校验：表结构/权限、历史每 SKU/月份成本分布、可恢复备份状态；不得预先导入或更新历史成本。
4. Production 开关保持关闭，先部署候选并做只读健康检查。Owner 另行批准后，才可按限定时间窗启用管理员成本确认开关。
5. 若路由、权限、成本预检、只读健康或审计异常：立即恢复旧 `current` 发布链接与旧配置，校验历史成本数量/散列不变，并停用独立开关。

**本工作包不授权切换正式链接、不授权 Production 成本写入、不授权自动执行回退。**
