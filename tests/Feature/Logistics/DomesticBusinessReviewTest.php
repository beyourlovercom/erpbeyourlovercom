<?php

namespace Tests\Feature\Logistics;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\LogisticsStatusSummary;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderSourceType;
use App\Modules\Orders\Application\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DomesticBusinessReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_split_child_uses_parent_payment_date_in_real_inertia_response(): void
    {
        $this->withoutVite();
        config()->set('inertia.testing.ensure_pages_exist', false);
        $actor = $this->userWith(['logistics.view', 'domestic.review']);
        $root = $this->order();
        $root->financials()->create(['paid_at' => '2026-10-18 12:00:00']);
        $root->shipment()->delete();
        $child = Order::query()->create([
            'fulfillment_parent_id' => $root->id,
            'fulfillment_suffix' => 'A',
            'fulfillment_role' => 'original',
            'erp_order_id' => $root->erp_order_id.'A',
            'internal_order_number' => 'FULF-'.$root->id.'-A',
            'source_type' => OrderSourceType::ManualOther,
            'order_kind' => OrderKind::FulfillmentDerived,
            'order_date' => '2026-11-09',
            'order_status' => OrderStatus::PendingShipment,
            'currency' => 'USD',
        ]);
        $factory = Warehouse::query()->where('code', 'FACTORY')->firstOrFail();
        $child->shipment()->create(['warehouse_id' => $factory->id, 'shipment_status' => 'pending']);
        $child->items()->create(['product_summary' => 'Split test SKU', 'sku' => 'SPLIT-DATE', 'quantity' => 2, 'unit_price' => '0.00', 'line_revenue' => '0.00']);
        $this->actingAs($actor)->get('/logistics/china?view=review')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Logistics/Domestic/Index')
                ->where('rows.total', 1)
                ->where('rows.data.0.paid_date', '10-18')
                ->where('rows.data.0.paid_date_source', '母订单支付日期'));
        $this->assertNull($child->financials, 'split fulfillment never invents its own payment fact');
    }

    public function test_stockout_goes_to_own_tab_and_approval_returns_to_pending(): void
    {
        $this->withoutVite();
        config()->set('inertia.testing.ensure_pages_exist', false);
        $actor = $this->userWith(['logistics.view', 'domestic.review']);
        $order = $this->order();

        $this->actingAs($actor)->post('/logistics/china/orders/'.$order->id.'/stockout')->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'workflow_status' => 'out_of_stock']);
        $this->assertDatabaseMissing('order_operational_markers', [
            'order_id' => $order->id, 'marker' => 'domestic_reviewed',
        ]);
        $this->actingAs($actor)->get('/logistics/china?view=stockout')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Logistics/Domestic/Index')
                ->where('viewCounts.stockout', 1)->where('viewCounts.pending', 0));

        $this->actingAs($actor)->post('/logistics/china/orders/'.$order->id.'/approve')->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'workflow_status' => 'sending']);
        $this->assertDatabaseHas('order_operational_markers', [
            'order_id' => $order->id, 'marker' => 'domestic_reviewed',
        ]);
        $this->actingAs($actor)->get('/logistics/china?view=pending')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Logistics/Domestic/Index')
                ->where('viewCounts.stockout', 0)->where('viewCounts.pending', 1));
    }

    public function test_approval_clears_legacy_automatic_provider_and_service_before_pending(): void
    {
        $this->withoutVite();
        config()->set('inertia.testing.ensure_pages_exist', false);
        $reviewer = $this->userWith(['domestic.review', 'logistics.view']);
        $order = $this->order();
        $order->shipment()->update([
            'carrier'=>'4PX', 'service'=>'SD经济挂号-带电',
            'carrier_selection_source'=>'automatic',
        ]);
        $this->actingAs($reviewer)->post('/logistics/china/orders/'.$order->id.'/approve')
            ->assertRedirect();
        $this->assertDatabaseHas('shipments', [
            'order_id'=>$order->id, 'carrier'=>null, 'service'=>null,
            'carrier_selection_source'=>null, 'shipment_status'=>'pending',
        ]);
        $this->actingAs($reviewer)->get('/logistics/china?view=pending')->assertInertia(
            fn($page) => $page->where('rows.total',1)
                ->where('rows.data.0.carrier',null)
                ->where('rows.data.0.service',null)
        );
    }

    public function test_rejection_returns_to_vita_with_a_persisted_reason_and_cleared_warehouse(): void
    {
        $this->withoutVite();
        config()->set('inertia.testing.ensure_pages_exist', false);
        $actor = $this->userWith(['orders.view', 'logistics.view', 'domestic.review']);
        $order = $this->order();
        $order->fulfillmentAllocation()->create([
            'status' => 'submitted', 'decision_source' => 'manual', 'submitted_by' => $actor->id,
        ]);

        $this->actingAs($actor)->post('/logistics/china/orders/'.$order->id.'/reject', [
            'reason' => '收件人地址有误，需要 Vita 核对',
        ])->assertRedirect('/logistics/china?view=review');
        $this->assertDatabaseHas('orders', [
            'id' => $order->id, 'domestic_reject_reason' => '收件人地址有误，需要 Vita 核对',
        ]);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'warehouse_id' => null, 'carrier' => null,
        ]);
        $this->assertDatabaseHas('fulfillment_allocations', [
            'order_id' => $order->id, 'status' => 'rejected',
        ]);
        self::assertSame(1, app(LogisticsStatusSummary::class)->pendingAllocationQuery()
            ->whereKey($order->id)->count());
        $this->actingAs($actor)->get('/customers/orders/'.$order->id)->assertOk()
            ->assertInertia(fn ($page) => $page->component('Orders/Show')
                ->where('order.domestic_reject_reason', '收件人地址有误，需要 Vita 核对'));
    }

    public function test_rejection_requires_reason_and_logistics_role_alone_cannot_review(): void
    {
        $order = $this->order();
        $reviewer = $this->userWith(['logistics.view', 'domestic.review']);
        $this->actingAs($reviewer)->post('/logistics/china/orders/'.$order->id.'/reject', [
            'reason' => '',
        ])->assertSessionHasErrors('reason');
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id,
            'warehouse_id' => $order->shipment->warehouse_id]);

        $logisticsOnly = $this->userWith(['logistics.view', 'logistics.operate']);
        $this->actingAs($logisticsOnly)->post('/logistics/china/orders/'.$order->id.'/stockout')->assertForbidden();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'workflow_status' => null]);
    }

    public function test_domestic_shipping_cannot_bypass_business_approval(): void
    {
        $actor = $this->userWith(['logistics.view', 'logistics.operate']);
        $order = $this->order();

        try {
            app(\App\Modules\Logistics\Application\ShipmentService::class)->markShipped($order, [
                'warehouse_id' => $order->shipment->warehouse_id,
                'carrier' => '4PX', 'tracking_number' => 'TEST123', 'shipped_at' => now()->toIso8601String(),
            ], $actor);
            self::fail('Unreviewed factory shipment must be rejected');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            self::assertSame(409, $exception->getStatusCode());
            self::assertStringContainsString('商务确认', $exception->getMessage());
        }
        self::assertSame('pending', $order->fresh()->shipment->shipment_status);
    }

    public function test_split_order_rejection_unassigns_unshipped_siblings_and_returns_parent_to_vita(): void
    {
        $actor = $this->userWith(['domestic.review']);
        $parent = $this->order();
        $parent->shipment()->delete();
        $factory = Warehouse::query()->where('code', 'FACTORY')->firstOrFail();
        $overseas = Warehouse::query()->where('code', 'US-LA')->firstOrFail();
        foreach (['A' => $factory, 'B' => $overseas] as $suffix => $warehouse) {
            $child = Order::query()->create([
                'fulfillment_parent_id' => $parent->id, 'fulfillment_suffix' => $suffix,
                'fulfillment_role' => 'original', 'erp_order_id' => $parent->erp_order_id.$suffix,
                'internal_order_number' => 'FULF-'.$parent->id.'-'.$suffix,
                'source_type' => OrderSourceType::ManualOther,
                'order_kind' => OrderKind::FulfillmentDerived,
                'order_date' => '2026-11-04', 'order_status' => OrderStatus::PendingShipment,
                'currency' => 'USD',
            ]);
            $child->shipment()->create(['warehouse_id' => $warehouse->id, 'shipment_status' => 'pending']);
        }
        $domestic = $parent->originalFulfillmentChildren()->where('fulfillment_suffix', 'A')->firstOrFail();
        $this->actingAs($actor)->post('/logistics/china/orders/'.$domestic->id.'/reject', [
            'reason' => '收件人税号需重新核对',
        ])->assertRedirect();
        $this->assertDatabaseHas('orders', [
            'id' => $parent->id, 'domestic_reject_reason' => '收件人税号需重新核对',
        ]);
        foreach ($parent->originalFulfillmentChildren as $child) {
            self::assertNull($child->fresh()->shipment->warehouse_id);
        }
        self::assertSame(1, app(LogisticsStatusSummary::class)->pendingAllocationQuery()
            ->whereKey($parent->id)->count());
    }

    public function test_super_admin_can_review_domestic_orders(): void
    {
        $order = $this->order();
        $role = Role::query()->firstOrCreate(['key' => 'super_admin'], ['label' => 'Super Admin']);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach($role);
        $this->actingAs($admin)->post('/logistics/china/orders/'.$order->id.'/approve')->assertRedirect();
        $this->assertDatabaseHas('order_operational_markers', [
            'order_id' => $order->id, 'marker' => 'domestic_reviewed',
        ]);
    }

    private function userWith(array $permissions): User
    {
        $role = Role::query()->create(['key' => 'review-test-'.uniqid(), 'label' => 'Review Test']);
        foreach ($permissions as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    private function order(): Order
    {
        $order = Order::query()->create([
            'erp_order_id' => '007-1103-'.uniqid(), 'internal_order_number' => 'INT-'.uniqid(),
            'source_type' => OrderSourceType::Manual, 'order_kind' => OrderKind::Customer,
            'external_order_number' => 'EXT-'.uniqid(), 'order_date' => '2026-11-04',
            'order_status' => OrderStatus::PendingShipment, 'currency' => 'USD',
        ]);
        $order->address()->create([
            'recipient_name' => 'Test Recipient', 'country' => 'DE',
            'province_state' => 'Berlin', 'city' => 'Berlin', 'address_line_1' => 'Sample Street 1',
            'postal_code' => '10115', 'phone' => '1234567',
        ]);
        $order->items()->create([
            'product_summary' => 'Test SKU', 'sku' => 'REVIEW-TEST', 'quantity' => 1,
            'unit_price' => '10.00', 'line_revenue' => '10.00',
        ]);
        $warehouse = Warehouse::query()->where('code', 'FACTORY')->firstOrFail();
        $order->shipment()->create([
            'warehouse_id' => $warehouse->id, 'shipment_status' => 'pending',
            'carrier' => '4PX', 'warehouse_selection_source' => 'manual',
        ]);

        return $order->fresh('shipment');
    }
}
