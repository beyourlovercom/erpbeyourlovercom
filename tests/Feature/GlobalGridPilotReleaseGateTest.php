<?php

namespace Tests\Feature;

use App\Modules\Shell\Application\GlobalGridPilot;
use Illuminate\Http\Request;
use Tests\TestCase;

final class GlobalGridPilotReleaseGateTest extends TestCase
{
    public function test_pilot_requires_explicit_query_and_non_production_environment(): void
    {
        $this->assertTrue(GlobalGridPilot::available());
        $this->assertFalse(GlobalGridPilot::enabled(Request::create('/products/list')));
        $this->assertFalse(GlobalGridPilot::enabled(Request::create('/products/list?global_grid=0')));
        $this->assertTrue(GlobalGridPilot::enabled(Request::create('/products/list?global_grid=1')));

        $priorEnvironment = app()->environment();
        try {
            app()->detectEnvironment(fn (): string => 'production');
            $this->assertFalse(GlobalGridPilot::available());
            $this->assertFalse(GlobalGridPilot::enabled(Request::create('/purchase?global_grid=1')));
            $this->assertFalse(GlobalGridPilot::enabled(Request::create('/inventory/products?global_grid=1')));
        } finally {
            app()->detectEnvironment(fn (): string => $priorEnvironment);
        }
    }
}
