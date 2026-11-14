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
        $root->forceFill(['source_type' => OrderSourceType::WooCommerce])->save();
        $root->financials()->create(['paid_at' => '2026-10-18 12:00:00', 'provider_order_total' => '145.68', 'currency' => 'EUR']);
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
                ->where('rows.data.0.paid_date_source', '母订单支付日期')
                ->where('rows.data.0.amount', '145.68')
                ->where('rows.data.0.amount_currency', 'EUR')
                ->where('rows.data.0.amount_source', '母订单总金额 · 分仓不重复计收'));
        $this->assertNull($child->financials, 'split fulfillment never invents its own payment fact');
    }

    public function test_r4_bulk_review_reuses_original_domestic_gates_and_reports_mixed_rows(): void
    {
        $actor = $this->userWith(['logistics.view', 'domestic.review']);
        $first = $this->order();
        $second = $this->order();
        $this->actingAs($actor)->postJson('/logistics/china/batch-review', [
            'order_ids' => [$first->id, $second->id, 987654321],
            'action' => 'stockout',
        ])->assertOk()->assertJsonPath('success_count', 2)
            ->assertJsonPath('failed_count', 1);
        $this->assertDatabaseHas('orders', ['id' => $first->id, 'workflow_status' => 'out_of_stock']);
        $this->assertDatabaseHas('orders', ['id' => $second->id, 'workflow_status' => 'out_of_stock']);
        $this->actingAs($actor)->postJson('/logistics/china/batch-review', [
            'order_ids' => [$first->id, $second->id],
            'action' => 'restock',
        ])->assertOk()->assertJsonPath('success_count', 2);
        $this->actingAs($actor)->postJson('/logistics/china/batch-review', [
            'order_ids' => [$first->id],
            'action' => 'reject', 'reason' => 'a',
        ])->assertUnprocessable();
        $unauthorized = $this->userWith(['logistics.view']);
        $this->actingAs($unauthorized)->postJson('/logistics/china/batch-review', [
            'order_ids' => [$first->id], 'action' => 'approve',
        ])->assertForbidden();
    }

    public function test_stockout_return_to_allocation_is_not_a_business_rejection(): void
    {
        $this->withoutVite();
        config()->set('inertia.testing.ensure_pages_exist', false);
        $actor = $this->userWith(['logistics.view', 'domestic.review']);
        $order = $this->order();
        $warehouse = $order->shipment->warehouse_id;
        $this->actingAs($actor)->post('/logistics/china/orders/'.$order->id.'/stockout')->assertRedirect();
        $this->actingAs($actor)->post('/logistics/china/orders/'.$order->id.'/return-allocation')->assertRedirect();
        $order->refresh();
        $this->assertNull($order->shipment->warehouse_id);
        $this->assertNotEquals($warehouse, $order->shipment->warehouse_id);
        $this->assertSame('sending', $order->workflow_status);
        $this->assertNull($order->domestic_reject_reason);
        $this->assertDatabaseMissing('order_operational_markers', ['order_id'=>$order->id, 'marker'=>'domestic_stockout']);
        $this->actingAs($actor)->get('/logistics/wait-stock')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Logistics/FulfillmentAllocation')
                ->where('orders.total', 1));
        $this->actingAs($actor)->post('/logistics/china/orders/'.$order->id.'/return-allocation')->assertStatus(409);
    }

    public function test_bulk_stockout_return_to_allocation_isolates_bad_rows(): void
    {
        $actor = $this->userWith(['logistics.view', 'domestic.review']);
        $first = $this->order();
        $second = $this->order();
        $this->actingAs($actor)->post('/logistics/china/orders/'.$first->id.'/stockout')->assertRedirect();
        $this->actingAs($actor)->postJson('/logistics/china/batch-review', [
            'order_ids'=>[$first->id, $second->id], 'action'=>'return_allocation',
        ])->assertOk()->assertJsonPath('success_count', 1)->assertJsonPath('failed_count', 1);
        $this->assertNull($first->fresh()->shipment->warehouse_id);
        $this->assertNotNull($second->fresh()->shipment->warehouse_id);
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

    public function test_derived_factory_auxiliary_stockout_uses_domestic_business_workflow(): void
    {
        $this->withoutVite();
        config()->set('inertia.testing.ensure_pages_exist', false);
        $actor = $this->userWith(['orders.view', 'orders.edit', 'logistics.view', 'domestic.review']);
        $root = $this->order();
        $root->shipment()->delete();
        $child = Order::query()->create([
            'fulfillment_parent_id'=>$root->id, 'fulfillment_suffix'=>'A', 'fulfillment_role'=>'original',
            'erp_order_id'=>$root->erp_order_id.'A', 'internal_order_number'=>'AUX-'.$root->id,
            'source_type'=>OrderSourceType::ManualOther, 'order_kind'=>OrderKind::FulfillmentDerived,
            'order_date'=>'2026-11-10', 'order_status'=>OrderStatus::PendingShipment, 'currency'=>'USD',
        ]);
        $child->shipment()->create([
            'warehouse_id'=>Warehouse::query()->where('code','FACTORY')->firstOrFail()->id,
            'shipment_status'=>'pending',
        ]);

        $this->actingAs($actor)->get('/customers/orders/'.$child->id)->assertOk()->assertInertia(
            fn ($page) => $page->component('Orders/Show')
                ->where('canMarkOperational', false)
                ->where('isDomesticFactory', true)
                ->where('canMarkDomesticStockout', true)
        );
        // Direct/legacy marker URL must also use the canonical domestic review gate.
        $this->actingAs($actor)->post('/orders/'.$child->id.'/operational-markers/stockout', [
            'active'=>true,
        ])->assertRedirect()->assertSessionHas('success', '已标记缺货，订单进入国内「缺货单」。');

        $this->assertDatabaseHas('orders', [
            'id'=>$child->id, 'workflow_status'=>'out_of_stock',
        ]);
        $this->assertDatabaseHas('order_operational_markers', [
            'order_id'=>$child->id,'marker'=>'domestic_stockout','marked_by'=>$actor->id,
        ]);
        $this->assertDatabaseMissing('order_operational_markers', [
            'order_id'=>$child->id,'marker'=>'stockout',
        ]);
        $this->assertDatabaseMissing('order_operational_markers', [
            'order_id'=>$child->id,'marker'=>'domestic_reviewed',
        ]);
        $this->assertDatabaseHas('shipments', [
            'order_id'=>$child->id,'shipment_status'=>'pending','tracking_number'=>null,'shipped_at'=>null,
        ]);
        $this->actingAs($actor)->get('/logistics/china?view=stockout')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Logistics/Domestic/Index')
                ->where('viewCounts.stockout',1)->where('viewCounts.pending',0));

        // Do not let a second click silently clear the fulfillment shortage gate.
        $this->actingAs($actor)->post('/orders/'.$child->id.'/operational-markers/stockout', [
            'active'=>false,
        ])->assertStatus(409);
        $this->actingAs($actor)->post('/logistics/china/orders/'.$child->id.'/restock')
            ->assertRedirect('/logistics/china?view=pending');
        $this->actingAs($actor)->get('/logistics/china?view=pending')->assertInertia(
            fn ($page) => $page->where('viewCounts.pending',1)->where('viewCounts.stockout',0)
        );
    }

    public function test_factory_auxiliary_stockout_permission_and_shipment_gate_remain_enforced(): void
    {
        $order = $this->order();
        $editor = $this->userWith(['orders.view', 'orders.edit']);
        $reviewer = $this->userWith(['orders.view', 'orders.edit', 'domestic.review']);
        $this->actingAs($editor)->post('/orders/'.$order->id.'/operational-markers/stockout', [
            'active'=>true,
        ])->assertForbidden();
        $this->assertDatabaseMissing('order_operational_markers', [
            'order_id'=>$order->id,'marker'=>'domestic_stockout',
        ]);
        $order->shipment()->update(['shipment_status'=>'shipped','shipped_at'=>now(),'tracking_number'=>'LIVE-GATE-1']);
        $this->actingAs($reviewer)->post('/orders/'.$order->id.'/operational-markers/stockout', [
            'active'=>true,
        ])->assertStatus(409);
        $this->assertDatabaseMissing('order_operational_markers', [
            'order_id'=>$order->id,'marker'=>'domestic_stockout',
        ]);
    }

    public function test_overseas_auxiliary_stockout_remains_annotation_not_domestic_review(): void
    {
        $order = $this->order();
        $order->shipment()->update(['warehouse_id'=>Warehouse::query()->where('code','US-LA')->firstOrFail()->id]);
        $actor = $this->userWith(['orders.view','orders.edit']);
        $this->actingAs($actor)->post('/orders/'.$order->id.'/operational-markers/stockout', [
            'active'=>true,
        ])->assertRedirect();
        $this->assertDatabaseHas('order_operational_markers', [
            'order_id'=>$order->id,'marker'=>'stockout',
        ]);
        $this->assertDatabaseMissing('order_operational_markers', [
            'order_id'=>$order->id,'marker'=>'domestic_stockout',
        ]);
        self::assertNull($order->fresh()->workflow_status);
    }

    public function test_restock_moves_only_out_of_stock_order_into_pending_without_shipping(): void
    {
        $this->withoutVite();
        config()->set('inertia.testing.ensure_pages_exist', false);
        $actor = $this->userWith(['logistics.view', 'domestic.review']);
        $order = $this->order();

        $this->actingAs($actor)->post('/logistics/china/orders/'.$order->id.'/stockout')->assertRedirect();
        $this->actingAs($actor)->post('/logistics/china/orders/'.$order->id.'/restock')
            ->assertRedirect('/logistics/china?view=pending')
            ->assertSessionHas('success', '已确认到货，订单已进入待发货。');
        $this->assertDatabaseHas('orders', ['id'=>$order->id, 'workflow_status'=>'sending', 'order_status'=>'pending_shipment']);
        $this->assertDatabaseMissing('order_operational_markers', ['order_id'=>$order->id, 'marker'=>'domestic_stockout']);
        $this->assertDatabaseHas('order_operational_markers', ['order_id'=>$order->id, 'marker'=>'domestic_reviewed', 'marked_by'=>$actor->id]);
        $this->assertDatabaseHas('shipments', [
            'order_id'=>$order->id, 'shipment_status'=>'pending', 'tracking_number'=>null, 'shipped_at'=>null,
        ]);
        $this->assertDatabaseHas('audit_records', [
            'entity_id'=>$order->id, 'action'=>'domestic.review_restock',
        ]);
        $this->actingAs($actor)->get('/logistics/china?view=pending')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Logistics/Domestic/Index')
                ->where('viewCounts.stockout',0)->where('viewCounts.pending',1)
                ->where('rows.data.0.order_id',$order->id));
    }

    public function test_restock_requires_business_permission_and_actual_stockout_state(): void
    {
        $order = $this->order();
        $actor = $this->userWith(['logistics.view', 'domestic.review']);
        $logisticsOnly = $this->userWith(['logistics.view', 'logistics.operate']);

        $this->actingAs($actor)->post('/logistics/china/orders/'.$order->id.'/restock')->assertStatus(409);
        $this->assertDatabaseMissing('order_operational_markers', ['order_id'=>$order->id,'marker'=>'domestic_reviewed']);
        $this->actingAs($actor)->post('/logistics/china/orders/'.$order->id.'/stockout')->assertRedirect();
        $this->actingAs($logisticsOnly)->post('/logistics/china/orders/'.$order->id.'/restock')->assertForbidden();
        $this->assertDatabaseHas('orders', ['id'=>$order->id,'workflow_status'=>'out_of_stock']);
        $this->assertDatabaseHas('order_operational_markers', ['order_id'=>$order->id,'marker'=>'domestic_stockout']);
    }

    public function test_approval_persists_existing_country_default_without_manual_confirmation(): void
    {
        $this->withoutVite();
        config()->set('inertia.testing.ensure_pages_exist', false);
        $reviewer = $this->userWith(['domestic.review', 'logistics.view']);
        $order = $this->order();
        $order->shipment()->update([
            'carrier'=>'4PX', 'service'=>'SD经济挂号-带电',
            'carrier_selection_source'=>'automatic',
        ]);
        self::assertNotNull(app(\App\Modules\Logistics\Application\Domestic\DomesticCarrierDefaults::class)->recommended($order));
        $this->actingAs($reviewer)->post('/logistics/china/orders/'.$order->id.'/approve')
            ->assertRedirect();
        $this->assertDatabaseHas('shipments', [
            'order_id'=>$order->id, 'carrier'=>'4PX', 'service'=>'SD经济挂号-带电',
            'carrier_selection_source'=>'automatic', 'shipment_status'=>'pending',
        ]);
        $this->actingAs($reviewer)->get('/logistics/china?view=pending')->assertInertia(
            fn($page) => $page->where('rows.total',1)
                ->where('rows.data.0.carrier','4PX')
                ->where('rows.data.0.service','SD经济挂号-带电')
                ->where('rows.data.0.carrier_ready',true)
        );
    }

    public function test_factory_country_override_selects_weship_and_preserves_staff_choice(): void
    {
        $reviewer = $this->userWith(['domestic.review', 'logistics.view']);
        $mexico = $this->order();
        $mexico->address()->update(['country' => 'MX']);
        $this->actingAs($reviewer)->post('/logistics/china/orders/'.$mexico->id.'/approve')->assertRedirect();
        $this->assertDatabaseHas('shipments', [
            'order_id'=>$mexico->id, 'carrier'=>'Weship', 'service'=>'Standard',
            'carrier_selection_source'=>'automatic', 'shipment_status'=>'pending',
        ]);

        $custom = $this->order();
        $custom->address()->update(['country' => 'MX']);
        $custom->shipment()->update([
            'carrier'=>'4PX','service'=>'SD标准挂号-带电','carrier_selection_source'=>'manual',
        ]);
        $this->actingAs($reviewer)->post('/logistics/china/orders/'.$custom->id.'/approve')->assertRedirect();
        $this->assertDatabaseHas('shipments', [
            'order_id'=>$custom->id, 'carrier'=>'4PX', 'service'=>'SD标准挂号-带电',
            'carrier_selection_source'=>'manual',
        ]);
    }

    public function test_missing_destination_cannot_be_automatically_committed(): void
    {
        $reviewer = $this->userWith(['domestic.review', 'logistics.view']);
        $order = $this->order();
        $order->address()->update(['country' => null]);
        $this->actingAs($reviewer)->post('/logistics/china/orders/'.$order->id.'/approve')->assertRedirect();
        $this->assertDatabaseHas('shipments', [
            'order_id'=>$order->id, 'carrier'=>null, 'service'=>null,
            'carrier_selection_source'=>null,
        ]);
    }

    public function test_existing_pending_routes_backfill_is_dry_run_by_default_and_keeps_manual_overrides(): void
    {
        $reviewer = $this->userWith(['domestic.review', 'logistics.view']);
        $automatic = $this->order();
        $this->actingAs($reviewer)->post('/logistics/china/orders/'.$automatic->id.'/approve')->assertRedirect();
        $automatic->shipment()->update([
            'carrier'=>null,'service'=>null,'carrier_service_id'=>null,'carrier_selection_source'=>null,
        ]);
        $manual = $this->order();
        $manual->shipment()->update([
            'carrier'=>'4PX','service'=>'SD标准挂号-带电','carrier_selection_source'=>'manual',
        ]);
        $this->actingAs($reviewer)->post('/logistics/china/orders/'.$manual->id.'/approve')->assertRedirect();
        $unreviewed = $this->order();
        $this->artisan('domestic:backfill-carrier-defaults')->assertExitCode(0);
        self::assertNull($automatic->fresh()->shipment->carrier);
        $this->artisan('domestic:backfill-carrier-defaults', ['--apply'=>true])->assertExitCode(0);
        $this->assertDatabaseHas('shipments', [
            'order_id'=>$automatic->id,'carrier'=>'4PX','service'=>'SD经济挂号-带电',
            'carrier_selection_source'=>'automatic',
        ]);
        $this->assertDatabaseHas('shipments', [
            'order_id'=>$manual->id,'carrier'=>'4PX','service'=>'SD标准挂号-带电',
            'carrier_selection_source'=>'manual',
        ]);
        self::assertNull($unreviewed->fresh()->shipment->carrier_selection_source);
        $this->artisan('domestic:backfill-carrier-defaults', ['--apply'=>true])->assertExitCode(0);
        self::assertSame('automatic', $automatic->fresh()->shipment->carrier_selection_source);
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

    public function test_domestic_rejection_notifies_operations_and_opens_rejected_queue(): void
    {
        $reviewer = $this->userWith(['orders.view', 'logistics.view', 'domestic.review']);
        $operationsRole = Role::query()->firstOrCreate(
            ['key' => \App\Modules\Identity\Application\RolePreset::Operations->roleKey()],
            ['label' => '运营人员'],
        );
        $vita = User::factory()->create(['is_active' => true]);
        $vita->roles()->attach($operationsRole);
        $order = $this->order();

        $this->actingAs($reviewer)->post('/logistics/china/orders/'.$order->id.'/reject', [
            'reason' => '请运营重新确认收件地址',
        ])->assertRedirect('/logistics/china?view=review');
        $notification = $vita->notifications()->first();
        $this->assertNotNull($notification);
        $this->assertSame('国内商务分仓被驳回', $notification->data['title']);
        $this->assertSame('/logistics/wait-stock?rejected=1', $notification->data['action_url']);
        $this->assertStringContainsString('请运营重新确认收件地址', $notification->data['message']);
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

    public function test_domestic_grid_kol_order_uses_kind_aware_detail_route(): void
    {
        $this->withoutVite();
        config()->set('inertia.testing.ensure_pages_exist', false);
        $actor = $this->userWith(['logistics.view', 'orders.view', 'kol.view', 'kol.views.manage']);
        $order = $this->order();
        $order->forceFill(['order_kind' => OrderKind::KolFulfillment])->save();

        $this->actingAs($actor)->get('/logistics/china?view=review')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('rows.total', 1)
                ->where('rows.data.0.order_url', '/orders/'.$order->id));
        $this->actingAs($actor)->get('/orders/'.$order->id)
            ->assertRedirect('/kol/orders/'.$order->id);
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
