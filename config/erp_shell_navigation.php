<?php

return [
    ['key' => 'dashboard', 'label' => '仪表盘', 'emoji' => '📊', 'path' => '/dashboard', 'route' => 'dashboard', 'permission' => 'dashboard.view', 'placeholder' => false],
    ['key' => 'orders', 'label' => '订单', 'emoji' => '📋', 'path' => '/orders', 'route' => 'orders.index', 'permission' => 'orders.view', 'placeholder' => false, 'children' => [
        ['key' => 'orders-customers', 'label' => '客户订单', 'path' => '/customers/orders', 'route' => 'customers.orders', 'permission' => 'orders.view', 'placeholder' => false],
        ['key' => 'orders-kol', 'label' => '网红订单', 'path' => '/kol/orders', 'route' => 'kol.orders', 'permission' => 'orders.view', 'placeholder' => false],
        ['key' => 'orders-procurement', 'label' => '外采订单', 'path' => '/orders/procurement', 'route' => 'orders.procurement', 'permission' => 'orders.view', 'placeholder' => false],
    ]],
    ['key' => 'logistics', 'label' => '物流', 'emoji' => '🚚', 'path' => '/logistics', 'route' => 'shell.logistics', 'permission' => 'logistics.view', 'placeholder' => false, 'children' => [
        ['key' => 'logistics-pending', 'label' => '待发货', 'path' => '/logistics/pending', 'route' => 'logistics.pending', 'permission' => 'logistics.view', 'placeholder' => false],
        ['key' => 'logistics-awaiting-tracking', 'label' => '已发待单号', 'path' => '/logistics/awaiting-tracking', 'route' => 'logistics.awaiting-tracking', 'permission' => 'prototype.view', 'placeholder' => false],
        ['key' => 'logistics-in-transit', 'label' => '运输中', 'path' => '/logistics/in-transit', 'route' => 'logistics.in-transit', 'permission' => 'prototype.view', 'placeholder' => false],
        ['key' => 'logistics-exceptions', 'label' => '异常订单', 'path' => '/logistics/exceptions', 'route' => 'logistics.exceptions', 'permission' => 'prototype.view', 'placeholder' => false],
        ['key' => 'logistics-carriers', 'label' => '承运商', 'path' => '/logistics/carriers', 'route' => 'logistics.carriers', 'permission' => 'logistics.view', 'placeholder' => false],
    ]],
    ['key' => 'sync', 'label' => '同步', 'emoji' => '🔄', 'path' => '/sync', 'route' => 'shell.sync', 'permission' => 'sync.view', 'placeholder' => false, 'children' => [
        ['key' => 'sync-orders', 'label' => '同步订单', 'path' => '/sync/orders', 'route' => 'sync.orders', 'permission' => 'sync.view', 'placeholder' => false],
        ['key' => 'sync-warehouses', 'label' => '同步仓库', 'path' => '/sync/warehouses', 'route' => 'sync.warehouses', 'permission' => 'sync.view', 'placeholder' => false],
        ['key' => 'sync-kol', 'label' => '同步网红', 'path' => '/sync/kol', 'route' => 'sync.kol', 'permission' => 'sync.view', 'placeholder' => false],
        ['key' => 'sync-exports', 'label' => '导出平台订单', 'path' => '/sync/exports', 'route' => 'sync.exports', 'permission' => 'sync.view', 'placeholder' => false],
    ]],
    ['key' => 'customers', 'label' => '客户', 'emoji' => '👥', 'path' => '/customers', 'route' => 'shell.customers', 'permission' => 'customers.view', 'placeholder' => false, 'children' => [
        ['key' => 'customers-library', 'label' => '客户列表', 'path' => '/customers/library', 'route' => 'customers.library', 'permission' => 'customers.view', 'placeholder' => false],
        ['key' => 'customers-orders', 'label' => '客户订单', 'path' => '/customers/orders', 'route' => 'customers.orders', 'permission' => 'orders.view', 'placeholder' => false],
        ['key' => 'customers-orders-create', 'label' => '新建订单', 'path' => '/customers/orders/create', 'route' => 'customers.orders.create', 'permission' => 'orders.create', 'placeholder' => false],
        ['key' => 'customers-orders-mine', 'label' => '我的订单', 'path' => '/customers/orders/mine', 'route' => 'customers.orders.mine', 'permission' => 'prototype.view', 'placeholder' => false],
    ]],
    ['key' => 'kol', 'label' => '网红', 'emoji' => '⭐', 'path' => '/kol', 'route' => 'shell.kol', 'permission' => 'kol.view', 'placeholder' => false, 'children' => [
        ['key' => 'kol-library', 'label' => '红人库', 'path' => '/kol/library', 'route' => 'kol.library', 'permission' => 'kol.view', 'placeholder' => false],
        ['key' => 'kol-orders', 'label' => '网红订单', 'path' => '/kol/orders', 'route' => 'kol.orders', 'permission' => 'orders.view', 'placeholder' => false],
        ['key' => 'kol-library-create', 'label' => '新增网红', 'path' => '/kol/library/create', 'route' => 'kol.library.create', 'permission' => 'kol.operate', 'placeholder' => false],
        ['key' => 'kol-opportunities', 'label' => '合作机会', 'path' => '/kol/opportunities', 'route' => 'kol.opportunities', 'permission' => 'kol.view', 'placeholder' => false],
        ['key' => 'kol-collaborations', 'label' => '合作记录', 'path' => '/kol/collaborations', 'route' => 'kol.collaborations', 'permission' => 'kol.view', 'placeholder' => false],
        ['key' => 'kol-communications', 'label' => '沟通跟进', 'path' => '/kol/communications', 'route' => 'kol.communications', 'permission' => 'kol.view', 'placeholder' => false],
        ['key' => 'kol-samples', 'label' => '样品与发货', 'path' => '/kol/samples', 'route' => 'kol.samples', 'permission' => 'kol.view', 'placeholder' => false],
        ['key' => 'kol-content', 'label' => '内容与发布', 'path' => '/kol/content', 'route' => 'kol.content', 'permission' => 'kol.view', 'placeholder' => false],
        ['key' => 'kol-commissions', 'label' => '佣金与结算', 'path' => '/kol/commissions', 'route' => 'kol.commissions', 'permission' => 'kol.view', 'placeholder' => false],
        ['key' => 'kol-affiliate', 'label' => '优惠码与 Affiliate', 'path' => '/kol/affiliate', 'route' => 'kol.affiliate', 'permission' => 'kol.view', 'placeholder' => false],
    ]],
    ['key' => 'inventory', 'label' => '库存', 'emoji' => '📦', 'path' => '/inventory', 'route' => 'shell.inventory', 'permission' => 'inventory.view', 'placeholder' => false, 'children' => [
        ['key' => 'inventory-overview', 'label' => '库存总览', 'path' => '/inventory', 'route' => 'shell.inventory', 'permission' => 'inventory.view', 'placeholder' => false],
        ['key' => 'inventory-list', 'label' => '仓库清单', 'path' => '/inventory/list', 'route' => 'inventory.list', 'permission' => 'inventory.view', 'placeholder' => false],
        ['key' => 'inventory-products', 'label' => '产品库存', 'path' => '/inventory/products', 'route' => 'inventory.products', 'permission' => 'inventory.view', 'placeholder' => false],
        ['key' => 'inventory-sync', 'label' => '批量同步', 'path' => '/inventory/sync', 'route' => 'inventory.sync', 'permission' => 'inventory.operate', 'placeholder' => false],
    ]],
    ['key' => 'procurement', 'label' => '外采', 'emoji' => '🛒', 'path' => '/procurement', 'route' => 'shell.procurement', 'permission' => 'procurement.view', 'placeholder' => false, 'children' => [
        ['key' => 'procurement-overview', 'label' => '外采总览', 'path' => '/procurement', 'route' => 'shell.procurement', 'permission' => 'procurement.view', 'placeholder' => false],
        ['key' => 'procurement-files', 'label' => '外采文件', 'path' => '/procurement/files', 'route' => 'procurement.files', 'permission' => 'prototype.view', 'placeholder' => false],
        ['key' => 'procurement-records', 'label' => '记录', 'path' => '/procurement/records', 'route' => 'procurement.records', 'permission' => 'prototype.view', 'placeholder' => false],
    ]],
    ['key' => 'products', 'label' => '产品', 'emoji' => '🏷️', 'path' => '/products', 'route' => 'products.dashboard', 'permission' => 'products.view', 'placeholder' => false, 'children' => [
        ['key' => 'products-list', 'label' => '产品列表', 'path' => '/products/list', 'route' => 'products.list', 'permission' => 'products.view', 'placeholder' => false],
        ['key' => 'products-skus', 'label' => 'SKU', 'path' => '/products/skus', 'route' => 'products.skus', 'permission' => 'prototype.view', 'placeholder' => false],
        ['key' => 'products-sku-aliases', 'label' => 'SKU别名', 'path' => '/products/sku-aliases', 'route' => 'products.sku-aliases', 'permission' => 'prototype.view', 'placeholder' => false],
    ]],
    ['key' => 'guides', 'label' => '指南', 'emoji' => '📖', 'path' => '/guides', 'route' => 'shell.guides', 'permission' => 'guides.view', 'placeholder' => false, 'children' => [
        ['key' => 'guides-index', 'label' => '全部教程', 'path' => '/guides', 'route' => 'shell.guides', 'permission' => 'guides.view', 'placeholder' => false],
        ['key' => 'guides-videos', 'label' => '视频教程', 'path' => '/guides/videos', 'route' => 'guides.videos', 'permission' => 'guides.view', 'placeholder' => false],
        ['key' => 'guides-manuals', 'label' => '操作手册', 'path' => '/guides/manuals', 'route' => 'guides.manuals', 'permission' => 'guides.view', 'placeholder' => false],
    ]],
    ['key' => 'accounting', 'label' => '会计', 'emoji' => '🧾', 'path' => '/accounting', 'route' => 'shell.accounting', 'permission' => 'accounting.view', 'placeholder' => false, 'children' => [
        ['key' => 'accounting-cost-calculation', 'label' => '成本核算表', 'path' => '/accounting/cost-calculation', 'route' => 'accounting.cost-calculation', 'permission' => 'prototype.view', 'placeholder' => false],
        ['key' => 'accounting-order-costs', 'label' => '每一单成本表', 'path' => '/accounting/order-costs', 'route' => 'accounting.order-costs', 'permission' => 'analysis.view', 'placeholder' => false],
        ['key' => 'accounting-expense-analysis', 'label' => '费用分析表', 'group' => true, 'path' => '/accounting/expense-analysis', 'route' => null, 'permission' => 'prototype.view', 'placeholder' => false, 'children' => [
            ['key' => 'accounting-expense-analysis-actual-2025', 'label' => '2025实际', 'path' => '/accounting/expense-analysis/2025-actual', 'route' => 'accounting.actual-2025', 'permission' => 'prototype.view', 'placeholder' => false],
            ['key' => 'accounting-expense-analysis-execution-2026', 'label' => '2026执行', 'path' => '/accounting/expense-analysis/2026-execution', 'route' => 'accounting.execution-2026', 'permission' => 'prototype.view', 'placeholder' => false],
            ['key' => 'accounting-expense-analysis-forecast-2027', 'label' => '2027预估', 'path' => '/accounting/expense-analysis/2027-forecast', 'route' => 'accounting.forecast-2027', 'permission' => 'prototype.view', 'placeholder' => false],
            ['key' => 'accounting-expense-analysis-monthly', 'label' => '每月明细表', 'path' => '/accounting/expense-analysis/monthly', 'route' => 'accounting.monthly', 'permission' => 'prototype.view', 'placeholder' => false],
            ['key' => 'accounting-expense-analysis-optimization', 'label' => '可优化的部分', 'path' => '/accounting/expense-analysis/optimization', 'route' => 'accounting.optimization', 'permission' => 'prototype.view', 'placeholder' => false],
            ['key' => 'accounting-expense-analysis-variance', 'label' => '预估和实际的差别', 'path' => '/accounting/expense-analysis/variance', 'route' => 'accounting.variance', 'permission' => 'prototype.view', 'placeholder' => false],
        ]],
        ['key' => 'accounting-kol-commissions', 'label' => '网红佣金表', 'path' => '/accounting/kol-commissions', 'route' => 'accounting.kol-commissions', 'permission' => 'prototype.view', 'placeholder' => false],
    ]],
    ['key' => 'settings', 'label' => '设置', 'emoji' => '⚙️', 'path' => '/settings', 'route' => 'shell.settings', 'permission' => 'system.permissions.manage', 'placeholder' => false, 'children' => [
        ['key' => 'settings-statuses', 'label' => '状态设置', 'path' => '/settings/statuses', 'route' => 'settings.statuses', 'permission' => 'prototype.view', 'placeholder' => false],
        ['key' => 'settings-warehouses', 'label' => '仓库设置', 'path' => '/settings/warehouses', 'route' => 'settings.warehouses', 'permission' => 'prototype.view', 'placeholder' => false],
        ['key' => 'settings-users', 'label' => '用户', 'path' => '/settings/users', 'route' => 'user.index', 'permission' => 'system.permissions.manage', 'placeholder' => false],
        ['key' => 'settings-roles-permissions', 'label' => '角色与权限', 'path' => '/settings/roles-permissions', 'route' => 'settings.roles-permissions', 'permission' => 'system.permissions.manage', 'placeholder' => false],
        ['key' => 'settings-audit-logs', 'label' => '操作日志', 'path' => '/settings/audit-logs', 'route' => 'settings.audit-logs', 'permission' => 'prototype.view', 'placeholder' => false],
        ['key' => 'settings-changelog', 'label' => '版本更新', 'path' => '/settings/changelog', 'route' => 'settings.changelog', 'permission' => 'system.permissions.manage', 'placeholder' => false],
    ]],
];
