<?php

namespace Tests\Feature\Logistics;

use App\Modules\Inventory\Application\InventoryAvailabilityProjection;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Logistics\Application\Wlwy\CanadaPhysicalStockSync;
use App\Modules\Logistics\Application\Xlwms\UsNyAllocationReservation;
use App\Modules\Logistics\Infrastructure\Wlwy\CanadaReadGateway;
use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductWarehouseSku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class CanadaPhysicalStockSyncTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        config(['wlwy.ca_inventory_apply_enabled' => true, 'wlwy.ca_customer_code' => 'CA-FIXTURE']);
        $ca = Warehouse::where('code', 'CA')->firstOrFail();
        $p = Product::create(['sku' => 'CA-CANONICAL', 'name' => 'Synthetic product', 'is_active' => true]);
        ProductWarehouseSku::create(['warehouse_id' => $ca->id, 'product_id' => $p->id,
            'sku' => 'CA-PHYSICAL', 'is_active' => true, 'verified_at' => now()]);
        $connection = WarehouseOpenApiConnection::create(['warehouse_id' => $ca->id, 'provider' => 'wlwy',
            'oms_warehouse_code' => 'CA02', 'app_key_encrypted' => 'synthetic-key', 'app_secret_encrypted' => 'synthetic-secret']);

        return [$ca, $p, $connection];
    }

    private function row(string $sku, string $id, int $physical, int $available, string $batch = '', string $quality = 'G'): array
    {
        return ['customer_code' => 'CA-FIXTURE', 'warehouse_code' => 'CA02', 'sku_code' => $sku,
            'sku_id' => $id, 'batch_no' => $batch, 'stock_quality' => $quality,
            'warehouse_stock' => $physical, 'available_stock' => $available];
    }

    private function response(array $rows, ?int $total = null): array
    {
        return ['result' => '1', 'data' => ['page_no' => 1, 'page_size' => 50, 'total' => $total ?? count($rows), 'inventorylist' => $rows], 'errors' => null];
    }

    public function test_complete_signed_stock_quarantine_and_reservation_refresh(): void
    {
        [$ca, $p] = $this->fixture();
        $rows = [$this->row('CA-PHYSICAL', '1', 10, 8, 'A'), $this->row('CA-PHYSICAL', '1', 5, 5, 'B'),
            $this->row('CA-PHYSICAL', '1', 3, 0, 'A', 'E'),
            $this->row('UNKNOWN-POSITIVE', '2', 99, 99), $this->row('UNKNOWN-ZERO', '3', 0, 0)];
        Http::preventStrayRequests();
        Http::fake(['https://api.wlwytech.com/router/api/service*' => function (Request $r) use ($rows) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            $this->assertSame('fu.wms.inventory.get', $q['method']);
            $this->assertSame('CA02', $r->data()['warehouse_code']);
            $expected = md5('app_keysynthetic-keyformatjsonmethod'.$q['method'].'timestamp'.$q['timestamp'].'v1.0'.$r->body().'synthetic-secret');
            $this->assertSame($expected, $q['sign']);
            $this->assertMatchesRegularExpression('/^[0-9]{13}$/D', $q['timestamp']);

            return Http::response($this->response($rows));
        }]);
        $svc = app(CanadaPhysicalStockSync::class);
        $dry = $svc->run();
        $this->assertCount(1, $dry['verified']);
        $this->assertCount(2, $dry['quarantine']);
        $this->assertSame(3, $dry['non_good']);
        $this->assertSame(0, WarehouseInventory::where('warehouse_id', $ca->id)->count());
        $svc->run(true);
        $stock = WarehouseInventory::where('warehouse_id', $ca->id)->firstOrFail();
        $this->assertSame(15, $stock->quantity_on_hand);
        $this->assertSame(13, $stock->provider_available_quantity);
        $order = Order::create(['internal_order_number' => 'CA-TEST-1', 'source_type' => 'manual',
            'order_kind' => 'customer', 'order_date' => now()->toDateString(), 'order_status' => 'pending_shipment']);
        $order->items()->create(['product_id' => $p->id, 'sku' => 'CA-PHYSICAL', 'product_summary' => 'CA',
            'quantity' => 1, 'unit_price' => '1.00', 'line_revenue' => '1.00']);
        $gate = app(UsNyAllocationReservation::class);
        \DB::transaction(fn () => $gate->sync($order, $ca->id, null, 'CA'));
        \DB::transaction(fn () => $gate->sync($order, $ca->id, null, 'CA'));
        $this->assertSame(1, $stock->fresh()->quantity_reserved);
        $svc->run(true);
        $this->assertSame(1, $stock->fresh()->quantity_reserved);
        $projection = app(InventoryAvailabilityProjection::class);
        $this->assertSame(12, $projection->forRows(collect([$stock->fresh()]))[$stock->id]);
        $this->assertSame([], $gate->dispatchBlockers($order, 'CA'));
        \DB::transaction(fn () => $gate->sync($order, 0, null, 'CA'));
        $this->assertSame(0, $stock->fresh()->quantity_reserved);
        $this->assertSame(13, $projection->forRows(collect([$stock->fresh()]))[$stock->id]);
    }

    public function test_truncated_read_revokes_only_ca_proof_and_preserves_counts(): void
    {
        [$ca, $p] = $this->fixture();
        $ny = Warehouse::where('code', 'US-NY')->firstOrFail();
        foreach ([$ca, $ny] as $w) {
            WarehouseInventory::create(['warehouse_id' => $w->id, 'product_id' => $p->id,
                'quantity_on_hand' => 10, 'quantity_reserved' => 2, 'provider_available_quantity' => 8, 'provider_stock_synced_at' => now()]);
        }
        Http::preventStrayRequests();
        Http::fake(['https://api.wlwytech.com/router/api/service*' => Http::response($this->response([$this->row('CA-PHYSICAL', '1', 10, 8)], 2))]);
        try {
            app(CanadaPhysicalStockSync::class)->run(true);
            $this->fail('Expected incomplete source to fail closed');
        } catch (RuntimeException $e) {
            $this->assertSame('CA_INCOMPLETE_PAGINATION', $e->getMessage());
        }
        $stock = WarehouseInventory::where('warehouse_id', $ca->id)->firstOrFail();
        $this->assertNull($stock->provider_stock_synced_at);
        $this->assertSame(10, $stock->quantity_on_hand);
        $this->assertSame(2, $stock->quantity_reserved);
        $this->assertNotNull(WarehouseInventory::where('warehouse_id', $ny->id)->firstOrFail()->provider_stock_synced_at);
    }

    public function test_exact_reference_status_rejects_other_warehouse_and_create_is_not_allowlisted(): void
    {
        [, , $connection] = $this->fixture();
        Http::preventStrayRequests();
        Http::fake(['https://api.wlwytech.com/router/api/service*' => Http::response(['result' => '1', 'data' => ['consignmentlist' => [['ref_no' => 'REF-1', 'consignment_no' => 'OMS-1', 'from_warehouse_code' => 'OTHER']]]])]);
        $gateway = app(CanadaReadGateway::class);
        try {
            $gateway->exactStatus($connection, 'REF-1');
            $this->fail('Expected warehouse mismatch');
        } catch (RuntimeException $e) {
            $this->assertSame('CA_STATUS_IDENTITY_CONFLICT', $e->getMessage());
        }
        $this->expectExceptionMessage('CA_READ_SCOPE_INVALID');
        $gateway->read($connection, 'fu.wms.outbound.create', []);
    }
}
