<?php

return [
    'orders' => [
        ['order_number' => 'KOL26073001-007', 'created_at' => '2026-07-30 18:42', 'shipping_status' => '待发货', 'owner' => 'Hiko', 'customer' => 'Richard Delgado', 'country' => '哥伦比亚', 'sku' => 'MF414A', 'quantity' => 1, 'warehouse' => '美国美西加州仓', 'tracking_number' => ''],
        ['order_number' => 'KOL26072918-003', 'created_at' => '2026-07-29 15:08', 'shipping_status' => '已发待单号', 'owner' => 'Andy', 'customer' => 'Mariana López', 'country' => '墨西哥', 'sku' => 'MF319C', 'quantity' => 2, 'warehouse' => '美国美东纽约仓', 'tracking_number' => ''],
        ['order_number' => 'KOL26072811-014', 'created_at' => '2026-07-28 10:26', 'shipping_status' => '运输中', 'owner' => 'Hiko', 'customer' => 'Sofia Chen', 'country' => '美国', 'sku' => 'MF208B', 'quantity' => 1, 'warehouse' => '美国美西加州仓', 'tracking_number' => '4PX3003010148134CN'],
        ['order_number' => 'KOL26072502-001', 'created_at' => '2026-07-25 09:12', 'shipping_status' => '已收货', 'owner' => 'Andy', 'customer' => 'Camila Rojas', 'country' => '哥伦比亚', 'sku' => 'MF414A', 'quantity' => 3, 'warehouse' => '美国美西加州仓', 'tracking_number' => 'YT238190441'],
        ['order_number' => 'KOL26072420-009', 'created_at' => '2026-07-24 20:04', 'shipping_status' => '缺货', 'owner' => 'Hiko', 'customer' => 'Ana Torres', 'country' => '智利', 'sku' => 'MF901X', 'quantity' => 1, 'warehouse' => '外采', 'tracking_number' => ''],
        ['order_number' => 'KOL26072317-006', 'created_at' => '2026-07-23 14:37', 'shipping_status' => '信息缺失', 'owner' => 'Andy', 'customer' => 'Luis Mendoza', 'country' => '秘鲁', 'sku' => 'MF120D', 'quantity' => 1, 'warehouse' => '加拿大仓', 'tracking_number' => ''],
        ['order_number' => 'KOL26072208-012', 'created_at' => '2026-07-22 11:16', 'shipping_status' => '补发', 'owner' => 'Hiko', 'customer' => 'Valentina Cruz', 'country' => '哥伦比亚', 'sku' => 'MF414A', 'quantity' => 1, 'warehouse' => '美国美西加州仓', 'tracking_number' => ''],
        ['order_number' => 'KOL26072101-002', 'created_at' => '2026-07-21 08:48', 'shipping_status' => '无需发货', 'owner' => 'Andy', 'customer' => 'Daniel Kim', 'country' => '韩国', 'sku' => 'DIGITAL-01', 'quantity' => 1, 'warehouse' => '无需发货', 'tracking_number' => ''],
    ],
    'detail' => [
        'order_number' => 'KOL26073001-007', 'shipping_status' => '待发货', 'warehouse' => '美国美西加州仓', 'owner' => 'Hiko',
        'customer' => ['name' => 'Richard Delgado', 'phone' => '+57 3103280154', 'email' => 'Richarddelgadocollabs@gmail.com', 'country' => 'Colombia', 'state' => 'Cundinamarca', 'city' => 'Bogotá D.C.', 'address' => 'CL 63A SUR 76B 17', 'postcode' => '111921', 'website' => 'BYL Official Store', 'website_order' => '#78431', 'created_at' => '2026-07-30 18:42', 'synced_at' => '2026-07-30 18:47', 'product' => 'MF414A', 'quantity' => 1],
        'structured_address' => ['Full name' => 'Richard Delgado', 'Address Street' => 'CL 63A SUR 76B 17', 'City' => 'Bogotá D.C.', 'State' => 'Cundinamarca', 'Zipcode' => '111921', 'Phone' => '+57 3103280154', 'Contact Email' => 'Richarddelgadocollabs@gmail.com', 'Country' => 'Colombia', 'Notes' => 'Colombia | KOL26073001-007 | MF414A × 1'],
        'carrier' => '4PX', 'tracking_number' => '4PX3003010148134CN', 'quantity' => 1,
        'timeline' => [
            ['actor' => 'Hiko', 'action' => '创建了网红订单', 'time' => '18:42'], ['actor' => '系统', 'action' => '将原始地址拆分为美国仓格式', 'time' => '18:43'], ['actor' => 'Hiko', 'action' => '核对了物流地址', 'time' => '18:51'], ['actor' => 'Andy', 'action' => '复制了物流信息', 'time' => '19:02'], ['actor' => 'Andy', 'action' => '填写物流单号', 'time' => '20:04'], ['actor' => '系统', 'action' => '发货状态变更为已发', 'time' => '20:12'], ['actor' => '系统', 'action' => '发货状态变更为运输中', 'time' => '20:18'],
        ],
    ],
];
