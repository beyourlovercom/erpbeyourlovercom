<?php

namespace Tests\Feature\Logistics;

use App\Modules\Logistics\Experience\Middleware\RejectXlwmsAutomaticCreate;
use App\Modules\Logistics\Experience\Middleware\RequireLogisticsExecution;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Frozen manual import V1: do not reintroduce M2's automatic shipment apply. */
final class XlwmsManualM2ConsolidationContractTest extends TestCase
{
    public function test_manual_excel_and_staff_confirm_routes_remain_authorized(): void
    {
        $names = [
            'logistics.xlwms.manual.export' => 'GET',
            'logistics.oversea.us-3sm.manual-ship' => 'POST',
            'logistics.xlwms.order.reship-manual-draft' => 'GET',
            'logistics.xlwms.order.reship-manual-bind' => 'POST',
        ];
        foreach ($names as $name => $method) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Manual workflow route missing: {$name}");
            $this->assertContains($method, $route->methods());
            $this->assertContains(RequireLogisticsExecution::class, $route->gatherMiddleware());
            $this->assertContains('can:logistics.operate', $route->gatherMiddleware());
            $this->assertNotContains(RejectXlwmsAutomaticCreate::class, $route->gatherMiddleware());
        }
    }

    public function test_previous_oms_create_routes_stay_blocked_in_manual_mode(): void
    {
        foreach ([
            'logistics.xlwms.warehouse.sync',
            'logistics.xlwms.order.send',
            'logistics.xlwms.order.web-draft',
            'logistics.xlwms.order.reship-create',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Old CREATE route missing expected blocker: {$name}");
            $this->assertContains('POST', $route->methods());
            $this->assertContains(RejectXlwmsAutomaticCreate::class, $route->gatherMiddleware());
        }
    }

    public function test_readonly_oms_status_and_existing_manual_tracking_flow_remain(): void
    {
        foreach ([
            'logistics.xlwms.warehouse.refresh-statuses',
            'logistics.xlwms.order.status',
            'logistics.xlwms.order.reship-status',
            'logistics.tracking-outbound.selected',
        ] as $name) {
            $this->assertNotNull(Route::getRoutes()->getByName($name), "Existing status/sync route missing: {$name}");
        }

        // #562 included a new OMS-driven ERP shipment APPLY which is explicitly
        // superseded by the Owner-approved human ERP confirmation in #581.
        $this->assertNull(Route::getRoutes()->getByName('logistics.xlwms.m2.apply'));
        $this->assertNull(Route::getRoutes()->getByName('logistics.xlwms.m2.preflight'));
    }
}
