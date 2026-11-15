<?php

namespace Tests\Feature\Logistics;

use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\Domestic\DomesticReviewDecisionService;
use App\Modules\Logistics\Application\FulfillmentAllocationSubmissionService;
use App\Modules\Orders\Application\OrderProgressQuery;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Procurement\Application\ExternalProcurementWorkflow;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class ExternalProcurementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_vita_allocates_external_and_zhang_receives_and_vita_releases_without_kaven(): void
    {
        $factory = $this->warehouse('FACTORY');
        $vita = $this->operator(['logistics.view', 'logistics.operate', 'orders.edit', 'domestic.review']);
        $zhang = $this->operator(['procurement.view', 'procurement.operate']);
        [$order, $item] = $this->orderWithItem('M1');

        app(FulfillmentAllocationSubmissionService::class)->submitManual($order, [
            ['order_item_id' => $item->id, 'source_code' => 'OUTSOURCE'],
        ], '1688 order by Zhang', $vita);

        $order->refresh()->load('shipment.warehouse', 'procurementOrder.items');
        self::assertSame('FACTORY', $order->shipment->warehouse->code);
        self::assertSame('pending', $order->shipment->shipment_status);
        self::assertNull($order->shipment->tracking_number);
        $procurement = $order->procurementOrder;
        self::assertNotNull($procurement);
        self::assertSame('external', $procurement->origin);
        self::assertSame('pending', $procurement->status);
        self::assertSame($item->id, $procurement->items->first()->source_order_item_id);
        self::assertFalse(app(OrderProgressQuery::class)->domesticReady(Order::query())->whereKey($order->id)->exists());

        $this->actingAs($zhang)->get('/purchase/external')->assertOk()->assertInertia(
            fn ($page) => $page->component('Logistics/Domestic/Index')
                ->where('view', 'outsource')->where('rows.data.0.erp_order_id', $order->erp_order_id)
                ->where('rows.data.0.procurement.id', $procurement->id)
                ->where('canProcurementOperate', true)
                ->where('canProcurementRelease', false)
                ->etc()
        );
        $this->actingAs($zhang)->get('/logistics/china?view=pending')->assertForbidden();
        $this->actingAs($zhang)->get('/purchase/external?view=pending')->assertOk()->assertInertia(
            fn ($page) => $page->where('view', 'outsource')->etc()
        );
        $this->actingAs($zhang)->post('/purchase/orders/'.$procurement->id.'/release-to-domestic')->assertForbidden();

        $this->actingAs($zhang)->patch('/purchase/orders/'.$procurement->id, [
            'status' => 'purchasing',
        ])->assertRedirect();
        $this->actingAs($zhang)->patch('/purchase/orders/'.$procurement->id, [
            'status' => 'ordered', 'carrier' => '顺丰速运', 'tracking_number' => 'SF-PURCHASE-001',
        ])->assertRedirect();

        // A missing physical receipt is never sufficient to release a customer order.
        $this->actingAs($vita)->post('/purchase/orders/'.$procurement->id.'/release-to-domestic')->assertStatus(409);
        $this->actingAs($zhang)->patch('/purchase/orders/'.$procurement->id, [
            'status' => 'arrived', 'receipt_note' => '2026-11-14 中国工厂已实际收货，Zhang确认',
            'items' => [['id' => $procurement->items->first()->id, 'received_sku' => 'M1', 'received_quantity' => 0]],
        ])->assertStatus(422);
        $this->actingAs($zhang)->patch('/purchase/orders/'.$procurement->id, [
            'status' => 'arrived', 'receipt_note' => '2026-11-14 中国工厂已实际收货，Zhang确认',
            'items' => [['id' => $procurement->items->first()->id, 'received_sku' => 'M1', 'received_quantity' => 1]],
        ])->assertRedirect();
        self::assertSame('arrived', $procurement->fresh()->status);
        $this->actingAs($vita)->post('/purchase/orders/'.$procurement->id.'/release-to-domestic')
            ->assertRedirect('/logistics/china?view=pending');

        $procurement->refresh();
        self::assertSame('released', $procurement->status);
        self::assertNotNull($procurement->released_at);
        self::assertSame($vita->id, $procurement->released_by);
        self::assertSame('pending', $order->fresh('shipment')->shipment->shipment_status);
        self::assertTrue($order->fresh()->operationalMarkers()->where('marker', 'domestic_reviewed')->exists());
        self::assertTrue(app(OrderProgressQuery::class)->domesticReady(Order::query())->whereKey($order->id)->exists());
        $this->assertDatabaseHas('audit_records', ['action' => 'procurement.external_released_to_domestic']);
        $this->assertDatabaseHas('procurement_order_items', ['procurement_order_id' => $procurement->id, 'received_quantity' => 1, 'received_sku' => 'M1']);
    }

    public function test_split_only_purchased_item_creates_procurement_for_real_fulfillment_child(): void
    {
        $this->warehouse('FACTORY');
        $this->warehouse('US-SM');
        $vita = $this->operator(['logistics.view', 'logistics.operate', 'orders.edit', 'domestic.review']);
        [$parent,$item] = $this->orderWithItem('PURCHASED-M1');
        $second = $this->product('IN_STOCK-M2');
        $secondItem = $parent->items()->create([
            'product_id' => $second->id, 'sku' => $second->sku, 'product_summary' => $second->name,
            'quantity' => 1, 'unit_price' => '12.00', 'line_revenue' => '12.00',
        ]);
        app(FulfillmentAllocationSubmissionService::class)->submitManual($parent, [
            ['order_item_id' => $item->id, 'source_code' => 'OUTSOURCE'],
            ['order_item_id' => $secondItem->id, 'source_code' => 'FACTORY'],
        ], 'only M1 outsourced', $vita, true);

        self::assertNull($parent->fresh()->procurementOrder);
        $children = $parent->fresh()->originalFulfillmentChildren()->with(['items', 'procurementOrder', 'shipment.warehouse'])->get();
        self::assertCount(2, $children);
        $purchaseChild = $children->first(fn (Order $child) => $child->items->contains('sku', 'PURCHASED-M1'));
        $otherChild = $children->first(fn (Order $child) => $child->items->contains('sku', 'IN_STOCK-M2'));
        self::assertNotNull($purchaseChild);
        self::assertNotNull($otherChild);
        self::assertNotNull($purchaseChild->procurementOrder);
        self::assertSame('external', $purchaseChild->procurementOrder->origin);
        self::assertNull($otherChild->procurementOrder);
        self::assertSame('FACTORY', $purchaseChild->shipment->warehouse->code);
        self::assertFalse(app(OrderProgressQuery::class)->domesticReview(Order::query())->whereKey($purchaseChild->id)->exists());
        self::assertTrue(app(OrderProgressQuery::class)->domesticReview(Order::query())->whereKey($otherChild->id)->exists());
    }

    public function test_external_reallocation_or_gift_placeholder_cannot_bypass_purchase_identity(): void
    {
        $this->warehouse('FACTORY');
        $vita = $this->operator(['logistics.view', 'logistics.operate']);
        [$order,$item] = $this->orderWithItem('gift');
        try {
            app(FulfillmentAllocationSubmissionService::class)->submitManual($order, [
                ['order_item_id' => $item->id, 'source_code' => 'OUTSOURCE'],
            ], null, $vita);
            self::fail('Expected gift placeholder to be blocked');
        } catch (HttpException $error) {
            self::assertSame(422, $error->getStatusCode());
        }
        self::assertNull($order->fresh()->shipment);
    }

    public function test_vita_reallocation_before_purchase_cancels_superseded_external_task(): void
    {
        $this->warehouse('FACTORY');
        $vita = $this->operator(['logistics.view', 'logistics.operate', 'orders.edit', 'domestic.review']);
        [$order, $item] = $this->orderWithItem('M1-REASSIGN');
        $service = app(FulfillmentAllocationSubmissionService::class);
        $service->submitManual($order, [
            ['order_item_id' => $item->id, 'source_code' => 'OUTSOURCE'],
        ], null, $vita);
        $procurement = $order->fresh()->procurementOrder;
        self::assertSame('pending', $procurement->status);
        $service->submitManual($order->fresh(['items', 'address']), [
            ['order_item_id' => $item->id, 'source_code' => 'FACTORY'],
        ], 'Vita confirmed physical factory stock', $vita, true);
        self::assertSame('cancelled', $procurement->fresh()->status);
        self::assertFalse(Order::query()->whereKey($order->id)
            ->whereHas('procurementOrder', fn ($q) => $q->where('origin', 'external')
                ->whereNotIn('status', ['cancelled', 'completed']))->exists());
    }

    public function test_after_purchase_started_vita_cannot_silently_reallocate_or_kaven_approve(): void
    {
        $this->warehouse('FACTORY');
        $vita = $this->operator(['logistics.view', 'logistics.operate', 'orders.edit', 'domestic.review']);
        $kaven = $this->operator(['domestic.review', 'logistics.view']);
        [$order, $item] = $this->orderWithItem('M1-ACTIVE-PURCHASE');
        $service = app(FulfillmentAllocationSubmissionService::class);
        $service->submitManual($order, [
            ['order_item_id' => $item->id, 'source_code' => 'OUTSOURCE'],
        ], null, $vita);
        $procurement = $order->fresh()->procurementOrder;
        app(ExternalProcurementWorkflow::class)->updateProgress($procurement, 'purchasing', [], $vita);
        try {
            $service->submitManual($order->fresh(['items', 'address']), [
                ['order_item_id' => $item->id, 'source_code' => 'FACTORY'],
            ], null, $vita, true);
            self::fail('Active procurement may not be overwritten');
        } catch (HttpException $error) {
            self::assertSame(409, $error->getStatusCode());
        }
        try {
            app(DomesticReviewDecisionService::class)
                ->decide($order, 'approve', null, $kaven);
            self::fail('Kaven may not bypass Vita arrival release');
        } catch (HttpException $error) {
            self::assertSame(409, $error->getStatusCode());
        }
        self::assertSame('purchasing', $procurement->fresh()->status);
        self::assertFalse($order->fresh()->operationalMarkers()->where('marker', 'domestic_reviewed')->exists());
    }

    private function orderWithItem(string $sku): array
    {
        $order = Order::create([
            'internal_order_number' => 'EXT-'.uniqid(), 'erp_order_id' => '007-EXT-'.uniqid(),
            'external_order_number' => 'EXT-'.uniqid(),
            'source_type' => 'manual', 'order_kind' => 'customer',
            'order_date' => '2026-11-14', 'order_status' => 'pending_shipment', 'currency' => 'USD',
        ]);
        $order->address()->create([
            'recipient_name' => 'Joseph Test', 'phone' => '10000000000', 'country' => 'US',
            'province_state' => 'CA', 'city' => 'Los Angeles',
            'address_line_1' => '123 Test Street', 'postal_code' => '90001',
        ]);
        $product = $this->product($sku);
        $item = $order->items()->create([
            'product_id' => $product->id, 'sku' => $sku, 'product_summary' => $product->name,
            'quantity' => 1, 'unit_price' => '21.00', 'line_revenue' => '21.00',
        ]);

        return [$order, $item];
    }

    private function warehouse(string $code): Warehouse
    {
        return Warehouse::firstOrCreate(['code' => $code], [
            'slug' => strtolower($code).'-test', 'name' => $code.' Warehouse',
            'country_code' => $code === 'FACTORY' ? 'CN' : 'US', 'is_active' => true, 'priority' => 1,
        ]);
    }

    private function product(string $sku): Product
    {
        return Product::create(['sku' => $sku, 'name' => 'Item '.$sku, 'product_type' => 'simple', 'source_type' => 'manual', 'is_active' => true]);
    }

    private function operator(array $permissions): User
    {
        $role = Role::create(['key' => 'external-test-'.uniqid(), 'label' => 'External Procurement']);
        foreach ($permissions as $key) {
            $role->permissions()->firstOrCreate(['key' => $key], ['label' => $key]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
