# ERP2 Code First — R0 全部 178 个 Laravel Migration 静态索引（2026-11-18）

> **R0 GitHub 静态结果，不等于在 Dev/Production 已执行的 schema。** Source: `beyourlovercom/erpbeyourlovercom@eb8ed64e7790efbcc6271742c8beaac3eaa3b30b`，目录 `database/migrations/`。本轮已逐一读取 **178/178 文件，无读取错误**；按 `Schema::create/table/drop/rename` 字面调用归类为 **176 个不同“被引用的表名”**，这个数字**不是已创建表数量**。

## 1. 关键业务表：触达它们的迁移文件

| 表 | 触达 migration 文件数 | 主要补丁和潜在漂移 |
|---|---:|---|
| `orders` | 13 | `2026_11_01_120000_add_order_workflow_status.php`、`2026_11_03_000001_add_operational_archive_fields_to_orders.php`、`2026_11_03_150000_add_domestic_review_rejection_fields.php`、`2026_11_15_080000_add_order_routing_warehouse.php` |
| `order_items` | 5 | `2026_08_24_100000_create_products_table.php`、`2026_09_14_020500_add_gift_sku_resolution_to_fulfillment.php`、`2026_09_15_010000_add_woocommerce_line_item_identity_to_order_items.php`、`2026_11_13_180000_preserve_order_item_source_product_identity.php` |
| `shipments` | 3 | `2026_08_05_000001_create_shipments_table.php`、`2026_08_27_020000_add_manual_fulfillment_fields_to_shipments.php`、`2026_09_20_021500_upgrade_logistics_carrier_master_v2.php` |
| `warehouses` | 5 | `2026_08_27_171200_add_warehouse_ownership_type.php`、`2026_08_28_190000_add_domestic_flag_to_warehouses.php`、`2026_09_05_171000_add_replenishment_policy_to_warehouses.php`、`2026_09_07_010000_add_fulfillment_available_on_to_warehouses.php` |
| `warehouse_inventory` | 4 | `2026_08_25_000100_create_inventory_foundation_tables.php`、`2026_10_24_000100_soft_delete_warehouse_inventory.php`、`2026_11_13_080000_add_factory_two_month_demand_override.php`、`2026_11_14_093000_add_provider_stock_mirror_to_warehouse_inventory.php` |
| `product_sku_aliases` | 3 | `2026_09_01_100000_create_product_sku_aliases_table.php`、`2026_10_26_200000_add_oms_verification_to_product_sku_aliases.php`、`2026_11_09_100000_add_oms_dispatch_approval_to_product_sku_aliases.php` |
| `order_external_references` | 2 | `2026_08_05_000000_create_orders_tables.php`、`2026_08_27_181000_add_woocommerce_inbound_order_contract.php` |
| `fulfillment_allocations` | 1 | `2026_09_10_180000_create_fulfillment_allocations_table.php` |
| `oversea_warehouse_reviews` | 1 | `2026_09_10_130000_create_oversea_warehouse_reviews_table.php` |
| `inventory_reservations` | 1 | `2026_09_05_160000_create_inventory_reservations_tables.php` |
| `order_operational_markers` | 1 | `2026_08_28_190100_create_order_operational_markers_table.php` |
| `users` | 4 | `0001_01_01_000000_create_users_table.php`、`2026_07_31_040001_add_is_active_to_users_table.php`、`2026_08_24_000000_add_permission_mode_and_permission_user_pivot.php`、`2026_08_28_150000_add_self_profile_fields_to_users.php` |
| `roles` | 1 | `2026_07_31_040000_create_roles_permissions_tables.php` |
| `permissions` | 1 | `2026_07_31_040000_create_roles_permissions_tables.php` |

> 以上只计算直接的 `Schema::*('literal_table')`，不包含普通 `DB::table` 数据更新/动态 SQL 中隐含的业务表；不要通过此表重建最终字段清单。主合同字段级说明参见 `ERP2_CODE_FIRST_DATA_LAST_R0_WORK_PACKAGE_20261118.md` §4A/4C。

## 2. 12 个没有直接 Schema::* 字面调用的迁移：需单独审计数据副作用

