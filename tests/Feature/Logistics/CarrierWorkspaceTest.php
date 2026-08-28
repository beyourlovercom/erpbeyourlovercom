<?php

namespace Tests\Feature\Logistics;

use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class CarrierWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_carrier_index_and_detail_are_aggregated_from_canonical_shipments(): void
    {
        $user = $this->userWith(['logistics.view']);
        $this->shipment($user, 'DHL', 'DHL-1', 2.500, 18.50, 'USD', 'delivered');
        $this->shipment($user, 'DHL', 'DHL-2', 1.250, 11.50, 'USD', 'in_transit');
        $this->shipment($user, 'UPS', 'UPS-1', 3.000, 20.00, 'USD', null);

        $shipmentCount = Shipment::query()->count();

        $this->actingAs($user)->get('/logistics/carriers')->assertInertia(
            fn (Assert $page) => $page
                ->component('Logistics/Carriers/Index')
                ->has('carriers', 2)
                ->where('carriers.0.name', 'DHL')
                ->where('carriers.0.shipment_count', 2)
                ->where('carriers.0.chargeable_weight_kg', 3.75)
                ->where('carriers.0.costs_by_currency.0.currency', 'USD')
                ->where('carriers.0.costs_by_currency.0.total', 30)
        );

        $this->actingAs($user)->get('/logistics/carriers/DHL')->assertInertia(
            fn (Assert $page) => $page
                ->component('Logistics/Carriers/Show')
                ->where('carrier', 'DHL')
                ->where('summary.shipment_count', 2)
                ->where('summary.delivered_count', 1)
                ->where('summary.in_transit_count', 1)
                ->where('shipments.total', 2)
                ->where('shipments.data.0.carrier', 'DHL')
                ->where('shipments.data.1.carrier', 'DHL')
        );

        $this->assertSame($shipmentCount, Shipment::query()->count(), 'Carrier workspace reads canonical shipments and must not create a second shipment truth.');
    }

    public function test_billing_extension_keeps_cost_and_raw_evidence_without_copying_shipment_identity(): void
    {
        $user = $this->userWith(['logistics.view']);
        $shipment = $this->shipment($user, 'DHL', 'DHL-EVIDENCE', 4.125, 33.75, 'USD', 'exception');

        $this->assertDatabaseHas('shipment_carrier_billing', [
            'shipment_id' => $shipment->id,
            'billing_document_number' => 'BILL-DHL-EVIDENCE',
            'data_source' => 'carrier_bill',
        ]);
        $this->assertFalse(Schema::hasColumn('shipment_carrier_billing', 'order_id'));
        $this->assertFalse(Schema::hasColumn('shipment_carrier_billing', 'tracking_number'));
        $this->assertFalse(Schema::hasColumn('shipment_carrier_billing', 'shipment_status'));

        $this->actingAs($user)->get('/logistics/carriers/DHL')->assertInertia(
            fn (Assert $page) => $page
                ->where('shipments.data.0.tracking_number', 'DHL-EVIDENCE')
                ->where('shipments.data.0.billing_document_number', 'BILL-DHL-EVIDENCE')
                ->where('shipments.data.0.raw_evidence.discount_amount', 3.25)
        );
    }

    public function test_carrier_workspace_requires_logistics_view_permission(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user)->get('/logistics/carriers')->assertForbidden();
        $this->actingAs($user)->get('/logistics/carriers/DHL')->assertForbidden();
    }

    private function shipment(User $user, string $carrier, string $tracking, float $weight, float $cost, string $currency, ?string $deliveryStatus): Shipment
    {
        $warehouse = Warehouse::query()->where('is_active', true)->orderBy('priority')->firstOrFail();
        $order = Order::query()->create([
            'internal_order_number' => 'ERP-'.$tracking,
            'source_type' => 'manual',
            'order_kind' => 'customer',
            'external_order_number' => 'ORDER-'.$tracking,
            'order_date' => '2026-08-28',
            'owner_id' => $user->id,
            'order_status' => 'shipped',
            'currency' => $currency,
        ]);
        $order->address()->create(['recipient_name' => 'Receiver '.$tracking, 'country' => 'US']);
        $order->items()->create(['product_summary' => 'Carrier Test Product', 'sku' => 'SKU-'.$tracking, 'quantity' => 1, 'unit_price' => '10.00', 'line_revenue' => '10.00']);

        $shipment = $order->shipment()->create([
            'warehouse_id' => $warehouse->id,
            'shipment_status' => 'shipped',
            'carrier' => $carrier,
            'service' => 'Express',
            'tracking_number' => $tracking,
            'shipped_at' => now(),
            'handled_by' => $user->id,
        ]);
        $shipment->billingEvidence()->create([
            'chargeable_weight_kg' => $weight,
            'actual_cost' => $cost,
            'currency' => $currency,
            'billing_document_number' => 'BILL-'.$tracking,
            'delivery_status' => $deliveryStatus,
            'data_source' => 'carrier_bill',
            'raw_evidence' => ['discount_amount' => 3.25, 'provider_field' => 'preserved'],
        ]);

        return $shipment;
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'carrier-'.uniqid(), 'label' => 'Carrier Workspace']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
