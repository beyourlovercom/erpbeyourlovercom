<?php

return [
    'canonical_import_enabled' => filter_var(env('KOL_CANONICAL_IMPORT_ENABLED', false), FILTER_VALIDATE_BOOL),
    'affiliate_binding_activation_enabled' => filter_var(env('KOL_AFFILIATE_BINDING_ACTIVATION_ENABLED', false), FILTER_VALIDATE_BOOL),
];
