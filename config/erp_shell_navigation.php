<?php

return [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'emoji' => '📊', 'path' => '/dashboard', 'route' => 'dashboard', 'permission' => 'dashboard.view', 'placeholder' => false],
    ['key' => 'logistics', 'label' => '物流', 'emoji' => '🚚', 'path' => '/logistics', 'route' => 'shell.logistics', 'permission' => 'logistics.view', 'placeholder' => true],
    ['key' => 'sync', 'label' => '同步', 'emoji' => '🔄', 'path' => '/sync', 'route' => 'shell.sync', 'permission' => 'sync.view', 'placeholder' => true],
    ['key' => 'customers', 'label' => '客户', 'emoji' => '👥', 'path' => '/customers', 'route' => 'shell.customers', 'permission' => 'customers.view', 'placeholder' => true],
    ['key' => 'kol', 'label' => '网红', 'emoji' => '⭐', 'path' => '/kol', 'route' => 'shell.kol', 'permission' => 'kol.view', 'placeholder' => true],
    ['key' => 'inventory', 'label' => '库存', 'emoji' => '📦', 'path' => '/inventory', 'route' => 'shell.inventory', 'permission' => 'inventory.view', 'placeholder' => true],
    ['key' => 'procurement', 'label' => '外采', 'emoji' => '🛒', 'path' => '/procurement', 'route' => 'shell.procurement', 'permission' => 'procurement.view', 'placeholder' => true],
    ['key' => 'products', 'label' => '产品', 'emoji' => '🏷️', 'path' => '/products', 'route' => 'shell.products', 'permission' => 'products.view', 'placeholder' => true],
    ['key' => 'guides', 'label' => '指南', 'emoji' => '📖', 'path' => '/guides', 'route' => 'shell.guides', 'permission' => 'guides.view', 'placeholder' => true],
    ['key' => 'accounting', 'label' => '会计', 'emoji' => '🧾', 'path' => '/accounting', 'route' => 'shell.accounting', 'permission' => 'accounting.view', 'placeholder' => true],
    ['key' => 'settings', 'label' => '设置', 'emoji' => '⚙️', 'path' => '/settings', 'route' => 'shell.settings', 'permission' => 'system.permissions.manage', 'placeholder' => true],
];
