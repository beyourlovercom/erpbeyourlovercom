<?php

namespace Tests\Feature\Logistics;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class Erp2ReadOnlyProjectionTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/erp2/v1/logistics/domestic/pending';
    private const ALLOCATION_URL = '/api/erp2/v1/logistics/pending-allocation';

    private Warehouse $factory;

    protected function setUp(): void
    {
        parent::setUp();
        config(['erp2_readonly.enabled' => true]);
        $this->factory = Warehouse::query()->where('code', 'FACTORY')->first()
            ?? Warehouse::query()->create([
                'slug' => 'factory', 'code' => 'FACTORY', 'name' => 'Synthetic factory', 'is_active' => true,
            ]);
    }

    public function test_logistics_view_user_receives_canonical_domestic_pending_projection(): void
    {
        $user = $this->userWith('logistics.view');
        $order = $this->pendingOrder('SYNTHETIC-READONLY-1');
        $order->items()->create([
            'product_summary' => 'Synthetic read-only item',
            'sku' => 'SOURCE-SKU', 'resolved_fulfillment_sku' => 'RESOLVED-SKU',
            'final_sku' => null, 'quantity' => 2, 'unit_price' => '0.00', 'line_revenue' => '0.00',
        ]);
        $order->operationalMarkers()->create(['marker' => 'domestic_reviewed', 'marked_at' => now()]);
        $this->pendingOrder('SYNTHETIC-READONLY-2');
        $stockout = $this->pendingOrder('SYNTHETIC-READONLY-3');
        $stockout->operationalMarkers()->create(['marker' => 'domestic_stockout', 'marked_at' => now()]);
        $ordersBefore = Order::query()->count();
        $markersBefore = DB::table('order_operational_markers')->count();
        $this->actingAs($user)->getJson(self::URL.'?per_page=20&search=READONLY')
            ->assertOk()->assertHeader('Content-Type', 'application/json')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('data.0.order_number', 'SYNTHETIC-READONLY-2')
            ->assertJsonPath('data.1.items.0.sku', 'SOURCE-SKU')
            ->assertJsonPath('data.1.items.0.resolved_sku', 'RESOLVED-SKU')
            ->assertJsonPath('data.1.items.0.final_sku', null);
        $this->assertSame($ordersBefore, Order::query()->count());
        $this->assertSame($markersBefore, DB::table('order_operational_markers')->count());
    }

    public function test_allocation_and_domestic_pending_queues_do_not_cross_leak(): void
    {
        $user = $this->userWith('logistics.view');
        $unallocated = $this->unallocatedOrder('CROSS-UNALLOCATED');
        $factoryReady = $this->pendingOrder('CROSS-FACTORY-READY');

        $this->actingAs($user)->getJson(self::ALLOCATION_URL.'?search=CROSS-UNALLOCATED')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.order_number', 'CROSS-UNALLOCATED');
        $this->getJson(self::URL.'?search=CROSS-UNALLOCATED')
            ->assertOk()->assertJsonPath('meta.total', 0);

        $this->getJson(self::URL.'?search=CROSS-FACTORY-READY')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.order_number', 'CROSS-FACTORY-READY');
        $this->getJson(self::ALLOCATION_URL.'?search=CROSS-FACTORY-READY')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_unauthenticated_and_forbidden_users_cannot_read_projection(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->actingAs($this->userWith('kol.view'))->get(self::URL)->assertForbidden();
    }

    public function test_flag_and_environment_are_hard_gates(): void
    {
        $user = $this->userWith('logistics.view');
        config(['erp2_readonly.enabled' => false]);
        $this->actingAs($user)->get(self::URL)->assertNotFound();
        config(['erp2_readonly.enabled' => true]);
        $this->app['env'] = 'production';
        $this->get(self::URL)->assertNotFound();
    }

    public function test_query_allowlists_and_read_only_verb_contract_are_enforced(): void
    {
        $this->actingAs($this->userWith('logistics.view'));
        $this->get(self::URL.'?sort=orders.secret')->assertStatus(422);
        $this->get(self::URL.'?view=shipped')->assertStatus(422);
        $this->get(self::URL.'?warehouse=not-a-warehouse')->assertStatus(422);
        $this->get(self::URL.'?page=0')->assertStatus(422);
        $this->get(self::URL.'?unexpected=1')->assertStatus(422);
        $this->post(self::URL)->assertStatus(405);
    }

    private function userWith(string $permission): User
    {
        $role = Role::query()->create(['key' => 'erp2-readonly-'.uniqid(), 'label' => 'ERP2 Readonly']);
        $model = Permission::query()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        $role->permissions()->attach($model);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    private function pendingOrder(string $number): Order
    {
        $order = Order::query()->create([
            'internal_order_number' => $number,
            'erp_order_id' => $number,
            'source_type' => 'manual',
            'order_kind' => 'customer',
            'external_order_number' => $number,
            'order_date' => '2026-11-12',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
            'routing_warehouse_code' => 'FACTORY',
        ]);
        $order->shipment()->create([
            'warehouse_id' => $this->factory->id, 'shipment_status' => 'pending',
            'carrier' => 'Synthetic', 'tracking_number' => null,
        ]);

        return $order;
    }

    private function unallocatedOrder(string $number): Order
    {
        return Order::query()->create([
            'internal_order_number' => $number,
            'erp_order_id' => $number,
            'source_type' => 'manual',
            'order_kind' => 'customer',
            'external_order_number' => $number,
            'order_date' => '2026-11-12',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
    }
}
