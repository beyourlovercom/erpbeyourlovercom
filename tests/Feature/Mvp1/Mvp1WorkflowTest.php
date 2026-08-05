<?php

namespace Tests\Feature\Mvp1;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Finance\Application\OrderAnalysisService;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\ManualSyncStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class Mvp1WorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_order_fulfillment_and_sync_are_atomic_and_audited(): void
    {
        $user = $this->userWith(['dashboard.view', 'orders.view', 'orders.create', 'orders.edit', 'logistics.view', 'logistics.operate', 'sync.view', 'sync.operate']);
        $payload = $this->orderPayload();

        $response = $this->actingAs($user)->post('/customers/orders', $payload);
        $order = Order::query()->firstOrFail();

        $response->assertRedirect('/customers/orders/'.$order->id);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'source_type' => 'website', 'order_status' => 'pending_shipment']);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'quantity' => 2, 'line_revenue' => '39.98']);
        $this->assertDatabaseHas('audit_records', ['action' => 'order.created', 'entity_id' => (string) $order->id]);

        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/ship', [
            'carrier' => 'Synthetic Carrier', 'tracking_number' => 'SYNTH-001', 'shipped_at' => '2026-08-05T08:00:00Z',
        ])->assertRedirect();
        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/ship', [
            'carrier' => 'Synthetic Carrier', 'tracking_number' => 'SYNTH-002', 'shipped_at' => '2026-08-05T09:00:00Z',
        ])->assertRedirect();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'order_status' => 'shipped']);
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'tracking_number' => 'SYNTH-002']);
        $this->assertDatabaseCount('manual_syncs', 1);
        $this->assertDatabaseHas('manual_syncs', ['order_id' => $order->id, 'website_sync_status' => 'pending']);

        $this->actingAs($user)->post('/sync/orders/'.$order->id.'/complete', ['sync_note' => 'Synthetic external update'])->assertRedirect();
        $this->assertDatabaseHas('manual_syncs', ['order_id' => $order->id, 'website_sync_status' => 'completed', 'synced_by' => $user->id]);
        $this->assertDatabaseHas('audit_records', ['action' => 'manual_sync.completed']);

        $this->actingAs($user)->post('/sync/orders/'.$order->id.'/reopen', ['sync_note' => 'Correction'])->assertRedirect();
        $this->assertDatabaseHas('manual_syncs', ['order_id' => $order->id, 'website_sync_status' => 'pending', 'synced_by' => null]);
        $this->actingAs($user)->post('/sync/orders/'.$order->id.'/return', ['sync_note' => 'Returned for correction'])->assertRedirect();
        $this->assertDatabaseHas('manual_syncs', ['order_id' => $order->id, 'website_sync_status' => 'returned']);
        $this->assertDatabaseHas('audit_records', ['action' => 'manual_sync.reopened']);
        $this->assertDatabaseHas('audit_records', ['action' => 'manual_sync.returned']);
    }

    public function test_order_correction_updates_items_and_address_in_one_audited_request(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create', 'orders.edit']);
        $this->actingAs($user)->post('/customers/orders', $this->orderPayload())->assertRedirect();
        $order = Order::query()->firstOrFail();

        $this->actingAs($user)->patch('/customers/orders/'.$order->id, [
            'recipient_name' => 'Corrected Recipient',
            'address_line_1' => 'Corrected Address',
            'items' => [['product_summary' => 'Corrected Product', 'sku' => 'COR-1', 'quantity' => 3, 'unit_price' => '12.00']],
        ])->assertRedirect();

        $order->refresh()->load(['address', 'items']);
        $this->assertSame('Corrected Recipient', $order->address->recipient_name);
        $this->assertSame('Corrected Product', $order->items->first()->product_summary);
        $this->assertSame(3, $order->items->first()->quantity);
        $this->assertSame('36.00', $order->items->first()->line_revenue);
        $this->assertDatabaseHas('audit_records', ['action' => 'order.updated', 'entity_id' => (string) $order->id]);
    }

    public function test_analysis_requires_all_costs_before_confirming_profit_and_exports(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create', 'analysis.view']);
        $this->actingAs($user)->post('/customers/orders', $this->orderPayload())->assertRedirect();
        $order = Order::query()->firstOrFail();
        $this->actingAs($user)->get('/accounting/order-costs')->assertInertia(fn (Assert $page) => $page->where('orders.0.summary.profit_status', 'not_confirmed')->where('orders.0.summary.completeness', 'incomplete'));

        $costs = array_fill_keys(['product_cost', 'warehouse_cost', 'first_mile_cost', 'overseas_handling_cost', 'last_mile_cost', 'kol_commission', 'payment_fee', 'customs_tax', 'refund_replacement_loss', 'other_variable_cost'], '1.00');
        $this->actingAs($user)->put('/accounting/order-costs/'.$order->id, ['amount_paid' => '100.00', 'refund_amount' => '0.00', ...$costs])->assertRedirect();
        $this->actingAs($user)->get('/accounting/order-costs')->assertInertia(fn (Assert $page) => $page->where('orders.0.summary.profit_status', 'confirmed')->where('orders.0.summary.contribution_profit', '90.00'));
        $this->actingAs($user)->get('/accounting/order-costs/export')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_restricted_role_cannot_mutate_orders_fulfillment_or_sync(): void
    {
        $restricted = $this->userWith(['orders.view']);
        $this->actingAs($restricted)->get('/customers/orders')->assertOk();
        $this->actingAs($restricted)->post('/customers/orders', $this->orderPayload())->assertForbidden();
        $this->actingAs($restricted)->get('/logistics/pending')->assertForbidden();
        $this->actingAs($restricted)->get('/sync')->assertForbidden();
    }

    private function orderPayload(): array
    {
        return [
            'source_type' => 'website', 'external_order_number' => 'SYNTH-WEB-001', 'order_date' => '2026-08-05',
            'recipient_name' => 'Synthetic Recipient', 'phone' => '+1 555 0100', 'country' => 'US',
            'province_state' => 'CA', 'city' => 'Los Angeles', 'address_line_1' => '100 Synthetic Street',
            'postal_code' => '90001', 'items' => [
                ['product_summary' => 'Synthetic Product', 'sku' => 'SYN-1', 'quantity' => 2, 'unit_price' => '19.99'],
            ],
        ];
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'mvp1-'.uniqid(), 'label' => 'MVP1 Test Role']);
        foreach ($permissions as $permission) {
            $role->permissions()->create(['key' => $permission, 'label' => $permission]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
