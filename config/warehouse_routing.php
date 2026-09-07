<?php

/*
|--------------------------------------------------------------------------
| Warehouse Routing Policy — Single Source of Truth
|--------------------------------------------------------------------------
| Business routing, fallback, aliases and warehouse priority rules live here.
| Do not hard-code warehouse routing policy inside recommendation or
| automatic-allocation services. Runtime safety switches live separately.
*/

return [
    'version' => '2026-09-07-v3',

    'global' => [
        'require_active_warehouse' => true,
        'require_full_snapshot' => true,
        'require_fulfillment_ready' => true,
        'allow_split_shipment' => false,
        'allow_cross_region_fallback' => false,
    ],

    'aliases' => [
        'US-NY' => ['US-NY', 'US-NJ', '美东纽约仓', '美东仓'],
        'US-LA' => ['US-LA', 'MorePlus', '美西洛杉矶仓', 'USA 美西仓'],
        'US-SJ' => ['US-SJ', 'SMGJ', '美西加州3仓', '世纪仓', '世迈仓', '世迈国际仓', 'SMGJ 世迈国际仓'],
        'CA' => ['CA', 'CA02', '加拿大仓库', '加拿大仓'],
    ],

    'countries' => [
        'DE' => [
            'automatic' => true,
            'strategy' => 'fixed',
            'warehouses' => [
                ['code' => 'DE', 'priority' => 1, 'role' => 'primary'],
            ],
        ],

        'CA' => [
            // Assisted-only until Owner opens the Canada automatic gate.
            'automatic' => false,
            'strategy' => 'fixed',
            'allow_cross_region_fallback' => false,
            'warehouses' => [
                ['code' => 'CA', 'priority' => 1, 'role' => 'primary'],
            ],
        ],

        'US' => [
            // Keep assisted-only until Owner explicitly opens the US automatic gate.
            'automatic' => false,
            'strategy' => 'east_zone_then_priority',
            'allow_cross_region_fallback' => true,
            'warehouses' => [
                ['code' => 'US-NY', 'role' => 'east'],
                ['code' => 'US-SJ', 'role' => 'west_primary'],
                ['code' => 'US-LA', 'role' => 'west_secondary'],
            ],
            'rules' => [
                'east_zone' => [
                    'warehouse' => 'US-NY',
                    'max_zone' => 4,
                    'data_file' => 'resources/data/usps/us-ny-zone-ranges-2026-05-01.php',
                    'source_effective_date' => '2026-05-01',
                    'unknown_behavior' => 'fallback_priority',
                ],
                'fallback_priority' => ['US-SJ', 'US-LA', 'US-NY'],
            ],
        ],
    ],
];
