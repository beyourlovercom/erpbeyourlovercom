<?php

return [
    'canonical_import_enabled' => filter_var(env('KOL_CANONICAL_IMPORT_ENABLED', false), FILTER_VALIDATE_BOOL),
];
