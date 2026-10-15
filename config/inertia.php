<?php

// Inertia's package default uses lowercase resources/js/pages.
// The canonical Vue source tree in this ERP is uppercase resources/js/Pages.
// On Linux case-sensitive filesystems these are different paths.
return [
    'pages' => [
        'ensure_pages_exist' => false,
        'paths' => [resource_path('js/Pages')],
        'extensions' => ['js', 'jsx', 'svelte', 'ts', 'tsx', 'vue'],
    ],
];
