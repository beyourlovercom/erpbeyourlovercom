<?php

namespace Tests\Feature\Logistics;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\ShipOut\ShipOutSandboxDispatchService;
use App\Modules\Logistics\Infrastructure\ShipOut\ShipOutReadOnlyGateway;
use App\Modules\Logistics\Infrastructure\ShipOut\ShipOutSandboxOutboundTransport;
use App\Modules\Logistics\Infrastructure\Models\OverseaWarehouseReview;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ShipOutSandboxDispatchServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Deliberately synthetic credentials; connector isn't runtime-bound yet.
        $this->app->bind(ShipOutReadOnlyGateway::class,
            fn () => new ShipOutReadOnlyGateway('test-key', 'test-secret', 'test-token'));
        $this->app->bind(ShipOutSandboxOutboundTransport::class,
            fn () => new ShipOutSandboxOutboundTransport('test-key', 'test-secret', 'test-token'));
    }

    private function order(bool $approved = true): Order
    {
        $wh = Warehouse::query()->updateOrCreate(['code' => 'US-LA'], [
            'slug' => 'us-la', 'name' => 'MorePlus',
            'type' => 'warehouse', 'is_active' => true, 'priority' => 10,
        ]);
        $sku = Product::query()->create([
            'sku' => 'SHIPOUT-LOCAL-TEST', 'name' => 'Sandbox Product',
            'product_type' => 'simple', 'is_active' => true,
        ]);
        $o = Order::query()->create([
            'internal_order_number' => 'TEST-ORDER-UNIQUE',
            'erp_order_id' => '007-1029-c99999',
            'source_type' => 'manual_other', 'order_kind' => 'customer',
            'external_order_number' => 'SOURCE-99999',
            'order_date' => '2026-10-29', 'order_status' => 'pending_shipment',
        ]);
        $o->address()->create([
            'recipient_name' => 'TEST ONLY', 'phone' => '+1 555 0199',
            'email' => 'sandbox@example.test', 'country' => 'US',
            'province_state' => 'CA', 'city' => 'Test City',
            'address_line_1' => '100 Example Way', 'postal_code' => '90210',
        ]);
        $o->items()->create([
            'product_id' => $sku->id, 'sku' => $sku->sku,
            'product_summary' => 'Sandbox Product', 'quantity' => 1,
            'unit_price' => '1.00', 'line_revenue' => '1.00',
        ]);
        $shipment = $o->shipment()->create([
            'warehouse_id' => $wh->id,
            'warehouse_selection_source' => 'manual',
            'shipment_status' => 'pending', 'carrier' => 'USPS',
        ]);
        $user = User::factory()->create();
        OverseaWarehouseReview::query()->create([
            'order_id' => $o->id,
            'submitted_warehouse_id' => $wh->id,
            'reviewed_warehouse_id' => $approved ? $wh->id : null,
            'status' => $approved ? 'approved' : 'pending',
            'reviewed_by' => $approved ? $user->id : null,
            'reviewed_at' => $approved ? now() : null,
        ]);
        return $o;
    }

    private function enable(): void
    {
        config()->set('shipout.sandbox_read_enabled', true);
        config()->set('shipout.sandbox_dispatch_enabled', true);
        config()->set('shipout.sandbox_order_allowlist', 'C99999-007');
        config()->set('shipout.sandbox_warehouse_id', 'TEST-W');
    }

    public function test_approved_order_is_submitted_once_and_second_submission_is_blocked(): void
    {
        $this->enable();
        $o = $this->order();
        Http::fake(fn ($request) => $request->method() === 'GET'
            ? Http::response(['result'=>'OK','data'=>['records'=>[],'total'=>0]], 200)
            : Http::response([['result'=>'OK', 'data'=>[
                'sid'=>1,'orderNO'=>'C99999-007','orderId'=>'PROVIDER-TEST-123',
            ]]], 200));
        Http::preventStrayRequests();

        $service = app(ShipOutSandboxDispatchService::class);
        $result = $service->dispatchApprovedOrder($o->id, '2026-10-30');
        $this->assertSame('acknowledged', $result['status']);
        $this->assertDatabaseHas('shipout_dispatch_attempts', [
            'order_id' => $o->id, 'status' => 'acknowledged',
            'provider_order_id' => 'PROVIDER-TEST-123',
        ]);
        $this->expectExceptionMessage('SHIPOUT_DUPLICATE_ATTEMPT_BLOCKED');
        try {
            $service->dispatchApprovedOrder($o->id, '2026-10-30');
        } finally {
            Http::assertSentCount(3);
            Http::assertSent(fn ($request): bool => $request->method() === 'POST'
                && $request->url() === 'https://opendev.shipout.com/api/open-api/oms/order/batchSubmitAsync');
        }
    }

    public function test_dede_unapproved_order_blocks_before_any_api_call(): void
    {
        $this->enable();
        $o = $this->order(false);
        Http::fake();
        $this->expectExceptionMessage('SHIPOUT_ERP_APPROVAL_OR_WAREHOUSE_BLOCKED');
        try {
            app(ShipOutSandboxDispatchService::class)->dispatchApprovedOrder($o->id, '2026-10-30');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_timeout_remains_uncertain_and_never_automatically_retries(): void
    {
        $this->enable();
        $o = $this->order();
        Http::fake(fn ($request) => $request->method() === 'GET'
            ? Http::response(['result'=>'OK','data'=>['records'=>[],'total'=>0]], 200)
            : Http::response(['not'=>'success'], 503));
        $service = app(ShipOutSandboxDispatchService::class);
        try {
            $service->dispatchApprovedOrder($o->id, '2026-10-30');
            $this->fail('503 cannot be accepted.');
        } catch (\RuntimeException $e) {
            $this->assertSame('SHIPOUT_SUBMISSION_OUTCOME_UNKNOWN', $e->getMessage());
        }
        $this->assertDatabaseHas('shipout_dispatch_attempts', [
            'order_id' => $o->id, 'status' => 'uncertain',
        ]);
        $this->expectExceptionMessage('SHIPOUT_DUPLICATE_ATTEMPT_BLOCKED');
        try {
            $service->dispatchApprovedOrder($o->id, '2026-10-30');
        } finally {
            Http::assertSentCount(3);
        }
    }
}
