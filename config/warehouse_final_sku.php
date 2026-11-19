<?php

return [
    // Only switch on after the product×warehouse physical-SKU mapping coverage
    // is verified. Strict mode never silently falls back to a source SKU.
    'require_verified_mapping' => (bool) env('WAREHOUSE_FINAL_SKU_REQUIRE_VERIFIED_MAPPING', false),
];
