<?php

return [
    'canonical_import_enabled' => filter_var(env('KOL_CANONICAL_IMPORT_ENABLED', false), FILTER_VALIDATE_BOOL),
    'affiliate_binding_activation_enabled' => filter_var(env('KOL_AFFILIATE_BINDING_ACTIVATION_ENABLED', false), FILTER_VALIDATE_BOOL),
    'create_auto_provision' => [
        'native_welcome' => env('KOL_CREATE_NATIVE_WELCOME', false),
        'coupon_discount_type' => env('KOL_CREATE_COUPON_DISCOUNT_TYPE', 'percent'),
        'coupon_amount' => env('KOL_CREATE_COUPON_AMOUNT', '20'),
        'coupon_expiry' => env('KOL_CREATE_COUPON_EXPIRY'),
    ],
];
