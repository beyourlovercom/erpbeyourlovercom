<?php

namespace Tests\Feature\Dashboard;

use App\Models\Role;
use App\Models\User;
use App\Modules\Dashboard\Application\DashboardDataContract;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DashboardDataContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_contract_returns_current_metrics_and_utc_shipped_day_counts(): void
    {
        $user = $this->userWithDashboardView();
        $dayStart = now('UTC')->startOfDay();

        $todayOrder = Order::create([
            'internal_order_number' => 'DASH-001',
            'source_type' => 'website',
            'order_date' => $dayStart->toDateString(),
            'order_status' => 'shipped',
        ]);
        $todayOrder->shipment()->create([
            'shipment_status' => 'shipped',
            'shipped_at' => $dayStart->copy()->addHours(9),
        ]);

        $outsideWindowOrder = Order::create([
            'internal_order_number' => 'DASH-002',
            'source_type' => 'kol',
            'order_date' => $dayStart->toDateString(),
            'order_status' => 'shipped',
        ]);
        $outsideWindowOrder->shipment()->create([
            'shipment_status' => 'shipped',
            'shipped_at' => $dayStart->copy()->subSecond(),
        ]);

        $contract = app(DashboardDataContract::class)->current($user);

        $this->assertSame('UTC', $contract['time']['timezone']);
        $this->assertSame('inclusive_start_exclusive_end', $contract['time']['day_window']);
        $this->assertSame(1, $contract['metrics']['shipped_orders_today']);
        $this->assertSame(['website' => 1, 'kol' => 1, 'manual_other' => 0], $contract['order_source_counts']);
        $this->assertSame('not_available', $contract['coverage']['pending_shipment_history']);
    }

    public function test_dashboard_route_does_not_expose_data_without_dashboard_permission(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)->get('/dashboard')->assertForbidden();
    }

    private function userWithDashboardView(): User
    {
        $role = Role::create(['key' => 'dashboard-contract', 'label' => 'Dashboard Contract']);
        $role->permissions()->create(['key' => 'dashboard.view', 'label' => 'Dashboard View']);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
