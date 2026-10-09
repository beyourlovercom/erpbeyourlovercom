<?php

namespace Tests\Feature\Orders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class KolSkuLogisticsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_emma_can_select_sku_and_read_own_logistics_without_cross_order_access(): void
    {
        $emma = $this->userWith(['kol.view', 'orders.view', 'orders.create', 'products.view']);
        $other = $this->userWith(['kol.view', 'orders.view']);
        Product::create([
            'source_type' => 'manual',
            'sku' => 'EMMA-SKU-001',
            'name' => 'Test catalog item',
            'product_type' => 'simple',
        ]);
        $own = $this->order($emma, 'kol_fulfillment', 'emma');
        $foreign = $this->order($other, 'kol_fulfillment', 'foreign');
        $customer = $this->order($emma, 'customer', 'customer');

        $this->actingAs($emma)->getJson('/products/sku-search?q=EMMA-SKU-001')
            ->assertOk()->assertJsonFragment(['sku' => 'EMMA-SKU-001']);
        $this->actingAs($emma)->get('/products/list')->assertOk();
        $this->actingAs($emma)->get('/kol/logistics')->assertOk()
            ->assertInertia(fn ($page) => $page->where('isManager', false)
                ->where('orders.total', 1)->where('orders.data.0.id', $own->id));
        $this->actingAs($emma)->get('/kol/logistics?search=dbid:'.$foreign->id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('orders.total', 0));
        $this->actingAs($emma)->get('/orders/'.$foreign->id)->assertForbidden();
        $this->actingAs($emma)->get('/orders/'.$customer->id)->assertForbidden();
        $this->actingAs($emma)->get('/logistics/wait-stock')->assertForbidden();
    }

    public function test_kol_manager_with_product_read_sees_kol_team_but_not_customers(): void
    {
        $emma = $this->userWith(['kol.view', 'orders.view', 'products.view']);
        $manager = $this->userWith(['kol.view', 'kol.views.manage', 'orders.view', 'products.view']);
        $this->order($emma, 'kol_fulfillment', 'emma-manager-case');
        $this->order($manager, 'kol_fulfillment', 'manager-case');
        $this->order($manager, 'customer', 'customer-manager-case');

        $this->actingAs($manager)->get('/kol/logistics')->assertOk()
            ->assertInertia(fn ($page) => $page->where('isManager', true)
                ->where('orders.total', 2));
        $this->actingAs($manager)->get('/orders')->assertOk()
            ->assertInertia(fn ($page) => $page->where('orders.total', 2));
    }

    private function userWith(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'kol_combined_uat_'.uniqid(), 'label' => 'KOL combined UAT']);
        foreach ($keys as $key) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key]));
        }
        $user->roles()->attach($role);

        return $user;
    }

    private function order(User $owner, string $kind, string $suffix): Order
    {
        return Order::create([
            'internal_order_number' => 'COMBO-'.strtoupper($suffix),
            'erp_order_id' => '007erp-1008-k'.random_int(100000, 999999),
            'source_type' => 'manual',
            'order_kind' => $kind,
            'external_order_number' => 'KOL-COMBINED-'.$suffix,
            'order_date' => '2026-10-08',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
            'owner_id' => $owner->id,
        ]);
    }
}
