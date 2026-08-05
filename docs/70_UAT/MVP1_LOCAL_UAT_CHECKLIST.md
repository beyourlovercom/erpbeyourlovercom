# MVP1 本地人工 UAT Checklist

## 前置条件

- [ ] Owner 确认开始人工 UAT；Codex 不填写 Actual Result，不替代 PASS 决策。
- [ ] 访问 `http://erp.b`，确认页面连接本地 UAT MySQL `byl_erp_local_uat`。
- [ ] 读取凭据文件 `.codex/byl-erp-local-uat-access.txt`；不得把密码复制到 Git、PR、截图或日志。
- [ ] 确认 Remote Dev、Production、SSH 和真实外部 Provider 均未访问。

## 重置、Seed、备份

```bash
php artisan migrate:fresh --force
php artisan db:seed --class=Database\\Seeders\\LocalUatSeeder --force
mysqldump --no-tablespaces --single-transaction --skip-lock-tables byl_erp_local_uat > .codex/backups/byl-erp-local-uat-$(date +%Y%m%d).sql
shasum -a 256 .codex/backups/byl-erp-local-uat-*.sql
```

恢复演练使用独立本地数据库，禁止覆盖生产或远程数据库。

## 场景记录

| 编号 | 用户角色 | 操作步骤 | 预期结果 | Actual Result | PASS/FAIL | 截图/证据 | Defect ID | 复测结果 |
|---|---|---|---|---|---|---|---|---|
| UAT-01 | Owner/Admin | 登录、打开 Dashboard、退出 | 登录成功；真实计数显示；退出后需重新登录 |  |  |  |  |  |
| UAT-02 | Owner/Admin | 创建 Website 订单，填写多个 Item、地址、负责人 | 订单号唯一；保存快照；审计记录生成 |  |  |  |  |  |
| UAT-03 | Owner/Admin | 创建 KOL 与 Manual Other 订单 | 来源和状态正确；列表可筛选 |  |  |  |  |  |
| UAT-04 | Owner/Admin | 搜索、筛选、打开详情并修正地址/Item | 服务端校验；订单、地址、Item 一次性更新；审计记录生成 |  |  |  |  |  |
| UAT-05 | Logistics Operator | 打开发货队列，复制收件人/完整发货/Tracking | 复制格式稳定；只显示允许字段 |  |  |  |  |  |
| UAT-06 | Logistics Operator | 填 Carrier、Tracking、发货时间并标记发货 | 状态变为 shipped；Manual Sync 只创建一条 pending |  |  |  |  |  |
| UAT-07 | Logistics Operator | 打开同步队列并复制 Website payload | 不调用外部 API；payload 可人工复制 |  |  |  |  |  |
| UAT-08 | Owner/Admin | 人工更新网站后标记 completed | 记录 actor、时间和备注 |  |  |  |  |  |
| UAT-09 | Owner/Admin | Reopen、Return、Correction | 状态可纠正；重复操作不重复创建记录 |  |  |  |  |  |
| UAT-10 | Owner/Admin | 填完整成本并查看 Order Analysis | 服务端计算 Revenue、Cost、Profit、Margin |  |  |  |  |  |
| UAT-11 | Owner/Admin | 查看缺失成本订单并导出 CSV | 未完整成本不得显示 confirmed profit；CSV 与筛选一致 |  |  |  |  |  |
| UAT-12 | Restricted Operator | 打开订单并尝试创建、发货、同步、分析 | 允许/禁止与权限合同一致；禁止操作返回 403 |  |  |  |  |  |
| UAT-13 | Owner/Admin | 停用用户后尝试登录 | 登录失败且不泄漏账户状态 |  |  |  |  |  |

## 人工结论

```text
HUMAN_UAT_STATUS: NOT STARTED
OWNER_DECISION: <Owner填写>
AUTOMATED_ACCEPTANCE: 不等同于人工 UAT PASS
```