| Migration | 初步用途 | ERP2 Dev 原则 |
|---|---|---|
| `2026_08_25_000101_seed_initial_warehouses.php` | 仓库/承运商基础资料 | 仅操作隔离 Dev 的参考/主数据；不能对 Production 执行迁移或回滚 |
| `2026_08_29_130000_backfill_legacy_kol_approval_decisions.php` | 历史业务回填 | 只对隔离 Dev 虚构 KOL 数据进行回填，不导入真实历史 |
| `2026_09_01_210000_register_backup_permissions.php` | 权限预置/回填 | 仅迁移预置 ACL；与原 ERP 有效权限契约对齐 |
| `2026_09_01_223000_correct_us_warehouse_names.php` | 仓库/承运商基础资料 | 仅操作隔离 Dev 的参考/主数据；不能对 Production 执行迁移或回滚 |
| `2026_09_08_011000_add_factory_forwarders_to_logistics_carriers.php` | 仓库/承运商基础资料 | 仅操作隔离 Dev 的参考/主数据；不能对 Production 执行迁移或回滚 |
| `2026_09_08_023000_add_jd_to_logistics_carriers.php` | 仓库/承运商基础资料 | 仅操作隔离 Dev 的参考/主数据；不能对 Production 执行迁移或回滚 |
| `2026_09_15_010000_normalize_warehouse_master_canonical.php` | 仓库/承运商基础资料 | 仅操作隔离 Dev 的参考/主数据；不能对 Production 执行迁移或回滚 |
| `2026_11_04_210010_register_overdue_inventory_manage_permission.php` | 权限预置/回填 | 仅迁移预置 ACL；与原 ERP 有效权限契约对齐 |
| `2026_11_10_150000_backfill_administrator_preset_permissions.php` | 权限预置/回填 | 仅迁移预置 ACL；与原 ERP 有效权限契约对齐 |
| `2026_11_10_170000_register_order_archive_permission.php` | 权限预置/回填 | 仅迁移预置 ACL；与原 ERP 有效权限契约对齐 |
| `2026_11_11_100000_create_amazon_us_manual_warehouse.php` | 仓库/承运商基础资料 | 仅操作隔离 Dev 的参考/主数据；不能对 Production 执行迁移或回滚 |
| `2026_11_14_180000_create_tingon_partner_warehouse.php` | 仓库/承运商基础资料 | 仅操作隔离 Dev 的参考/主数据；不能对 Production 执行迁移或回滚 |

**重要差异：** `migrate:fresh` 在一份全新、独立的 ERP2 Dev 数据库上，不只是建空表——仓库、承运商、权限等引用资料还可能被 migration 初始化。我们定义的 **Data Migration Last** 指**真实生产业务数据最后导入**，不应阻止经过检查的 Dev 基础 reference seed；但任何历史 KOL 业务回填、仓库标准化/修正、权限 backfill 都必须以隔离合成数据做正/负例和回滚检查。绝不针对 ERP Production 做 `migrate:fresh`。

## 3. 继续 R0 必须获得的运行证据（本次尚未执行）

1. **仅 007windows 独立 Laravel Dev DB**：先对 DB 连接做 hostname/name 确认，明确不指向 Production；然后才执行 `php artisan migrate:status` / Dev `migrate:fresh`。
2. 对 178 个迁移逐个列出实际执行、pending、failed；运行后使用 Laravel schema inspect 生成最终表/列/索引/外键清单，并与 2026-11-18 GitHub 基线比较。
3. 单独验证 12 个数据副作用迁移在干净/已有虚构记录/重复运行/安全回滚中的结果；有任何不可控数据覆盖则 HOLD。
4. 实际 `GET` 业务队列使用 Dev 真实数据库（仅合成单据），服务端逐角色权限、分页、count、异常路径和 Audit 结果必须 PASS。
5. 再为 R1/R2 开发分支建立准确 HEAD PHPUnit/TypeScript/Vite/007windows UAT；**所有 Prod 订单与仓库操作保持禁止**。

## 4. 统计与限制

- GitHub 迁移文件：178，全部读取成功。
- 字面 `Schema::*` 所触达不同表名：176（包含 create、alter、drop 等，不是“最终 176 张表”）。
- 没有 `Schema::*` 的 migration：12，均须检查其 `DB::table` 级数据副作用。
- 显式 `DB::statement/unprepared` 文件：`2026_11_14_140000_create_order_manual_field_overrides.php`（按字面搜索，不涵盖间接 raw SQL）。
- 本次没有实测 PHP/Laravel migrator，不声明所有 178 个 migration 可执行或已完成；Production 真实列、修复数据状态和索引仍以实际 runtime 为准。
