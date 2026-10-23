<?php

namespace App\Modules\Logistics\Experience\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * One-time warehouse integrations live under /settings/warehouses.
 * No provider secrets or warehouse assignments are exposed by the index.
 */
final class WarehouseSettingsController
{
    public function index(): Response
    {
        return Inertia::render('Settings/Warehouses/Index', [
            'integrations' => [
                [
                    'id' => 'xlwms',
                    'label' => '领星 OMS · 世迈仓 OpenAPI',
                    'url' => '/settings/warehouses/xlwms',
                    'capability' => 'read_only_probe',
                    'configured' => false,
                    'auto_dispatch_enabled' => false,
                ],
            ],
        ]);
    }
}
