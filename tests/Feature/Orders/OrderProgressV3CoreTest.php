<?php

namespace Tests\Feature\Orders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderProgressQuery;
use App\Modules\Orders\Application\OrderWorkflowStatusProjection;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OrderProgressV3CoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_domestic_progress_matches_review_and_pending_views_and_kaven_dropdown(): void
    {
        $this->withoutVite();
        config()->set('inertia.testing.ensure_pages_exist', false);
        $kaven = $this->user(['orders.view', 'logistics.view', 'domestic.review']);
        $order = $this->order('FACTORY');

        self::assertSame('pending_review', $this->phase($order));
        self::assertSame(1, app(OrderProgressQuery::class)->domesticReview(Order::query())->whereKey($order->id)->count());
        $this->actingAs($kaven)->get('/customers/orders/'.$order->id)->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canEditOrder', false)
                ->where('canEditWorkflowStatus', true)
                ->where('order.workflow_status_projection.code', 'pending_review'));

        $this->actingAs($kaven)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'sending', 'expected_workflow_revision' => 0,
        ])->assertOk()->assertJsonPath('workflow_status.code', 'sending');
        $this->assertDatabaseMissing('order_operational_markers', [
            'order_id' => $order->id, 'marker' => 'domestic_reviewed',
        ]);
        self::assertSame('sending', $this->phase($order));
        self::assertSame(1, app(OrderProgressQuery::class)->domesticReady(Order::query())->whereKey($order->id)->count());
        self::assertSame(1, app(OrderProgressQuery::class)->readyForShipment(Order::query())->whereKey($order->id)->count());

        $this->actingAs($kaven)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'out_of_stock', 'expected_workflow_revision' => 1,
        ])->assertOk()->assertJsonPath('workflow_status.code', 'out_of_stock');
        self::assertSame(0, app(OrderProgressQuery::class)->domesticReady(Order::query())->whereKey($order->id)->count());
        self::assertSame(1, app(OrderProgressQuery::class)->domesticStockout(Order::query())->whereKey($order->id)->count());

        $this->actingAs($kaven)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'sending', 'expected_workflow_revision' => 2,
        ])->assertOk()->assertJsonPath('workflow_status.code', 'sending');
        self::assertSame(1, app(OrderProgressQuery::class)->domesticReady(Order::query())->whereKey($order->id)->count());
    }

    public function test_nonreviewer_cannot_forge_domestic_approval_or_shipped_phase(): void
    {
        $user = $this->user(['orders.view', 'orders.edit']);
        $order = $this->order('FACTORY');

        $this->actingAs($user)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'sending', 'expected_workflow_revision' => 0,
        ])->assertOk()->assertJsonPath('workflow_status.code', 'sending');
        $response = $this->actingAs($user)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'sent', 'expected_workflow_revision' => 1,
        ]);
        self::assertNotSame(200, $response->getStatusCode());
        self::assertSame('pending', $order->fresh()->shipment->shipment_status);
        $this->assertDatabaseMissing('order_operational_markers', [
            'order_id' => $order->id, 'marker' => 'domestic_reviewed',
        ]);
    }

    public function test_overseas_approval_drives_same_sending_state_and_list_scope(): void
    {
        $order = $this->order('US-LA');
        self::assertSame('pending_review', $this->phase($order));
        self::assertSame(0, app(OrderProgressQuery::class)->readyForShipment(Order::query())->whereKey($order->id)->count());

        $order->shipment->overseaReview()->create([
            'status' => 'approved',
            'submitted_warehouse_id' => $order->shipment->warehouse_id,
            'reviewed_warehouse_id' => $order->shipment->warehouse_id,
            'reviewed_at' => now(),
        ]);
        self::assertSame('sending', $this->phase($order));
        self::assertSame(1, app(OrderProgressQuery::class)->readyForShipment(Order::query())->whereKey($order->id)->count());
    }

    public function test_historical_shipped_without_domestic_approval_is_visible_as_conflict_not_exportable(): void
    {
        $this->withoutVite();
        config()->set('inertia.testing.ensure_pages_exist', false);
        $order = $this->order('FACTORY');
        $order->shipment->forceFill([
            'shipment_status' => 'shipped', 'shipped_at' => now(), 'tracking_number' => null,
        ])->save();
        self::assertSame('progress_conflict', $this->phase($order));
        self::assertSame(0, app(OrderProgressQuery::class)->domesticReady(Order::query())->whereKey($order->id)->count());
        self::assertSame(1, app(OrderProgressQuery::class)->applyPhase(Order::query(), 'progress_conflict')->whereKey($order->id)->count());

        $reviewer = $this->user(['logistics.view', 'orders.view', 'domestic.review']);
        $this->actingAs($reviewer)->get('/logistics/china?view=today-4px')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Logistics/Domestic/Index')
                ->where('rows.total', 1)
                ->where('rows.data.0.erp_order_id', $order->erp_order_id)
                ->where('rows.data.0.export_blockers.0', '该单已离开待发货队列，禁止重复导出')
                ->where('rows.data.0.review_status', 'conflict'));
        $this->actingAs($reviewer)->get('/logistics/china?view=exceptions')->assertOk()
            ->assertInertia(fn ($page) => $page->where('rows.total', 1));
    }

    public function test_vita_queue_and_preapproved_order_list_use_same_predicates(): void
    {
        $unallocated = $this->order();
        $review = $this->order('FACTORY');
        $ready = $this->order('FACTORY');
        $ready->operationalMarkers()->create(['marker' => 'domestic_reviewed', 'marked_at' => now()]);

        self::assertSame('pending_allocation', $this->phase($unallocated));
        self::assertSame('pending_review', $this->phase($review));
        self::assertSame('sending', $this->phase($ready));
        self::assertSame(1, app(OrderProgressQuery::class)->pendingAllocation(Order::query())->count());
        self::assertSame(1, app(OrderProgressQuery::class)->readyForShipment(Order::query())->count());
    }

    private function phase(Order $order): string
    {
        return app(OrderWorkflowStatusProjection::class)->for($order->fresh())['code'];
    }

    private function user(array $keys): User
    {
        $role = Role::create(['key' => 'v3-test-'.uniqid(), 'label' => 'V3 Test']);
        foreach ($keys as $key) {
            $role->permissions()->syncWithoutDetaching([
                Permission::firstOrCreate(['key' => $key], ['label' => $key])->id,
            ]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    private function order(?string $warehouseCode = null): Order
    {
        $order = Order::query()->create([
            'erp_order_id' => 'V3-'.uniqid(),
            'internal_order_number' => 'V3-INT-'.uniqid(),
            'external_order_number' => 'V3-EXT-'.uniqid(),
            'source_type' => 'manual',
            'order_kind' => OrderKind::Customer,
            'order_status' => 'pending_shipment',
            'order_date' => '2026-11-03',
            'currency' => 'USD',
        ]);
        if ($warehouseCode !== null) {
            $warehouse = Warehouse::query()->where('code', $warehouseCode)->firstOrFail();
            $order->shipment()->create([
                'warehouse_id' => $warehouse->id,
                'shipment_status' => 'pending',
                'carrier' => $warehouseCode === 'FACTORY' ? '4PX' : 'USPS',
            ]);
        }

        return $order->fresh('shipment');
    }
}
