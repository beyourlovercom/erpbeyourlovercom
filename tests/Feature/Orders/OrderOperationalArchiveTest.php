<?php

namespace Tests\Feature\Orders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Logistics\Application\LogisticsStatusSummary;
use App\Modules\Logistics\Application\ShipmentService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class OrderOperationalArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_archive_separates_order_from_operational_queues_without_destroying_evidence_and_admin_restores(): void
    {
        $staff = $this->userWith(['orders.view', 'orders.edit']);
        $admin = $this->userWith(['orders.view', 'orders.edit', 'system.permissions.manage']);
        $order = $this->makeOrder($staff, 'main');
        $order->externalReferences()->create(['provider' => 'test', 'reference' => 'OP-ARCH-MAIN']);
        $order->shipment()->create(['shipment_status' => 'pending']);

        $this->actingAs($staff)->get('/customers/orders/'.$order->id)
            ->assertOk()->assertInertia(fn ($page) => $page->where('canArchiveOrder', true));
        $this->actingAs($staff)->post('/orders/'.$order->id.'/archive')->assertRedirect('/orders/archive');
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'deleted_at' => null, 'archived_at' => null, 'operational_archived_by' => $staff->id]);
        $this->assertNotNull($order->fresh()->operational_archived_at);
        $this->assertDatabaseHas('order_external_references', ['order_id' => $order->id, 'reference' => 'OP-ARCH-MAIN']);
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'shipment_status' => 'pending']);
        $this->assertSame(0, Order::query()->eligibleForOperations()->whereKey($order->id)->count());
        $this->assertSame(0, app(LogisticsStatusSummary::class)->pendingAllocationQuery()->whereKey($order->id)->count());

        $this->actingAs($staff)->get('/orders')->assertOk()
            ->assertInertia(fn ($page) => $page->has('orders.data', 0));
        $this->actingAs($staff)->get('/orders/archive')->assertOk()
            ->assertInertia(fn ($page) => $page->has('orders.data', 1)
                ->where('orders.data.0.id', $order->id)
                ->where('orders.data.0.archived_by', $staff->name));
        $this->actingAs($staff)->get('/orders/trash')->assertForbidden();
        $this->actingAs($staff)->post('/orders/archive/'.$order->id.'/restore')->assertForbidden();
        $this->actingAs($staff)->post('/orders/'.$order->id.'/archive')->assertStatus(409);
        $this->actingAs($staff)->patch('/customers/orders/'.$order->id, [])->assertStatus(409);
        $this->actingAs($staff)->delete('/orders/'.$order->id.'/trash')->assertStatus(409);

        $this->actingAs($admin)->post('/orders/archive/'.$order->id.'/restore')->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'operational_archived_at' => null, 'operational_archived_by' => null]);
        $this->assertSame(1, Order::query()->eligibleForOperations()->whereKey($order->id)->count());
    }

    public function test_derived_fulfillment_inherits_parent_archive_and_must_not_be_archived_alone(): void
    {
        $staff = $this->userWith(['orders.view', 'orders.edit']);
        $root = $this->makeOrder($staff, 'parent');
        $child = $this->makeOrder($staff, 'derived');
        $child->forceFill(['order_kind' => 'fulfillment_derived', 'fulfillment_parent_id' => $root->id])->save();

        $this->actingAs($staff)->post('/orders/'.$child->id.'/archive')->assertStatus(409);
        $this->actingAs($staff)->post('/orders/'.$root->id.'/archive')->assertRedirect('/orders/archive');
        $this->assertTrue($child->fresh()->isOperationallyArchived());
        $this->assertSame(0, Order::query()->eligibleForOperations()->whereIn('id', [$root->id, $child->id])->count());
        $this->actingAs($staff)->get('/orders/archive')->assertOk()
            ->assertInertia(fn ($page) => $page->has('orders.data', 1));
        $this->assertDatabaseHas('orders', ['id' => $child->id, 'operational_archived_at' => null]);
    }

    public function test_archive_requires_edit_permission_and_enforces_kol_owner_boundary(): void
    {
        $reader = $this->userWith(['orders.view']);
        $order = $this->makeOrder($reader, 'readonly');
        $this->actingAs($reader)->post('/orders/'.$order->id.'/archive')->assertForbidden();
        $this->assertNull($order->fresh()->operational_archived_at);

        $owner = $this->userWith(['orders.view', 'orders.edit', 'kol.view']);
        $other = $this->userWith(['orders.view', 'orders.edit', 'kol.view']);
        $kolOrder = $this->makeOrder($other, 'kol');
        $kolOrder->forceFill(['order_kind' => 'kol_fulfillment'])->save();
        $this->actingAs($owner)->post('/orders/'.$kolOrder->id.'/archive')->assertForbidden();
        $this->assertNull($kolOrder->fresh()->operational_archived_at);
    }

    public function test_archived_order_refuses_shipment_draft_even_when_called_directly(): void
    {
        $staff = $this->userWith(['orders.view', 'orders.edit']);
        $order = $this->makeOrder($staff, 'fulfillment');
        $this->actingAs($staff)->post('/orders/'.$order->id.'/archive')->assertRedirect('/orders/archive');
        try {
            app(ShipmentService::class)->saveDraft($order->fresh(), [], $staff);
            $this->fail('Archived order should be rejected before saving a shipment draft.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertDatabaseMissing('shipments', ['order_id' => $order->id]);
    }

    private function userWith(array $permissionKeys): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'oparchive_'.uniqid(), 'label' => 'Operational archive UAT']);
        foreach ($permissionKeys as $key) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key]));
        }
        $user->roles()->attach($role);

        return $user;
    }

    private function makeOrder(User $owner, string $suffix): Order
    {
        return Order::create([
            'internal_order_number' => 'OPARCH-'.strtoupper($suffix),
            'erp_order_id' => '007erp-1104-k'.random_int(1000000, 9999999),
            'source_type' => 'manual',
            'order_kind' => 'customer',
            'external_order_number' => 'OP-ARCH-UAT-'.$suffix,
            'order_date' => '2026-11-04',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
            'owner_id' => $owner->id,
        ]);
    }
}
