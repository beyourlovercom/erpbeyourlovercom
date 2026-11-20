<?php

namespace Tests\Feature\Logistics;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class Erp2PendingAllocationProjectionTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/erp2/v1/logistics/pending-allocation';

    protected function setUp(): void
    {
        parent::setUp();
        config(['erp2_readonly.enabled' => true]);
    }

    public function test_logistics_view_user_receives_canonical_pending_allocation_projection(): void
    {
        $user = $this->userWith('logistics.view');
        $first = $this->unallocatedOrder('SYNTHETIC-ALLOCATION-1');
        $first->items()->create([
            'product_summary' => 'Synthetic allocation item', 'sku' => 'SOURCE-SKU',
            'resolved_fulfillment_sku' => 'RESOLVED-SKU', 'final_sku' => null,
            'quantity' => 2, 'unit_price' => '0.00', 'line_revenue' => '0.00',
        ]);
        $this->unallocatedOrder('SYNTHETIC-ALLOCATION-2');

        $this->actingAs($user)->getJson(self::URL.'?per_page=20&search=SYNTHETIC-ALLOCATION')
            ->assertOk()->assertHeader('Content-Type', 'application/json')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('data.1.order_number', 'SYNTHETIC-ALLOCATION-1')
            ->assertJsonPath('data.1.items.0.sku', 'SOURCE-SKU')
            ->assertJsonPath('data.1.items.0.resolved_sku', 'RESOLVED-SKU')
            ->assertJsonPath('data.1.items.0.final_sku', null);
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
        $this->get(self::URL.'?unexpected=1')->assertStatus(422);
        $this->post(self::URL)->assertStatus(405);
    }

    private function userWith(string $permission): User
    {
        $role = Role::query()->create(['key' => 'erp2-allocation-'.uniqid(), 'label' => 'ERP2 Allocation Reader']);
        $model = Permission::query()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        $role->permissions()->attach($model);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    private function unallocatedOrder(string $number): Order
    {
        return Order::query()->create([
            'internal_order_number' => $number, 'erp_order_id' => $number,
            'source_type' => 'manual', 'order_kind' => 'customer',
            'external_order_number' => $number, 'order_date' => '2026-11-12',
            'order_status' => 'pending_shipment', 'currency' => 'USD',
        ]);
    }
}
