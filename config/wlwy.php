<?php

return [
    'ca_inventory_apply_enabled' => env('WLWY_CA_INVENTORY_APPLY_ENABLED', false),
    'ca_inventory_daily_enabled' => env('WLWY_CA_INVENTORY_DAILY_ENABLED', false),
    'ca_batch_read_enabled' => env('WLWY_CA_BATCH_READ_ENABLED', false),
    'ca_dispatch_enabled' => env('WLWY_CA_DISPATCH_ENABLED', false),
    'ca_dispatch_owner_approved' => env('WLWY_CA_DISPATCH_OWNER_APPROVED', false),
    'ca_duplicate_contract_verified' => env('WLWY_CA_DUPLICATE_CONTRACT_VERIFIED', false),
    'ca_batch_contract_verified' => env('WLWY_CA_BATCH_CONTRACT_VERIFIED', false),
    'ca_canary_order_id' => (int) env('WLWY_CA_CANARY_ORDER_ID', 0),
    'ca_operator_batch_enabled' => env('WLWY_CA_OPERATOR_BATCH_ENABLED', false),
    'ca_min_order_id' => (int) env('WLWY_CA_MIN_ORDER_ID', 0),
    'ca_tracking_enabled' => env('WLWY_CA_TRACKING_ENABLED', false),
    'ca_customer_code' => env('WLWY_CA_CUSTOMER_CODE'),
];
