<?php

namespace Tests\Feature\Logistics;

use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Inventory\Infrastructure\UsNyStockClock;
use App\Modules\Logistics\Application\OverseaWarehouseQueueQuery;
use App\Modules\Logistics\Application\Wlwy\CanadaOutboundBatch;
use App\Modules\Logistics\Application\Wlwy\CanadaTrackingRefresh;
use App\Modules\Logistics\Application\Xlwms\UsNyAllocationReservation;
use App\Modules\Logistics\Experience\Jobs\ProcessCanadaOutboundBatch;
use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductWarehouseSku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class CanadaOutboundBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        config(['wlwy.ca_inventory_apply_enabled' => true, 'wlwy.ca_customer_code' => 'CA-FIXTURE',
            'wlwy.ca_batch_read_enabled' => true, 'warehouse_final_sku.require_verified_mapping' => true]);
    }

    private function operator(bool $write = true): User
    {
        $user = User::factory()->create(['is_active' => true, 'permission_mode' => 'role']);
        $role = Role::create(['key' => 'ca-fixture-'.uniqid(), 'label' => 'CA test']);
        foreach ($write ? ['logistics.view', 'logistics.operate'] : ['logistics.view'] as $key) {
            $role->permissions()->firstOrCreate(['key' => $key], ['label' => $key]);
        }
        $user->roles()->attach($role);

        return $user;
    }

    private function order(User $actor, string $suffix, bool $reviewed = true): Order
    {
        $w = Warehouse::where('code', 'CA')->firstOrFail();
        $p = Product::create(['sku' => 'CA-TEST-'.$suffix, 'name' => 'Synthetic SKU', 'is_active' => true]);
        ProductWarehouseSku::create(['product_id' => $p->id, 'warehouse_id' => $w->id, 'sku' => 'CA-PHYSICAL-'.$suffix, 'is_active' => true, 'verified_at' => now()]);
        UsNyStockClock::utc(fn () => WarehouseInventory::create(['warehouse_id' => $w->id, 'product_id' => $p->id,
            'quantity_on_hand' => 25, 'quantity_reserved' => 0, 'provider_available_quantity' => 25, 'provider_stock_synced_at' => now()]));
        $o = Order::create(['internal_order_number' => 'CA-BATCH-'.$suffix, 'erp_order_id' => 'C999'.$suffix.'-007',
            'source_type' => 'manual', 'order_kind' => 'customer', 'order_date' => now()->toDateString(), 'order_status' => 'pending_shipment']);
        $o->address()->create(['recipient_name' => 'Synthetic recipient', 'phone' => '10000000000', 'country' => 'CA', 'province_state' => 'ON',
            'city' => 'Toronto', 'address_line_1' => '1 Test St', 'postal_code' => 'M5V 1A1']);
        $o->items()->create(['product_id' => $p->id, 'sku' => $p->sku, 'product_summary' => 'Synthetic', 'quantity' => 1, 'unit_price' => 10, 'line_revenue' => 10]);
        $s = $o->shipment()->create(['warehouse_id' => $w->id, 'shipment_status' => 'pending', 'warehouse_selection_source' => 'manual', 'carrier' => 'Canada Post']);
        if ($reviewed) {
            $s->overseaReview()->create(['status' => 'approved', 'reviewed_warehouse_id' => $w->id, 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
        }
        DB::transaction(fn () => app(UsNyAllocationReservation::class)->sync($o, $w->id, $actor, 'CA'));
        WarehouseOpenApiConnection::updateOrCreate(['warehouse_id' => $w->id, 'provider' => 'wlwy'], [
            'oms_warehouse_code' => 'CA02', 'app_key_encrypted' => 'synthetic-key', 'app_secret_encrypted' => 'synthetic-secret']);

        return $o->fresh();
    }

    private function start(User $actor, array $orders): string
    {
        $ids = implode(',', array_map(fn ($o) => $o->id, $orders));
        $this->actingAs($actor)->withSession(['_token' => 'ca-test-token'])->withHeader('X-CSRF-TOKEN', 'ca-test-token');
        $p = $this->getJson('/logistics/oversea/ca/wlwy/batch/preview?scope=selected&order_ids='.$ids)
            ->assertOk()->assertJsonPath('count', count($orders))->json();

        return $this->postJson('/logistics/oversea/ca/wlwy/batch/start', [
            'scope' => 'selected', 'order_ids' => $ids, 'fingerprint' => $p['fingerprint'], 'confirmation' => 'CONFIRM_CA_BATCH_'.count($orders)])
            ->assertStatus(202)->json('batch_id');
    }

    private function productionFixture(): void
    {
        $this->app->instance('env', 'production');
        config(['wlwy.ca_dispatch_enabled' => true, 'wlwy.ca_dispatch_owner_approved' => true,
            'wlwy.ca_duplicate_contract_verified' => true, 'wlwy.ca_batch_contract_verified' => true,
            'wlwy.ca_operator_batch_enabled' => true, 'wlwy.ca_min_order_id' => 1]);
    }

    public function test_selected_dev_batch_runs_sequentially_no_provider_io_and_denies_viewer(): void
    {
        $actor = $this->operator();
        $first = $this->order($actor, '01');
        $second = $this->order($actor, '02');
        $other = $this->order($actor, '03');
        Http::fake();
        Queue::fake();
        $id = $this->start($actor, [$first, $second]);
        Queue::assertPushed(ProcessCanadaOutboundBatch::class, fn ($job) => $job->batchId === $id);
        app(CanadaOutboundBatch::class)->drain(1);
        $this->getJson('/logistics/oversea/ca/wlwy/batch/'.$id.'/status')->assertOk()->assertJsonPath('processed', 1);
        app(CanadaOutboundBatch::class)->drain(1);
        $this->getJson('/logistics/oversea/ca/wlwy/batch/'.$id.'/status')->assertOk()->assertJsonPath('status', 'completed')
            ->assertJsonPath('items.0.status', 'simulated')->assertJsonPath('items.1.status', 'simulated');
        $this->assertDatabaseMissing('wlwy_ca_batch_items', ['order_id' => $other->id]);
        $this->actingAs($this->operator(false))->postJson('/logistics/oversea/ca/wlwy/batch/start', [])->assertForbidden();
        $this->getJson('/logistics/oversea/ca/wlwy/batch/'.$id.'/status')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_dev_job_executes_only_its_own_simulation(): void
    {
        $actor = $this->operator();
        $order = $this->order($actor, '31');
        Http::fake();
        Queue::fake();
        $id = $this->start($actor, [$order]);
        (new ProcessCanadaOutboundBatch($id))->handle(app(CanadaOutboundBatch::class));
        $this->assertDatabaseHas('wlwy_ca_batch_items', ['order_id' => $order->id, 'status' => 'simulated']);
        Http::assertNothingSent();
    }

    public function test_fake_live_batch_skips_existing_order_and_recovers_valid_tracking_without_resend(): void
    {
        $actor = $this->operator();
        $duplicate = $this->order($actor, '11');
        $good = $this->order($actor, '12');
        $this->productionFixture();
        $creates = 0;
        $visible = false;
        Http::fake(['https://api.wlwytech.com/router/api/service*' => function (Request $req) use (&$creates, &$visible) {
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
            $b = $req->data();
            $method = $q['method'];
            if ($method === 'fu.wms.sku.getlist') {
                return Http::response(['result' => '1', 'data' => ['skulist' => array_map(fn ($sku) => ['sku_code' => $sku, 'sku_id' => 'SKU-ID', 'customer_code' => 'CA-FIXTURE', 'include_batch' => 'N', 'expired_date' => 'N'], $b['lstsku'])], 'errors' => null]);
            }
            if ($method === 'fu.wms.outbound.create') {
                $creates++;
                $this->assertSame('C99912', $b['ref_no']);
                $this->assertSame('CA02', $b['from_warehouse_code']);
                $this->assertSame('CAPOST', $b['logistics_service_info']['logistics_product_code']);
                $this->assertSame('CA-PHYSICAL-12', $b['oconsignment_sku'][0]['sku_code']);

                return Http::response(['result' => '1', 'data' => ['consignment_no' => 'OMS-12'], 'errors' => null]);
            }
            $ref = $b['ref_no'][0];
            $rows = ($ref === 'C99911' || ($ref === 'C99912' && $visible)) ? [
                ['ref_no' => $ref, 'from_warehouse_code' => 'CA02', 'consignment_no' => $ref === 'C99911' ? 'OMS-11' : 'OMS-12',
                    'status' => 'C', 'logistics_product_code' => 'CAPOST', 'shipping_no' => '1234567890123456']] : [];

            return Http::response(['result' => '1', 'data' => ['consignmentlist' => $rows], 'errors' => null]);
        }]);
        $id = $this->start($actor, [$duplicate, $good]);
        $this->assertTrue(CanadaOutboundBatch::guarded($good->id));
        app(CanadaOutboundBatch::class)->drain();
        $this->assertSame(1, $creates);
        $this->assertDatabaseHas('wlwy_ca_batch_items', ['order_id' => $duplicate->id, 'status' => 'exception', 'result_code' => 'CA_EXISTING_NO_CREATE']);
        $this->assertDatabaseHas('wlwy_ca_batch_items', ['order_id' => $good->id, 'status' => 'accepted']);
        $this->assertNull($good->fresh()->shipment->tracking_number);
        $this->assertSame('exceptions', app(OverseaWarehouseQueueQuery::class)->destination($duplicate->id));
        $visible = true;
        $this->assertSame(1, app(CanadaTrackingRefresh::class)->run()['staged']);
        $this->assertSame('1234567890123456', $good->fresh()->shipment->tracking_number);
        $this->assertSame('pending', $good->fresh()->shipment->shipment_status);
        $this->assertSame('issued', app(OverseaWarehouseQueueQuery::class)->destination($good->id));
        $this->assertSame(0, app(CanadaTrackingRefresh::class)->run()['staged']);
        app(CanadaOutboundBatch::class)->drain();
        $this->assertSame(1, $creates);
    }

    public function test_fake_live_create_timeout_stays_uncertain_and_never_retries(): void
    {
        $actor = $this->operator();
        $o = $this->order($actor, '21');
        $this->productionFixture();
        $creates = 0;
        Http::fake(['https://api.wlwytech.com/router/api/service*' => function (Request $req) use (&$creates) {
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
            if ($q['method'] === 'fu.wms.sku.getlist') {
                return Http::response(['result' => '1', 'data' => ['skulist' => array_map(fn ($sku) => ['sku_code' => $sku, 'sku_id' => 'SKU-ID', 'customer_code' => 'CA-FIXTURE', 'include_batch' => 'N', 'expired_date' => 'N'], $req->data()['lstsku'])], 'errors' => null]);
            }
            if ($q['method'] === 'fu.wms.outbound.create') {
                $creates++;
                throw new ConnectionException('synthetic timeout');
            }

            return Http::response(['result' => '1', 'data' => ['consignmentlist' => []], 'errors' => null]);
        }]);
        $this->start($actor, [$o]);
        app(CanadaOutboundBatch::class)->drain();
        app(CanadaOutboundBatch::class)->drain();
        app(CanadaTrackingRefresh::class)->run();
        $this->assertSame(1, $creates);
        $this->assertDatabaseHas('wlwy_ca_batch_items', ['order_id' => $o->id, 'status' => 'uncertain']);
        $this->assertNull($o->fresh()->shipment->tracking_number);
        $this->assertSame('exceptions', app(OverseaWarehouseQueueQuery::class)->destination($o->id));
        $this->assertSame(0, app(CanadaOutboundBatch::class)->preview([$o->id])['count']);
    }
}
