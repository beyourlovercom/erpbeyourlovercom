<?php

/*
|--------------------------------------------------------------------------
| Warehouse Routing Policy — Single Source of Truth
|--------------------------------------------------------------------------
| Business routing, fallback, aliases and warehouse priority rules live here.
| Do not hard-code warehouse routing policy inside recommendation or
| automatic-allocation services. Runtime safety switches live separately.
*/

$dePreferredCountries = ['CZ', 'PL', 'SK', 'HU', 'SI', 'AT', 'NL', 'BE'];
$deCostGateCountries = ['FR', 'ES', 'IT', 'PT', 'GR', 'EE'];
$deBlockedCountries = ['DK', 'FI', 'SE'];

$countries = [
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
            'destination_constraints' => [
                'military' => [
                    'city_codes' => ['APO', 'FPO', 'DPO'],
                    'state_codes' => ['AA', 'AE', 'AP'],
                    'must_use_us_warehouse' => true,
                    'factory_allowed' => false,
                    'human_review_if_split_required' => true,
                ],
                'ak_hi' => [
                    'state_codes' => ['AK', 'HI'],
                    'strategy' => 'cost_compare_us_vs_factory',
                    'automatic' => false,
                    'factory_allowed' => true,
                    'human_review_required' => true,
                ],
            ],
        ],
    ],
];

foreach ($dePreferredCountries as $country) {
    $countries[$country] = [
        'automatic' => false,
        'strategy' => 'de_preferred',
        'allow_cross_region_fallback' => false,
        'warehouses' => [['code' => 'DE', 'priority' => 1, 'role' => 'eu_regional']],
    ];
}

foreach ($deCostGateCountries as $country) {
    $countries[$country] = [
        'automatic' => false,
        'strategy' => 'de_cost_gate',
        'allow_cross_region_fallback' => false,
        'warehouses' => [['code' => 'DE', 'priority' => 1, 'role' => 'eu_regional']],
        'rules' => [
            'cost_gate' => [
                'currency' => 'EUR',
                'warehouse' => 'DE',
                'carrier' => 'DHL',
                'per_order_handling_cost_eur' => 1.00,
                'required_evidence' => [
                    'china_historical_shipping_cost_eur',
                    'china_historical_cost_source',
                    'de_dhl_shipping_cost_eur',
                    'de_dhl_quote_source',
                ],
                'comparison' => 'de_dhl_plus_handling_strictly_lower_than_china_historical',
                'missing_evidence_behavior' => 'blocked',
            ],
        ],
    ];
}

foreach ($deBlockedCountries as $country) {
    $countries[$country] = [
        'automatic' => false,
        'strategy' => 'blocked',
        'reason' => '当前德国 DHL 成本偏高，默认不使用德国仓；如未来有新的真实成本证据，再由 Owner 调整 Policy。',
        'warehouses' => [],
    ];
}

return [
    'version' => '2026-09-07-v5',

    'global' => [
        'require_active_warehouse' => true,
        'require_full_snapshot' => true,
        'require_fulfillment_ready' => true,
        'allow_split_shipment' => true,
        'automatic_split_shipment' => false,
        'split_execution_mode' => 'human_review_only',
        'compare_split_vs_unsplit_cost' => true,
        'selection_order' => ['local_country_warehouse', 'approved_nearby_warehouse', 'china_factory'],
        'allow_cross_region_fallback' => false,
    ],

    'aliases' => [
        'US-NY' => ['US-NY', 'US-NJ', '美东纽约仓', '美东仓'],
        'US-LA' => ['US-LA', 'MorePlus', '美西洛杉矶仓', 'USA 美西仓'],
        'US-SJ' => ['US-SJ', 'SMGJ', '美西加州3仓', '世纪仓', '世迈仓', '世迈国际仓', 'SMGJ 世迈国际仓'],
        'CA' => ['CA', 'CA02', '加拿大仓库', '加拿大仓'],
        'DE' => ['DE', '德国仓', '天运德国', '天运仓', '德国仓/天运仓'],
    ],

    'regional_policies' => [
        'DE-EU' => [
            'warehouse' => 'DE',
            'carrier' => 'DHL',
            'per_order_handling_cost_eur' => 1.00,
            'storage_cost_in_routing' => false,
            'group_a_de_preferred' => ['DE', ...$dePreferredCountries],
            'group_b_cost_gate' => $deCostGateCountries,
            'group_c_default_china' => $deBlockedCountries,
        ],
    ],

    'factory_fallback' => [
        'enabled' => true,
        'warehouse_code' => 'FACTORY',
        'label' => '中国工厂',
        'inventory_authority_required' => false,
        'carrier' => 'SD经济挂号-带电',
        'rate_source' => [
            'file' => '仓库-物流-国内小包费用.xlsx',
            'sheet' => 'SD经济挂号-带电',
        ],
        'use_when' => 'overseas_options_unavailable_or_costlier',
        'automatic_execution' => false,
    ],

    'countries' => $countries,
];
