<?php

namespace Tests\Feature;

use App\Modules\Shell\Application\GlobalGridPilot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

final class GlobalGridPilotReleaseGateTest extends TestCase
{
    public function test_pilot_requires_explicit_query_and_owner_id_in_production(): void
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

            $ownerId = 3;
            Auth::shouldReceive('id')->andReturnUsing(function () use (&$ownerId): int {
                return $ownerId;
            });
            $this->assertFalse(GlobalGridPilot::available());
            $this->assertFalse(GlobalGridPilot::enabled(Request::create('/products/list?global_grid=1')));

            $ownerId = 1;
            $this->assertTrue(GlobalGridPilot::available());
            $this->assertFalse(GlobalGridPilot::enabled(Request::create('/products/list')));
            $this->assertTrue(GlobalGridPilot::enabled(Request::create('/products/list?global_grid=1')));
            $this->assertTrue(GlobalGridPilot::enabled(Request::create('/purchase?global_grid=1')));
        } finally {
            app()->detectEnvironment(fn (): string => $priorEnvironment);
        }
    }
}
