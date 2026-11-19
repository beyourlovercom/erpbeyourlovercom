# BYL ERP 腾讯 KOL XLSX 导入 — 管理员操作与 Dev UAT（2026-11-19）

**状态：R0–R3 代码实现 / 自动回归通过；真实 Owner Local/UAT 未验收；未合并、未部署。**

## 安全边界

- 导入入口：`/kol/affiliatewp/tencent-import`；只有同时持有 `kol.view`、`kol.operate`、`kol.affiliates.manage`、`system.permissions.manage` 的账号可以访问与上传。
- 上传 XLSX 在 Laravel 请求临时区解析；**不将原文件永久存放在磁盘或 Git**。数据库只保存文件 SHA-256、文件名、批次信息、精确身份字段以及显式确认的业务负责人值。
- 不保存物流收件人、电话、完整地址、税号、原始运单、原订单号等与 KOL 身份匹配无关的数据。不上传给 ChatGPT / Claude / Codex。
- 在输入“负责人”列名前，必须业务核实并勾选确认：该列是**网红商务负责人**，而不是物流跟单负责人。未确认，服务端拒绝该列。
- 其他 KOL 经理沿用原权限，但无法浏览、生成或裁决腾讯专属导入批次。
- 匹配预览和负责人映射不修改 KOL；实际创建 / AffiliateWP 绑定必须分别经过原有的管理员激活计划及环境开关。
- 本功能不创建 WordPress / AffiliateWP 远端账号，不修改 Woo 订单、Referral 原始计佣、合同或现有 KOL 负责人归属。

## 管理员操作步骤
1. 在正式 WWW AffiliateWP 快照已按原机制同步的前提下，进入 ERP `网红 → AffiliateWP`，点击右上角“腾讯表格导入（管理员）”。
2. 选择从腾讯文档“【007日更】BYL发货信息表 - 腾讯-物流-同步”导出的 `.xlsx` 文件；填入含有 KOL 身份数据的**工作表名称**（示例 `网红-KOL`，以导出文件中的实际工作表为准）。
3. 手动核对邮箱列名和社交主页列名（至少映射其中一个）；平台、Handle 可选。若真实表格没有网红业务负责人，请**保持负责人列为空**，后续单独人工分配。
4. 点击“生成安全预览”：查看匹配的现有 ERP KOL、唯一 AffiliateWP 快照、冲突和未识别记录。最多 64 MiB / 5,000 行；文件缺失／工作表不存在／身份列有歧义，均拒绝。
5. 前往 `/kol/import-preview` 聚合真实身份，检查冲突后生成候选；前往 `/kol/owner-mapping` 映射真实 ERP KOL 负责人。
6. 管理员在 `/kol/import-activation` 生成创建计划，核对 checksum、ready/blocked 数量；仅在获批的 Local/Dev 环境开关允许时执行。
7. 管理员再到 `/kol/affiliatewp/binding-activation` 生成现有 WWW Affiliate 快照绑定计划，复核准确账户 ID，再按已有校验和/环境守卫执行。对重复执行，既有激活服务保持幂等。
8. 原有 AffiliateWP “立即同步／转为新 KOL／绑定现有 KOL”入口不变，可作为人工逐个核对和补录通道。
## `pixelpebbles_2` 真实验收条件

- WWW AffiliateWP provider affiliate_id `2169`、WP user ID `7254` 与 ERP 镜像账号 ID `2179` 的原始快照不可修改/重建。
- 从真实 XLSX 中找出该账号唯一的邮箱或社交主页身份证据，确认不存在同邮箱/同 URL 冲突，再独立确认业务负责人。
- 导入前 KOL 可能不存在；通过创建计划后只生成一个 KOL，再通过绑定计划将镜像 2179 指向该 KOL。
- 检查原 AffiliateWP provider 状态、Referral 金额/状态、Woo 订单及 KOL 负责人可见范围均未改变。
- 尚无真实导出的腾讯 XLSX 文件供本分支使用；**本次仅合成文件测试，未在 Production 转换 `pixelpebbles_2`。**

## 实施与自动验证结果

- 分支 `feat/kol-tencent-import-20261119`，源头 `origin/main@078de3ec`；仅美国 Mac mini 的自建 BYL DC 进行代码编辑和本地验证。
- `php artisan test tests/Feature/Kol`：335 tests / 1,712 assertions PASS。
- `npm exec vitest run resources/js/Pages/Kol/`：18 test files / 122 tests PASS。
- `npm run typecheck`、`npm run build`（含 Workspace Tabs 验证）及改动 PHP 文件 `pint --test` PASS。
- 全库 `npm test`：3 个库存模块测试文件共 7 个失败（InventoryBatchSync、WarehouseClearanceGiftPool、WarehouseCostCenterP3）；不在当前 Work Package 变更范围，暂未作归因或改动。
- 必要后续门禁：隔离 Dev 真正页面预览和 Owner UAT PASS；之后 Draft PR/CI 与合并治理，Production 发布另需明确批准。
