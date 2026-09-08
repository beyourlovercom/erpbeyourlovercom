<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Modules\Logistics\Application\ShipmentService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CustomerFulfillmentReviewGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_tracking_requires_vita_approval_before_pending_or_completion(): void
    {
        $vita = $this->userWith(['sync.view', 'sync.operate']);
        $order = $this->order('customer');

        app(ShipmentService::class)->markShipped($order, $this->shipment('CUSTOMER-TRACK-1'), null);

        $this->assertDatabaseHas('manual_syncs', [
            'order_id' => $order->id,
            'website_sync_status' => 'needs_confirmation',
        ]);

        $this->actingAs($vita)->post("/sync/orders/{$order->id}/complete")->assertStatus(409);
        $this->actingAs($vita)->post("/sync/orders/{$order->id}/reopen")->assertStatus(409);
        $this->assertDatabaseHas('manual_syncs', [
            'order_id' => $order->id,
            'website_sync_status' => 'needs_confirmation',
        ]);

        $this->actingAs($vita)->post("/sync/orders/{$order->id}/approve", ['sync_note' => 'Vita approved'])->assertRedirect();
        $this->assertDatabaseHas('manual_syncs', [
            'order_id' => $order->id,
            'website_sync_status' => 'pending',
        ]);
        $this->assertDatabaseHas('audit_records', [
            'action' => 'manual_sync.approved',
            'reason' => 'operator_review_approved',
        ]);
        $this->actingAs($vita)->post("/sync/orders/{$order->id}/approve")->assertStatus(409);
    }

    public function test_returned_customer_tracking_correction_reenters_vita_review(): void
    {
        $vita = $this->userWith(['sync.view', 'sync.operate']);
        $order = $this->order('customer');
        $shipments = app(ShipmentService::class);
        $shipments->markShipped($order, $this->shipment('CUSTOMER-TRACK-1'), null);

        $this->actingAs($vita)->post("/sync/orders/{$order->id}/return", ['sync_note' => 'Tracking wrong'])->assertRedirect();
        $this->assertDatabaseHas('manual_syncs', ['order_id' => $order->id, 'website_sync_status' => 'returned']);
        $this->actingAs($vita)->post("/sync/orders/{$order->id}/reopen")->assertStatus(409);

        $shipments->updateTracking($order->fresh(), 'CUSTOMER-TRACK-2', null);
        $this->assertDatabaseHas('manual_syncs', [
            'order_id' => $order->id,
            'website_sync_status' => 'needs_confirmation',
        ]);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'tracking_number' => 'CUSTOMER-TRACK-2',
        ]);
    }

    public function test_kol_fulfillment_preserves_existing_pending_sync_behavior(): void
    {
        $order = $this->order('kol_fulfillment');

        app(ShipmentService::class)->markShipped($order, $this->shipment('KOL-TRACK-1'), null);

        $this->assertDatabaseHas('manual_syncs', [
            'order_id' => $order->id,
            'website_sync_status' => 'pending',
        ]);
        $this->assertDatabaseMissing('manual_syncs', [
            'order_id' => $order->id,
            'website_sync_status' => 'needs_confirmation',
        ]);
    }

    private function order(string $kind): Order
    {
        return Order::create([
            'internal_order_number' => 'M21-'.strtoupper($kind).'-'.uniqid(),
            'erp_order_id' => '007-M21-'.strtoupper(substr($kind, 0, 3)).'-'.uniqid(),
            'source_type' => 'woocommerce',
            'order_kind' => $kind,
            'external_order_number' => 'WC-'.uniqid(),
            'order_date' => '2026-09-08',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
    }

    private function shipment(string $tracking): array
    {
        return [
            'carrier' => 'DHL',
            'service' => 'Express',
            'tracking_number' => $tracking,
            'shipped_at' => '2026-09-08T08:00:00Z',
        ];
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'm21-vita-'.uniqid(), 'label' => 'M2.1 Vita Review']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
