<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\Contracts\WooCommerceTrackingGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LogisticsTrackingPushControllerTest extends TestCase
{
    use RefreshDatabase;

    private TrackingPushFakeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new TrackingPushFakeGateway;
        $this->app->instance(WooCommerceTrackingGateway::class, $this->gateway);
    }

    public function test_vita_role_can_manually_push_shipped_woo_order_without_global_sync_operate(): void
    {
        $user = $this->userWith('role_preset_operations', ['logistics.view', 'orders.view', 'sync.view']);
        self::assertFalse($user->can('sync.operate'));
        $order = $this->order();

        $this->actingAs($user)->post('/logistics/tracking-outbound/selected', ['order_ids' => [$order->id]])
            ->assertRedirect()->assertSessionHas('success');

        self::assertSame(1, $this->gateway->calls);
        $this->assertDatabaseHas('manual_syncs', ['order_id' => $order->id, 'website_sync_status' => 'completed']);
    }

    public function test_repeated_explicit_sync_is_idempotent_and_does_not_duplicate_woo_tracking(): void
    {
        $user = $this->userWith('role_preset_logistics', ['logistics.view', 'logistics.operate', 'sync.view', 'sync.operate']);
        $order = $this->order();

        $this->actingAs($user)->post('/logistics/tracking-outbound/selected', ['order_ids' => [$order->id]])
            ->assertSessionHas('success');
        $this->actingAs($user)->post('/logistics/tracking-outbound/selected', ['order_ids' => [$order->id]])
            ->assertSessionHas('success');

        self::assertSame(1, $this->gateway->calls);
    }

    public function test_custom_permission_mode_operations_account_cannot_inherit_scoped_authority(): void
    {
        $user = $this->userWith('role_preset_operations', ['logistics.view', 'orders.view', 'sync.view']);
        $user->update(['permission_mode' => 'custom']);
        $order = $this->order();
        $this->actingAs($user)->post('/logistics/tracking-outbound/selected', ['order_ids' => [$order->id]])->assertForbidden();
        self::assertSame(0, $this->gateway->calls);
    }

    public function test_not_shipped_woo_order_is_rejected_without_mutating_outbound(): void
    {
        $user = $this->userWith('role_preset_operations', ['logistics.view', 'sync.view']);
        $order = $this->order();
        $order->shipment->update(['shipment_status' => 'pending']);
        $this->actingAs($user)->post('/logistics/tracking-outbound/selected', ['order_ids' => [$order->id]])
            ->assertStatus(422);
        self::assertSame(0, $this->gateway->calls);
        $this->assertDatabaseCount('sync_outbox', 0);
    }

    public function test_non_woo_order_cannot_be_pushed(): void
    {
        $user = $this->userWith('role_preset_operations', ['logistics.view', 'sync.view']);
        $order = $this->order();
        $order->forceFill(['source_type' => 'manual_other'])->save();
        $this->actingAs($user)->post('/logistics/tracking-outbound/selected', ['order_ids' => [$order->id]])
            ->assertStatus(422);
        self::assertSame(0, $this->gateway->calls);
    }

    public function test_duplicate_input_ids_are_rejected_prior_to_remote_write(): void
    {
        $user = $this->userWith('role_preset_operations', ['logistics.view', 'sync.view']);
        $order = $this->order();
        $this->actingAs($user)->post('/logistics/tracking-outbound/selected', ['order_ids' => [$order->id, $order->id]])
            ->assertSessionHasErrors('order_ids.1');
        self::assertSame(0, $this->gateway->calls);
    }

    public function test_immediate_outbound_is_off_by_default_and_never_calls_gateway(): void
    {
        $order = $this->order();
        self::assertFalse((bool) config('tracking_sync_v2.immediate_enabled'));
        $result = app(\App\Modules\Logistics\Application\LogisticsTrackingImmediateDispatch::class)->afterShipmentSaved($order, null);
        self::assertSame('disabled', $result['status']);
        self::assertSame(0, $this->gateway->calls);
    }

    public function test_enabled_immediate_dispatch_uses_same_idempotent_outbox(): void
    {
        config(['tracking_sync_v2.immediate_enabled' => true]);
        $order = $this->order();
        $dispatcher = app(\App\Modules\Logistics\Application\LogisticsTrackingImmediateDispatch::class);
        $first = $dispatcher->afterShipmentSaved($order, null);
        $second = $dispatcher->afterShipmentSaved($order, null);

        self::assertSame('completed', $first['status']);
        self::assertSame('completed', $second['status']);
        self::assertSame(1, $this->gateway->calls);
        $this->assertDatabaseHas('manual_syncs', ['order_id' => $order->id, 'website_sync_status' => 'completed']);
    }

    public function test_immediate_dispatch_never_sends_unshipped_or_manual_source(): void
    {
        config(['tracking_sync_v2.immediate_enabled' => true]);
        $order = $this->order();
        $order->shipment->update(['shipment_status' => 'pending']);
        $dispatcher = app(\App\Modules\Logistics\Application\LogisticsTrackingImmediateDispatch::class);
        self::assertSame('not_eligible', $dispatcher->afterShipmentSaved($order, null)['status']);
        $order->shipment->update(['shipment_status' => 'shipped']);
        $order->forceFill(['source_type' => 'manual_other'])->save();
        self::assertSame('not_eligible', $dispatcher->afterShipmentSaved($order, null)['status']);
        self::assertSame(0, $this->gateway->calls);
    }

    public function test_automatic_retry_only_selects_outbox_after_activation_baseline(): void
    {
        config(['tracking_sync_v2.periodic_enabled' => true, 'tracking_sync_v2.min_outbox_id' => '1']);
        $gateway = $this->gateway;
        $gateway->fail = true;
        $old = $this->order(1009);
        $service = app(\App\Modules\Sync\Application\WooCommerceTrackingOutboundService::class);
        self::assertSame('retry_scheduled', $service->pushOne($old, null)['status']);
        $oldOutboxId = (int) \Illuminate\Support\Facades\DB::table('sync_outbox')
            ->where('operation', 'woocommerce.tracking_outbound')->value('id');

        $new = $this->order(1010);
        self::assertSame('retry_scheduled', $service->pushOne($new, null)['status']);
        \Illuminate\Support\Facades\DB::table('sync_outbox')
            ->where('operation', 'woocommerce.tracking_outbound')
            ->update(['available_at' => now()->subMinute()]);

        $gateway->fail = false;
        $run = app(\App\Modules\Sync\Application\SyncOrchestrationService::class)
            ->retryPending(null, 50, $oldOutboxId);

        self::assertSame(1, $run->requested_items);
        self::assertSame(1, $run->succeeded_items);
        $this->assertDatabaseHas('sync_outbox', ['id' => $oldOutboxId, 'status' => 'pending']);
        $this->assertDatabaseHas('manual_syncs', ['order_id' => $new->id, 'website_sync_status' => 'completed']);
        $this->assertDatabaseHas('manual_syncs', ['order_id' => $old->id, 'website_sync_status' => 'pending']);
    }

    public function test_tracking_retry_schedule_requires_activation_baseline_and_default_is_disabled(): void
    {
        self::assertFalse((bool) config('tracking_sync_v2.periodic_enabled'));
        config(['tracking_sync_v2.periodic_enabled' => true, 'tracking_sync_v2.min_outbox_id' => null]);
        require base_path('routes/console.php');
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());
        self::assertCount(0, $events->where('description', 'tracking.woocommerce.outbound.retry.half-hourly'));

        config(['tracking_sync_v2.min_outbox_id' => '13']);
        require base_path('routes/console.php');
        $tracking = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->where('description', 'tracking.woocommerce.outbound.retry.half-hourly');
        self::assertCount(1, $tracking);
        self::assertSame('*/30 * * * *', $tracking->first()->expression);
        self::assertTrue($tracking->first()->onOneServer);
        self::assertTrue($tracking->first()->withoutOverlapping);
        self::assertSame(25, $tracking->first()->expiresAt);
    }

    private function userWith(string $roleKey, array $permissions): User
    {
        $role = Role::create(['key' => $roleKey, 'label' => 'Tracking Operator']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user = User::factory()->create(['is_active' => true, 'permission_mode' => 'role']);
        $user->roles()->attach($role);

        return $user;
    }

    private function order(int $providerOrderId = 1009): Order
    {
        $order = Order::create([
            'internal_order_number' => 'TRACK-'.$providerOrderId,
            'source_type' => 'woocommerce',
            'order_kind' => 'customer',
            'external_order_number' => 'WC-'.$providerOrderId,
            'order_date' => '2026-10-28',
            'order_status' => 'shipped',
            'currency' => 'USD',
        ]);
        $order->externalReferences()->create([
            'provider' => 'woocommerce',
            'connection_key' => 'shop-a',
            'reference' => (string) $providerOrderId,
        ]);
        $order->items()->create([
            'woocommerce_line_item_id' => $providerOrderId + 1992,
            'product_summary' => 'Tracking fixture',
            'sku' => 'TRACK-PRODUCT',
            'quantity' => 1,
            'unit_price' => '12.00',
            'line_revenue' => '12.00',
        ]);
        $order->shipment()->create([
            'shipment_status' => 'shipped',
            'carrier' => 'DHL',
            'service' => 'Express',
            'tracking_number' => 'TRACK-'.$providerOrderId,
            'shipped_at' => '2026-10-28T12:00:00Z',
        ]);
        $order->manualSync()->create(['website_sync_status' => 'pending']);

        return $order->fresh(['shipment', 'items', 'externalReferences', 'manualSync']);
    }
}

final class TrackingPushFakeGateway implements WooCommerceTrackingGateway
{
    public int $calls = 0;
    public bool $fail = false;

    public function descriptor(): array
    {
        return ['configured' => true, 'url' => 'https://shop.test', 'connection_key' => 'shop-a'];
    }

    public function inspectTracking(int $providerOrderId): array
    {
        return ['tracking_number' => '', 'provider' => '', 'has_tracking' => false];
    }

    public function syncTracking(int $providerOrderId, string $providerSlug, string $trackingNumber, string $shipDate, array $lineItems = []): array
    {
        $this->calls++;
        if ($this->fail) { throw new \RuntimeException('Test-only transient Woo tracking gateway failure'); }
        return ['written' => true, 'verified' => true];
    }

    public function syncOrderStatus(int $providerOrderId, string $status): array
    {
        return ['written' => true, 'verified' => true, 'status' => $status];
    }
}
