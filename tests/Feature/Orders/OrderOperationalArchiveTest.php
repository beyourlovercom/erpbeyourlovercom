<?php

namespace Tests\Feature\Orders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Logistics\Application\LogisticsStatusSummary;
use App\Modules\Logistics\Application\ShipmentService;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Orders\Application\OrderProgressQuery;
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

    public function test_batch_archive_reuses_single_order_lock_audit_and_partial_failures(): void
    {
        $staff = $this->userWith(['orders.view', 'orders.archive']);
        $first = $this->makeOrder($staff, 'batch-a');
        $second = $this->makeOrder($staff, 'batch-b');

        $this->actingAs($staff)->postJson('/orders/archive/batch', [
            'order_ids' => [$first->id, $second->id, 987654321],
        ])->assertOk()->assertJsonPath('success_count', 2)
            ->assertJsonPath('failed_count', 1)
            ->assertJsonPath('results.0.ok', true)
            ->assertJsonPath('results.1.ok', true)
            ->assertJsonPath('results.2.ok', false);
        $this->assertDatabaseHas('orders', ['id' => $first->id, 'operational_archived_by' => $staff->id]);
        $this->assertDatabaseHas('orders', ['id' => $second->id, 'operational_archived_by' => $staff->id]);
        $this->actingAs($staff)->postJson('/orders/archive/batch', [
            'order_ids' => [$first->id, $second->id],
        ])->assertOk()->assertJsonPath('success_count', 0)->assertJsonPath('failed_count', 2);
        $this->actingAs($staff)->get('/orders/archive')->assertOk()
            ->assertInertia(fn ($page) => $page->has('orders.data', 2));

        $restricted = $this->userWith(['orders.view']);
        $this->actingAs($restricted)->postJson('/orders/archive/batch', [
            'order_ids' => [$first->id],
        ])->assertForbidden();
        $this->actingAs($staff)->postJson('/orders/archive/batch', [
            'order_ids' => [$first->id, $first->id],
        ])->assertUnprocessable();
    }

    public function test_archive_search_finds_order_beyond_first_page_without_leaking_other_matches(): void
    {
        $staff = $this->userWith(['orders.view', 'orders.archive']);
        $target = $this->makeOrder($staff, 'needle');
        $target->forceFill(['erp_order_id' => '007-1110-c42262', 'operational_archived_at' => now()->subDays(5)])->save();
        for ($index = 0; $index < 55; $index++) {
            $order = $this->makeOrder($staff, 'filler-'.$index);
            $order->forceFill(['operational_archived_at' => now()->subMinutes($index)])->save();
        }

        $this->actingAs($staff)->get('/orders/archive')->assertOk()
            ->assertInertia(fn ($page) => $page->has('orders.data', 50)->where('orders.total', 56));
        $this->actingAs($staff)->get('/orders/archive?search=007-1110-c42262')->assertOk()
            ->assertInertia(fn ($page) => $page->where('orders.total', 1)->has('orders.data', 1)
                ->where('orders.data.0.id', $target->id)->where('filters.search', '007-1110-c42262'));
        $this->actingAs($staff)->get('/orders/archive?search=dbid:'.$target->id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('orders.total', 1)->where('orders.data.0.id', $target->id));
    }

    public function test_archive_separates_order_from_operational_queues_without_destroying_evidence_and_admin_restores(): void
    {
        $staff = $this->userWith(['orders.view', 'orders.archive']);
        $admin = $this->userWith(['orders.view', 'orders.archive', 'system.permissions.manage']);
        $order = $this->makeOrder($staff, 'main');
        $order->externalReferences()->create(['provider' => 'test', 'reference' => 'OP-ARCH-MAIN']);
        $order->shipment()->create(['shipment_status' => 'pending']);

        $this->actingAs($staff)->get('/customers/orders/'.$order->id)
            ->assertOk()->assertInertia(fn ($page) => $page->where('canArchiveOrder', true));
        $this->actingAs($staff)->patch('/customers/orders/'.$order->id, [])->assertForbidden();
        $this->actingAs($staff)->delete('/orders/'.$order->id.'/trash')->assertForbidden();
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
        $this->actingAs($staff)->patch('/customers/orders/'.$order->id, [])->assertForbidden();
        $this->actingAs($staff)->delete('/orders/'.$order->id.'/trash')->assertForbidden();

        $this->actingAs($admin)->post('/orders/archive/'.$order->id.'/restore')->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'operational_archived_at' => null, 'operational_archived_by' => null]);
        $this->assertSame(1, Order::query()->eligibleForOperations()->whereKey($order->id)->count());
    }

    public function test_derived_fulfillment_can_be_archived_independently_and_restored_without_affecting_siblings(): void
    {
        $staff = $this->userWith(['orders.view', 'orders.archive']);
        $admin = $this->userWith(['orders.view', 'orders.archive', 'system.permissions.manage']);
        $root = $this->makeOrder($staff, 'parent');
        $child = $this->makeOrder($staff, 'derived');
        $sibling = $this->makeOrder($staff, 'sibling');
        foreach ([$child, $sibling] as $derived) {
            $derived->forceFill(['order_kind' => 'fulfillment_derived', 'fulfillment_parent_id' => $root->id])->save();
        }

        $this->actingAs($staff)->get('/customers/orders/'.$child->id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('canArchiveOrder', true));
        $this->actingAs($staff)->post('/orders/'.$child->id.'/archive')->assertRedirect('/orders/archive');
        $this->assertDatabaseHas('orders', ['id' => $root->id, 'operational_archived_at' => null]);
        $this->assertDatabaseHas('orders', ['id' => $sibling->id, 'operational_archived_at' => null]);
        $this->assertSame([$root->id, $sibling->id], Order::query()->eligibleForOperations()
            ->whereIn('id', [$root->id, $child->id, $sibling->id])->orderBy('id')->pluck('id')->all());
        $this->actingAs($staff)->get('/orders/archive')->assertOk()
            ->assertInertia(fn ($page) => $page->has('orders.data', 1)
                ->where('orders.data.0.id', $child->id)
                ->where('orders.data.0.direct_archive', true)
                ->where('orders.data.0.fulfillment_parent_id', $root->id));
        $this->actingAs($admin)->post('/orders/archive/'.$child->id.'/restore')->assertRedirect();
        $this->assertSame(3, Order::query()->eligibleForOperations()->whereIn('id', [$root->id, $child->id, $sibling->id])->count());

        $this->actingAs($staff)->post('/orders/'.$child->id.'/archive')->assertRedirect('/orders/archive');
        $this->actingAs($staff)->post('/orders/'.$root->id.'/archive')->assertRedirect('/orders/archive');
        $this->assertSame(0, Order::query()->eligibleForOperations()->whereIn('id', [$root->id, $child->id, $sibling->id])->count());
        $this->actingAs($staff)->get('/orders/archive')->assertOk()
            ->assertInertia(fn ($page) => $page->has('orders.data', 3));
        $this->actingAs($staff)->get('/customers/orders/'.$sibling->id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('operationallyArchived', true)
                ->where('canArchiveOrder', true)
                ->where('directlyArchived', false));

        $this->actingAs($admin)->post('/orders/archive/'.$sibling->id.'/restore')->assertStatus(409);
        $this->actingAs($admin)->post('/orders/archive/'.$root->id.'/restore')->assertRedirect();
        $this->assertSame(2, Order::query()->eligibleForOperations()->whereIn('id', [$root->id, $child->id, $sibling->id])->count());
        $this->actingAs($staff)->get('/orders/archive')->assertOk()
            ->assertInertia(fn ($page) => $page->has('orders.data', 1)->where('orders.data.0.id', $child->id));
        $this->actingAs($admin)->post('/orders/archive/'.$child->id.'/restore')->assertRedirect();
        $this->assertSame(3, Order::query()->eligibleForOperations()->whereIn('id', [$root->id, $child->id, $sibling->id])->count());
    }

    public function test_archive_requires_archive_permission_and_enforces_kol_owner_boundary(): void
    {
        $reader = $this->userWith(['orders.view']);
        $order = $this->makeOrder($reader, 'readonly');
        $this->actingAs($reader)->post('/orders/'.$order->id.'/archive')->assertForbidden();
        $this->assertNull($order->fresh()->operational_archived_at);
        $editor = $this->userWith(['orders.view', 'orders.edit']);
        $this->actingAs($editor)->get('/customers/orders/'.$order->id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('canArchiveOrder', false));
        $this->actingAs($editor)->post('/orders/'.$order->id.'/archive')->assertForbidden();
        $this->assertNull($order->fresh()->operational_archived_at);

        $owner = $this->userWith(['orders.view', 'orders.archive', 'kol.view']);
        $other = $this->userWith(['orders.view', 'orders.archive', 'kol.view']);
        $kolOrder = $this->makeOrder($other, 'kol');
        $kolOrder->forceFill(['order_kind' => 'kol_fulfillment'])->save();
        $this->actingAs($owner)->post('/orders/'.$kolOrder->id.'/archive')->assertForbidden();
        $this->assertNull($kolOrder->fresh()->operational_archived_at);
    }

    public function test_archived_order_refuses_shipment_draft_even_when_called_directly(): void
    {
        $staff = $this->userWith(['orders.view', 'orders.archive']);
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

    public function test_editor_changes_archived_factory_order_into_real_pending_queue(): void
    {
        $archiver = $this->userWith(['orders.view', 'orders.archive']);
        $editor = $this->userWith(['orders.view', 'orders.edit', 'logistics.view']);
        $order = $this->makeOrder($archiver, 'reactivate-factory');
        $factory = Warehouse::query()->where('code', 'FACTORY')->firstOrFail();
        $order->shipment()->create(['warehouse_id' => $factory->id, 'shipment_status' => 'pending']);
        $this->actingAs($archiver)->post('/orders/'.$order->id.'/archive')->assertRedirect();

        $this->actingAs($editor)->get('/orders/archive')->assertOk()->assertInertia(fn ($page) =>
            $page->where('canChangeStatus', true)->where('orders.data.0.can_change_status', true)
                ->where('orders.data.0.workflow_status_projection.code', 'archived'));
        $this->actingAs($editor)->get('/customers/orders/'.$order->id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('canEditWorkflowStatus', true));
        $this->actingAs($editor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'sending', 'expected_workflow_revision' => 0,
        ])->assertOk()->assertJsonPath('target_url', '/logistics/china?view=pending&global_grid=1')
            ->assertJsonPath('workflow_status.code', 'sending');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id, 'operational_archived_at' => null, 'operational_archived_by' => null,
            'order_status' => 'pending_shipment', 'workflow_status' => 'sending',
        ]);
        $this->assertSame(1, app(OrderProgressQuery::class)->domesticReady(Order::query())->whereKey($order->id)->count());
        $this->assertDatabaseHas('audit_records', ['entity_id' => $order->id, 'action' => 'order.operational_archive_restored']);
    }

    public function test_archive_reactivation_requires_editor_and_rolls_back_on_invalid_transition(): void
    {
        $archiver = $this->userWith(['orders.view', 'orders.archive']);
        $viewer = $this->userWith(['orders.view']);
        $editor = $this->userWith(['orders.view', 'orders.edit']);
        $order = $this->makeOrder($archiver, 'reactivate-denied');
        $this->actingAs($archiver)->post('/orders/'.$order->id.'/archive')->assertRedirect();
        $payload = ['workflow_status' => 'sending', 'expected_workflow_revision' => 0];
        $this->actingAs($viewer)->patchJson('/orders/'.$order->id.'/workflow-status', $payload)->assertForbidden();
        $this->actingAs($editor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'sending', 'expected_workflow_revision' => 1,
        ])->assertStatus(409);
        $this->actingAs($editor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'shipped', 'expected_workflow_revision' => 0,
        ])->assertStatus(409);
        $this->assertNotNull($order->fresh()->operational_archived_at);
        $this->assertSame(0, (int) $order->fresh()->workflow_revision);
        $this->assertDatabaseMissing('audit_records', ['entity_id' => $order->id, 'action' => 'order.operational_archive_restored']);
    }

    public function test_shipped_archive_cannot_be_reexported_by_changing_status(): void
    {
        $archiver = $this->userWith(['orders.view', 'orders.archive']);
        $editor = $this->userWith(['orders.view', 'orders.edit']);
        $order = $this->makeOrder($archiver, 'reactivate-shipped');
        $factory = Warehouse::query()->where('code', 'FACTORY')->firstOrFail();
        $order->shipment()->create([
            'warehouse_id' => $factory->id, 'shipment_status' => 'shipped',
            'shipped_at' => now(), 'tracking_number' => 'ARCHIVED-TRACK-001',
        ]);
        $order->forceFill(['order_status' => 'shipped'])->save();
        $this->actingAs($archiver)->post('/orders/'.$order->id.'/archive')->assertRedirect();
        $this->actingAs($editor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'sending', 'expected_workflow_revision' => 0,
        ])->assertStatus(409);
        $this->assertNotNull($order->fresh()->operational_archived_at);
        $this->assertSame('shipped', $order->fresh()->order_status->value);
        $this->assertSame('ARCHIVED-TRACK-001', $order->fresh()->shipment->tracking_number);

        // A stale canonical pending status must not disguise real dispatch evidence.
        $order->forceFill(['order_status' => 'pending_shipment'])->save();
        $this->actingAs($editor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'sending', 'expected_workflow_revision' => 0,
        ])->assertStatus(409);
        $this->assertNotNull($order->fresh()->operational_archived_at);
        $this->assertSame('ARCHIVED-TRACK-001', $order->fresh()->shipment->tracking_number);
    }

    public function test_child_cannot_be_reactivated_while_parent_stays_archived(): void
    {
        $archiver = $this->userWith(['orders.view', 'orders.archive']);
        $editor = $this->userWith(['orders.view', 'orders.edit']);
        $parent = $this->makeOrder($archiver, 'reactivate-parent');
        $child = $this->makeOrder($archiver, 'reactivate-child');
        $child->forceFill(['order_kind' => 'fulfillment_derived', 'fulfillment_parent_id' => $parent->id])->save();
        $this->actingAs($archiver)->post('/orders/'.$parent->id.'/archive')->assertRedirect();
        $this->actingAs($archiver)->post('/orders/'.$child->id.'/archive')->assertRedirect();
        $this->actingAs($editor)->patchJson('/orders/'.$child->id.'/workflow-status', [
            'workflow_status' => 'sending', 'expected_workflow_revision' => 0,
        ])->assertStatus(409);
        $this->assertNotNull($child->fresh()->operational_archived_at);
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
