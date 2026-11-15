<?php

return [
    // Global SKU-level stock decisions: overseas provider authority stays
    // independent per SKU. Disabled until Dev UAT and controlled production
    // enablement; no warehouse data is rewritten by flipping this flag.
    'enabled' => filter_var(env('INVENTORY_SKU_LEVEL_GATE_ENABLED', false), FILTER_VALIDATE_BOOL),
];
