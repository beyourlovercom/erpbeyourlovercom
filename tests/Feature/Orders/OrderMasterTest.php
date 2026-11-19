<?php

namespace Tests\Feature\Orders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OrderMasterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_master_includes_all_kinds_direct_and_inherited_archives_and_admin_trash(): void
    {
        $admin = $this->userWith(['orders.view', 'system.permissions.manage']);
        $normal = $this->makeOrder($admin, 'normal');
        $kol = $this->makeOrder($admin, 'kol', 'kol_fulfillment');
        $archived = $this->makeOrder($admin, 'archived');
        $archived->forceFill(['erp_order_id' => '007-1110-c42262', 'operational_archived_at' => now()])->save();
        $child = $this->makeOrder($admin, 'derived', 'fulfillment_derived');
        $child->forceFill(['fulfillment_parent_id' => $archived->id])->save();
        $trash = $this->makeOrder($admin, 'trashed');
        $trash->delete();

        $this->actingAs($admin)->get('/orders')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Orders/Master')
                ->where('counts.all', 5)->where('counts.operational', 2)
                ->where('counts.normal', 2)->where('counts.trash', 1)
                ->where('canSeeTrash', true)->has('orders.data', 5));
        $this->actingAs($admin)->get('/orders?view=operational')->assertOk()
            ->assertInertia(fn ($page) => $page->where('orders.total', 2));
        $this->actingAs($admin)->get('/orders?view=trash')->assertOk()
            ->assertInertia(fn ($page) => $page->where('orders.total', 1)
                ->where('orders.data.0.id', $trash->id)->where('orders.data.0.detail_url', '/orders/trash'));
        $this->actingAs($admin)->get('/orders?search=007-1110-c42262')->assertOk()
            ->assertInertia(fn ($page) => $page->where('orders.total', 1)
                ->where('orders.data.0.id', $archived->id));
        $this->actingAs($admin)->get('/orders?kind=kol_fulfillment')->assertOk()
            ->assertInertia(fn ($page) => $page->where('orders.total', 1)->where('orders.data.0.id', $kol->id));
        $this->assertDatabaseHas('orders', ['id' => $normal->id, 'deleted_at' => null]);
    }

    public function test_employee_does_not_discover_trash_and_kol_specialist_cannot_discover_others_orders(): void
    {
        $admin = $this->userWith(['orders.view', 'system.permissions.manage']);
        $employee = $this->userWith(['orders.view']);
        $specialist = $this->userWith(['orders.view', 'kol.view']);
        $normal = $this->makeOrder($admin, 'customer');
        $hiddenTrash = $this->makeOrder($admin, 'deleted');
        $hiddenTrash->delete();
        $otherKol = $this->makeOrder($admin, 'other-kol', 'kol_fulfillment');
        $ownedKol = $this->makeOrder($specialist, 'owned-kol', 'kol_fulfillment');

        $this->actingAs($employee)->get('/orders')->assertOk()
            ->assertInertia(fn ($page) => $page->where('counts.all', 3)
                ->where('counts.trash', 0)->where('canSeeTrash', false));
        $this->actingAs($employee)->get('/orders?view=trash')->assertOk()
            ->assertInertia(fn ($page) => $page->where('orders.total', 0));
        $this->actingAs($specialist)->get('/orders')->assertOk()
            ->assertInertia(fn ($page) => $page->where('orders.total', 1)
                ->where('orders.data.0.id', $ownedKol->id));
        $this->actingAs($specialist)->get('/orders?search='.$otherKol->erp_order_id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('orders.total', 0));
        $this->actingAs($specialist)->get('/orders?search='.$normal->erp_order_id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('orders.total', 0));
    }

    public function test_master_server_search_reaches_older_pages_and_validates_all_filters(): void
    {
        $reader = $this->userWith(['orders.view']);
        $target = $this->makeOrder($reader, 'target');
        $target->forceFill(['external_order_number' => '42262-TL278A-Green'])->save();
        for ($index = 0; $index < 55; $index++) {
            $this->makeOrder($reader, 'newer-'.$index);
        }

        $this->actingAs($reader)->get('/orders')->assertOk()
            ->assertInertia(fn ($page) => $page->where('orders.total', 56)->has('orders.data', 50));
        $this->actingAs($reader)->get('/orders?search=42262-TL278A-Green')->assertOk()
            ->assertInertia(fn ($page) => $page->where('orders.total', 1)
                ->where('orders.data.0.id', $target->id));
        $this->actingAs($reader)->get('/orders?grid_external_number=TL278A')->assertOk()
            ->assertInertia(fn ($page) => $page->where('orders.total', 1));
        $this->actingAs($reader)->getJson('/orders?sort=orders.id')->assertUnprocessable();
        $this->actingAs($reader)->getJson('/orders?per_page=1000')->assertUnprocessable();
    }

    public function test_master_projects_optional_full_material_name_by_order_sku(): void
    {
        $viewer = $this->userWith(['orders.view']);
        $order = $this->makeOrder($viewer, 'material-name');
        Product::create([
            'source_type' => 'manual', 'product_type' => 'simple',
            'sku' => 'BV339A', 'name' => 'Massager',
            'material_full_name' => 'BV339A胸部按摩仪成品紫色含润滑油版',
        ]);
        $order->items()->create([
            'sku' => 'BV339A', 'product_summary' => 'Massager',
            'quantity' => 1, 'unit_price' => '1.00', 'line_revenue' => '1.00',
        ]);
        $this->actingAs($viewer)->get('/orders?search=BV339A')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('orders.total', 1)
                ->where('orders.data.0.material_full_name',
                    'BV339A：BV339A胸部按摩仪成品紫色含润滑油版'));
    }

    public function test_old_master_url_redirects_and_preserves_filter_query(): void
    {
        $user = $this->userWith(['orders.view']);
        $this->actingAs($user)->get('/orders/master?search=EXAMPLE&view=normal')
            ->assertRedirect('/orders?search=EXAMPLE&view=normal');
    }

    public function test_import_entry_respects_existing_order_creation_permissions(): void
    {
        $viewer = $this->userWith(['orders.view']);
        $this->actingAs($viewer)->get('/orders')->assertOk()->assertInertia(
            fn ($page) => $page->where('canImportOmsOrders', false)->where('canConfirmOmsOrders', false)
        );
        $creator = $this->userWith(['orders.view', 'orders.create']);
        $this->actingAs($creator)->get('/orders')->assertOk()->assertInertia(
            fn ($page) => $page->where('canImportOmsOrders', true)->where('canConfirmOmsOrders', true)
        );
    }

    private function userWith(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'master_'.uniqid(), 'label' => 'Master Grid UAT']);
        foreach ($keys as $key) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key]));
        }
        $user->roles()->attach($role);

        return $user;
    }

    private function makeOrder(User $owner, string $suffix, string $kind = 'customer'): Order
    {
        return Order::create([
            'internal_order_number' => 'MASTER-'.strtoupper($suffix),
            'erp_order_id' => '007-1116-'.strtoupper($suffix),
            'source_type' => 'manual',
            'order_kind' => $kind,
            'external_order_number' => 'MASTER-'.strtoupper($suffix),
            'order_date' => '2026-11-16',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
            'owner_id' => $owner->id,
        ]);
    }
}
