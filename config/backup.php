<?php

return [
    'format' => [
        'name' => 'backup-format-v1',
        'version' => 1,
        'asset_suffix' => '.tar.zst.enc',
    ],
    'storage' => [
        'included_roots' => [
            'storage/app/private',
            'storage/app/public',
        ],
        'workspace' => 'storage/app/backups',
    ],
    'encryption' => [
        'algorithm' => 'AES-256-GCM',
        'key' => env('BACKUP_ENCRYPTION_KEY'),
        'key_id' => env('BACKUP_ENCRYPTION_KEY_ID'),
        'chunk_size' => 1024 * 1024,
    ],
    'github' => [
        'repository' => 'beyourlovercom/erp-backups',
        'visibility' => 'private',
        'token' => env('BACKUP_GITHUB_TOKEN'),
    ],
    'manifest' => [
        'version' => 1,
    ],
    'tooling' => [
        'mysqldump' => env('BACKUP_MYSQLDUMP_BINARY', 'mysqldump'),
        'tar' => env('BACKUP_TAR_BINARY', 'tar'),
        'zstd' => env('BACKUP_ZSTD_BINARY', 'zstd'),
    ],
];
