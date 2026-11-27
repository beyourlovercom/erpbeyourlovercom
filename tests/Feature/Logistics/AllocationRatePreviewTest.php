<?php

namespace Tests\Feature\Logistics;

use App\Models\Role;
use App\Models\User;
use App\Modules\Finance\Application\FxReferenceSettingsService;
use App\Modules\Inventory\Infrastructure\Models\PackagingMeasurementRecord;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductPackagingProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AllocationRatePreviewTest extends TestCase
{
    use RefreshDatabase;

    private function actor(array $permissions): User
    {
        $role = Role::query()->create(['key' => 'rate-preview-'.uniqid(), 'label' => 'Preview Test']);
        foreach ($permissions as $p) {
            $role->permissions()->firstOrCreate(['key' => $p], ['label' => $p]);
        }
        $u = User::factory()->create(['is_active' => true]);
        $u->roles()->attach($role);

        return $u;
    }

    private function order(User $u, string $country = 'US', bool $measure = true): Order
    {
        $o = Order::query()->create(['internal_order_number' => 'RATE-'.uniqid(), 'erp_order_id' => 'RATE-'.uniqid(),
            'source_type' => 'manual', 'order_kind' => 'customer', 'order_date' => now()->toDateString(), 'order_status' => 'pending_shipment', 'currency' => 'USD', 'owner_id' => $u->id]);
        $o->address()->create(['country' => $country, 'postal_code' => $country === 'US' ? '10001' : '10115', 'recipient_name' => 'Synthetic', 'phone' => '10000000000', 'city' => 'Test', 'address_line_1' => 'Test Road']);
        foreach ([[0.5, 20, 15, 5, 2], [0.75, 30, 10, 6, 1]] as $i => [$kg,$l,$w,$h,$q]) {
            $p = Product::query()->create(['name' => 'Synthetic package '.$i, 'sku' => 'RATE-P-'.uniqid(), 'source_type' => 'manual', 'product_type' => 'simple', 'is_active' => true]);
            $o->items()->create(['product_id' => $p->id, 'sku' => $p->sku, 'product_summary' => $p->name, 'quantity' => $q, 'unit_price' => 10, 'line_revenue' => 10 * $q]);
            if ($measure) {
                $r = PackagingMeasurementRecord::query()->create(['product_id' => $p->id, 'status' => 'approved', 'packaging_method_key' => 'bubble_wrap_cut', 'packaged_weight_kg' => $kg,
                    'packaging_length_cm' => $l, 'packaging_width_cm' => $w, 'packaging_height_cm' => $h, 'measured_at' => now(), 'created_by' => $u->id, 'reviewed_by' => $u->id, 'reviewed_at' => now()]);
                ProductPackagingProfile::query()->create(['product_id' => $p->id, 'preset_key' => 'bubble_wrap_cut', 'is_active' => true, 'current_measurement_record_id' => $r->id]);
            }
        }

        return $o;
    }

    private function version(User $u, string $bookKey, string $status = 'draft', int $number = 1): int
    {
        $book = DB::table('logistics_rate_books')->where('key', $bookKey)->value('id') ?? DB::table('logistics_rate_books')->insertGetId(['key' => $bookKey, 'supplier_name' => 'Synthetic', 'display_name' => 'Test']);
        $source = DB::table('logistics_rate_source_files')->insertGetId(['sha256' => hash('sha256', uniqid()), 'original_filename' => 'synthetic.xlsx',
            'private_storage_path' => 'synthetic.xlsx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'byte_size' => 1]);

        return DB::table('logistics_rate_versions')->insertGetId(['book_id' => $book, 'source_file_id' => $source, 'version_number' => $number, 'status' => $status, 'effective_from' => '2025-01-01',
            'approved_by' => $status === 'approved' ? $u->id : null, 'approved_at' => $status === 'approved' ? now() : null]);
    }

    private function rateComponent(int $v, string $warehouse, string $country, string $carrier, string $service, string $type, float $fixed, ?float $linear = null, bool $approved = false): void
    {
        $id = DB::table('logistics_rate_components')->insertGetId(['version_id' => $v, 'component_key' => strtolower($warehouse.'.'.$service.'.'.$type), 'warehouse_code' => $warehouse,
            'destination_country' => $country, 'carrier' => $carrier, 'service' => $service, 'component_type' => $type, 'currency' => $warehouse === 'FACTORY' ? 'CNY' : ($country === 'DE' ? 'EUR' : 'USD'),
            'weight_unit' => 'kg', 'pricing_mode' => $linear === null ? 'fixed_band' : 'linear_band', 'rule_spec' => json_encode(['basis' => 'max', 'dim_divisor' => 6000, 'volume_unit' => 'cm3_kg', 'coverage_complete' => $approved, 'handling_included' => $warehouse === 'FACTORY']),
            'source_sheet' => 'Synthetic', 'source_cells' => 'A1:D1', 'review_status' => $approved ? 'verified' : 'needs_review']);
        DB::table('logistics_rate_bands')->insert(['component_id' => $id, 'sequence' => 1, 'min_weight' => 0, 'max_weight' => 100, 'min_inclusive' => false, 'max_inclusive' => true, 'fixed_amount' => $fixed,
            'per_weight_amount' => $linear, 'per_extra_item_amount' => $type === 'handling' ? 0.8 : null, 'source_cells' => 'A1:D1']);
    }

    public function test_draft_estimates_sum_measured_items_without_reusing_moreplus_price_or_mutating_order(): void
    {
        $u = $this->actor(['logistics.view']);
        $order = $this->order($u);
        $v = $this->version($u, 'synthetic-us');
        $this->rateComponent($v, 'US-NY', 'US', 'USPS', 'ground_advantage', 'transport', 5);
        $this->rateComponent($v, 'SPEEDA-US-LA', 'US', 'USPS', 'ground_advantage', 'transport', 1);
        $this->rateComponent($v, 'FACTORY', 'US', 'SPEEDA', 'sd_economy_battery', 'transport', 10, 20);
        app(FxReferenceSettingsService::class)->save(['USD' => 7.5], ['USD' => false], null);
        $before = $order->fresh()->getAttributes();
        $url = '/logistics/orders/'.$order->id.'/fulfillment-allocation/rate-preview';
        $this->actingAs($this->actor([]))->getJson($url)->assertForbidden();
        $r = $this->actingAs($u)->getJson($url)->assertOk()->assertJsonPath('eligible_for_ranking', false)
            ->assertJsonPath('measurement.item_count', 3)->assertJsonPath('measurement.weight_kg', 1.75)->json();
        $this->assertEquals([30, 15, 16], $r['measurement']['dimensions_cm']);
        $rows = collect($r['rows'])->keyBy('warehouse_code');
        $this->assertSame('draft_reference', $rows['US-NY']['status']);
        $this->assertEquals(37.5, $rows['US-NY']['amount_cny']);
        $this->assertSame(2, $rows['US-NY']['extra_item_usd']);
        $this->assertSame('rate_scope_missing', $rows['US-LA']['reason']);
        $this->assertNull($rows['US-LA']['amount_cny']);
        $this->assertEquals(45, $rows['FACTORY']['amount_cny']);
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertDatabaseCount('fulfillment_allocations', 0);
        // A deleted draft route must not resurrect an older rate.
        $this->version($u, 'synthetic-us', 'draft', 2);
        $result = $this->actingAs($u)->getJson($url)->assertOk()->json();
        $this->assertSame('rate_scope_missing', collect($result['rows'])->firstWhere('warehouse_code', 'US-NY')['reason']);
    }

    public function test_approved_rate_requires_exact_version_confirmation_and_missing_measurements_block(): void
    {
        $u = $this->actor(['logistics.view']);
        $o = $this->order($u, 'DE');
        $v = $this->version($u, 'synthetic-de', 'approved');
        $this->rateComponent($v, 'DE', 'DE', 'DHL', 'domestic', 'transport', 4, null, true);
        $this->rateComponent($v, 'DE', 'DE', 'DHL', 'domestic', 'handling', 1, null, true);
        app(FxReferenceSettingsService::class)->save(['USD' => 7.5, 'EUR' => 8], ['USD' => false, 'EUR' => false], null);
        $url = '/logistics/orders/'.$o->id.'/fulfillment-allocation/rate-preview';
        $this->actingAs($u)->getJson($url)->assertOk()->assertJsonPath('rows.0.reason', 'eligibility_unconfirmed');
        $this->actingAs($u)->getJson($url.'?confirmed_scope='.urlencode('DE|DHL|domestic|999'))->assertOk()->assertJsonPath('rows.0.reason', 'eligibility_unconfirmed');
        $r = $this->actingAs($u)->getJson($url.'?confirmed_scope='.urlencode('DE|DHL|domestic|'.$v))->assertOk()->assertJsonPath('rows.0.status', 'approved_reference')->assertJsonPath('eligible_for_ranking', false)->json();
        $this->assertEquals(55, $r['rows'][0]['amount_cny']); // EUR 5 + USD 2; no double charge
        $missing = $this->order($u, 'DE', false);
        $this->actingAs($u)->getJson('/logistics/orders/'.$missing->id.'/fulfillment-allocation/rate-preview')->assertOk()->assertJsonPath('reason', 'missing_packaging_standard')->assertJsonCount(0,'rows');
        $o->items()->first()->update(['sku' => 'gift', 'source_sku' => 'gift']);
        $this->actingAs($u)->getJson($url)->assertOk()->assertJsonPath('reason','unresolved_product')->assertJsonCount(0,'rows');
        $this->assertDatabaseCount('fulfillment_allocations',0);
    }
}
