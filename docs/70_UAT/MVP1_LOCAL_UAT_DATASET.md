# MVP1 本地 UAT 合成数据集

数据只用于本地 UAT，禁止使用真实客户、真实订单、真实地址或真实 Provider 凭据。

## 用户

- `owner@example.test` — UAT Owner/Admin；凭据只在 `.codex/byl-erp-local-uat-access.txt`。
- `logistics@example.test` — UAT Logistics Operator；凭据只在凭据文件。
- `restricted@example.test` — UAT Restricted Operator；凭据只在凭据文件。

## 订单

| 订单 | 来源 | 初始业务状态 | 发货 | 同步 | 财务 |
|---|---|---|---|---|---|
| `UAT-WEB-001` | website | pending_shipment | 否 | 无 | 无 |
| `UAT-KOL-001` | kol | pending_shipment | 否 | 无 | incomplete |
| `UAT-MAN-001` | manual_other | shipped | 是 | pending | 无 |
| `UAT-WEB-002` | website | shipped | 是 | completed | 已完成同步 |
| `UAT-MAN-002` | manual_other | shipped | 是 | returned | 需纠正 |
| `UAT-WEB-003` | website | shipped | 是 | 无 | complete / confirmed profit |
| `UAT-KOL-002` | kol | pending_shipment | 否 | 无 | missing cost / not confirmed |

Seeder：`database/seeders/LocalUatSeeder.php`。只允许 `local` 或 `testing` 环境，Production 不自动运行。
