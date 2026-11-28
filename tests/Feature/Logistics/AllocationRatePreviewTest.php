<?php

namespace Tests\Feature\Logistics;

use App\Models\Role;
use App\Models\User;
use App\Modules\Audit\Infrastructure\Models\AuditRecord;
use App\Modules\Finance\Application\FxReferenceSettingsService;
use App\Modules\Inventory\Infrastructure\Models\PackagingMeasurementRecord;
use App\Modules\Logistics\Application\FulfillmentAllocationDecisionService;
use App\Modules\Logistics\Application\FulfillmentPlanPreviewService;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductPackagingProfile;
use App\Modules\Products\Infrastructure\Models\ProductWeightEvidence;
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
        $this->actingAs($u)->getJson('/logistics/orders/'.$missing->id.'/fulfillment-allocation/rate-preview')->assertOk()->assertJsonPath('reason', 'missing_packaging_standard')->assertJsonCount(0, 'rows');
        $o->items()->first()->update(['sku' => 'gift', 'source_sku' => 'gift']);
        $this->actingAs($u)->getJson($url)->assertOk()->assertJsonPath('reason', 'unresolved_product')->assertJsonCount(0, 'rows');
        $this->assertDatabaseCount('fulfillment_allocations', 0);
    }

    public function test_product_weight_fallback_and_missing_dimensions_are_partial_not_official_totals(): void
    {
        $u = $this->actor(['logistics.view']);
        $o = $this->order($u, 'US', false);
        $items = $o->items()->get();
        Product::whereKey($items[0]->product_id)->update(['weight_kg' => 0.5, 'length_cm' => 20, 'width_cm' => 15, 'height_cm' => 5]);
        Product::whereKey($items[1]->product_id)->update(['weight_kg' => 0.75]);
        $v = $this->version($u, 'synthetic-factory');
        $this->rateComponent($v, 'FACTORY', 'US', 'SPEEDA', 'sd_economy_battery', 'transport', 10, 20);
        $url = '/logistics/orders/'.$o->id.'/fulfillment-allocation/rate-preview';
        $r = $this->actingAs($u)->getJson($url)->assertOk()->assertJsonPath('measurement.basis', 'weight_only_reference')->assertJsonPath('measurement.dimensions_cm', null)->json();
        $this->assertEqualsWithDelta(2.1, $r['measurement']['weight_kg'], 0.000001);
        $row = collect($r['rows'])->firstWhere('warehouse_code', 'FACTORY');
        $this->assertSame('weight_only_reference', $row['status']);
        $this->assertEqualsWithDelta(52, $row['amount_cny'], 0.000001);
        $this->assertSame([(int) $items[1]->product_id], $r['missing_product_ids']);
        $this->assertSame('product_weight_plus_20_percent', $r['measurement_evidence'][0]['weight_source']);
        Product::whereKey($items[1]->product_id)->update(['length_cm' => 30, 'width_cm' => 10, 'height_cm' => 6]);
        $r = $this->actingAs($u)->getJson($url)->assertOk()->assertJsonPath('measurement.basis', 'product_standard_estimate')->json();
        $this->assertEquals([30, 15, 16], $r['measurement']['dimensions_cm']);
        $this->assertEqualsWithDelta(2.1, $r['measurement']['weight_kg'], 0.000001);
        $this->assertSame([], $r['missing_product_ids']);
        $this->assertDatabaseCount('fulfillment_allocations', 0);
    }

    public function test_split_cost_confirmation_is_actor_and_plan_bound_and_unknown_cost_is_not_zero(): void
    {
        $u = $this->actor(['logistics.view', 'logistics.operate']);
        $o = $this->order($u);
        $items = $o->items()->orderBy('id')->get();
        $v = $this->version($u, 'synthetic-factory');
        $this->rateComponent($v, 'FACTORY', 'US', 'SPEEDA', 'sd_economy_battery', 'transport', 10, 20);
        $assignments = [['order_item_id' => $items[0]->id, 'source_code' => 'FACTORY'], ['order_item_id' => $items[1]->id, 'source_code' => 'US-LA']];
        $url = '/logistics/orders/'.$o->id.'/fulfillment-allocation';
        $viewer = $this->actor(['logistics.view']);
        $this->actingAs($viewer)->postJson($url.'/cost-preview', ['mode' => 'manual', 'assignments' => $assignments])->assertForbidden();
        $quote = $this->actingAs($u)->postJson($url.'/cost-preview', ['mode' => 'manual', 'assignments' => $assignments])->assertOk()
            ->assertJsonPath('requires_confirmation', true)->assertJsonPath('total_cny', null)->assertJsonPath('unknown_group_count', 1)->json();
        $this->assertEqualsWithDelta(30, $quote['known_subtotal_cny'], 0.001);
        $this->assertEqualsWithDelta(45, $quote['factory_default_reference']['amount_cny'], 0.001);
        $this->assertSame(2, $quote['groups'][0]['items'][0]['quantity']);
        $this->assertNull($quote['groups'][1]['amount_cny']);
        $this->assertDatabaseCount('fulfillment_allocations', 0);
        $this->actingAs($u)->postJson($url.'/manual', ['assignments' => $assignments])->assertUnprocessable()->assertJsonValidationErrors('cost_confirmation');
        $other = $this->actor(['logistics.view', 'logistics.operate']);
        $this->actingAs($other)->postJson($url.'/manual', ['assignments' => $assignments, 'cost_confirmation' => $quote['confirmation_token']])->assertUnprocessable()->assertJsonValidationErrors('cost_confirmation');
        $this->actingAs($u)->postJson($url.'/manual', ['assignments' => [['order_item_id' => $items[0]->id, 'source_code' => 'US-LA'], ['order_item_id' => $items[1]->id, 'source_code' => 'FACTORY']], 'cost_confirmation' => $quote['confirmation_token']])->assertUnprocessable()->assertJsonValidationErrors('cost_confirmation');
        DB::table('logistics_rate_bands')->whereIn('component_id', DB::table('logistics_rate_components')->where('version_id', $v)->pluck('id'))->update(['fixed_amount' => 11]);
        $this->actingAs($u)->postJson($url.'/manual', ['assignments' => $assignments, 'cost_confirmation' => $quote['confirmation_token']])->assertUnprocessable()->assertJsonValidationErrors('cost_confirmation');
        $this->assertDatabaseCount('fulfillment_allocations', 0);
        $quote = $this->actingAs($u)->postJson($url.'/cost-preview', ['mode' => 'manual', 'assignments' => $assignments])->assertOk()->json();
        $this->actingAs($u)->postJson($url.'/manual', ['assignments' => $assignments, 'cost_confirmation' => $quote['confirmation_token']])->assertOk();
        $snapshot = $o->fresh()->fulfillmentAllocation->plan_snapshot['cost_confirmation'];
        $this->assertSame('confirmed', $snapshot['confirmation_status']);
        $this->assertSame($u->id, $snapshot['confirmed_by']);
        $this->assertEqualsWithDelta(31, $snapshot['known_subtotal_cny'], 0.001);
        $this->assertNull($snapshot['total_cny']);
        $this->assertStringContainsString('+08:00', $snapshot['confirmed_at']);
        $this->assertArrayNotHasKey('confirmation_token', $snapshot);
        $this->assertCount(2, $o->fresh()->fulfillmentChildren);
        $audit = AuditRecord::query()
            ->where('entity_id', (string) $o->id)->where('action', 'order.allocation_cost_confirmed')->sole();
        $this->assertSame((int) $u->id, (int) $audit->actor_id);
        $this->assertSame('confirmed', $audit->after['confirmation_status']);
        $this->assertEquals($snapshot, $audit->after);

    }

    public function test_quick_and_batch_share_confirmation_gate_but_missing_weights_allow_submission(): void
    {
        $u = $this->actor(['logistics.view', 'logistics.operate']);
        $o = $this->order($u);
        $unknown = $this->order($u, 'US', false);
        $v = $this->version($u, 'synthetic-factory');
        $this->rateComponent($v, 'FACTORY', 'US', 'SPEEDA', 'sd_economy_battery', 'transport', 10, 20);
        $factory = DB::table('warehouses')->where('code', 'FACTORY')->value('id');
        $assignments = $o->items()->get()->map(fn ($i) => ['order_item_id' => $i->id, 'warehouse_id' => $factory])->all();
        $url = '/logistics/orders/'.$o->id.'/fulfillment-allocation';
        $quote = $this->actingAs($u)->postJson($url.'/cost-preview', ['mode' => 'quick', 'assignments' => $assignments])->assertOk()->json();
        $this->assertTrue($quote['requires_confirmation']);
        $this->actingAs($u)->postJson($url.'/quick', ['assignments' => $assignments])->assertUnprocessable()->assertJsonValidationErrors('cost_confirmation');
        $body = ['order_ids' => [$o->id, $unknown->id], 'action' => 'warehouse', 'warehouse_code' => 'FACTORY', 'confirmed' => true];
        $r = $this->actingAs($u)->postJson('/logistics/wait-stock/batch-allocation/cost-preview', $body)->assertOk()->json('results');
        $this->assertTrue($r[0]['quote']['requires_confirmation']);
        $this->assertFalse($r[1]['quote']['requires_confirmation']);
        $this->actingAs($u)->postJson('/logistics/wait-stock/batch-allocation', $body)->assertOk()->assertJsonPath('succeeded', 1)->assertJsonPath('failed', 1);
        $this->assertNull($o->fresh()->fulfillmentAllocation);
        $this->assertSame('skipped_unavailable', $unknown->fresh()->fulfillmentAllocation->plan_snapshot['cost_confirmation']['confirmation_status']);
        $this->actingAs($u)->postJson($url.'/quick', ['assignments' => $assignments, 'cost_confirmation' => $quote['confirmation_token']])->assertRedirect();
        $this->assertSame('confirmed', $o->fresh()->fulfillmentAllocation->plan_snapshot['cost_confirmation']['confirmation_status']);
    }

    public function test_recommended_and_batch_recommended_use_same_resolved_snapshot(): void
    {
        $u = $this->actor(['logistics.view', 'logistics.operate']);
        $orders = [$this->order($u, 'DE'), $this->order($u, 'DE')];
        $v = $this->version($u, 'synthetic-factory');
        $this->rateComponent($v, 'FACTORY', 'DE', 'SPEEDA', 'sd_economy_battery', 'transport', 10, 20);
        app(FxReferenceSettingsService::class)->save(['USD' => 7.5, 'EUR' => 8], ['USD' => false, 'EUR' => false], null);
        $keys = [];
        foreach ($orders as $o) {
            foreach ($o->items()->get() as $item) {
                $p = Product::findOrFail($item->product_id);
                $e = ProductWeightEvidence::create([
                    'product_id' => $p->id, 'weight_kind' => 'shipping_reference_weight', 'observed_weight_kg' => 0.5,
                    'raw_sku' => $p->sku, 'source_type' => 'manual_entry', 'source_reference' => 'cost-recommended-fixture',
                    'status' => 'adopted', 'reviewed_at' => now(),
                ]);
                $p->forceFill(['shipping_weight_kg' => 0.5, 'shipping_weight_evidence_id' => $e->id,
                    'standard_packaging_cost' => 1, 'cost_currency' => 'CNY'])->save();
            }
            $order = $o->fresh(['items', 'address']);
            $items = $order->items->map(fn ($i) => ['product_id' => $i->product_id, 'quantity' => $i->quantity, 'sku' => $i->sku])->all();
            $preview = app(FulfillmentPlanPreviewService::class)->preview('DE', $items);
            $decision = app(FulfillmentAllocationDecisionService::class)->present($order, $preview);
            $this->assertTrue($decision['can_use_recommendation'], json_encode($decision['manual_reasons']));
            $keys[$o->id] = $decision['recommended_plan_key'];
        }
        [$o,$b] = $orders;
        $url = '/logistics/orders/'.$o->id.'/fulfillment-allocation';
        $body = ['mode' => 'recommended', 'plan_key' => $keys[$o->id]];
        $quote = $this->actingAs($u)->postJson($url.'/cost-preview', $body)->assertOk()->assertJsonPath('requires_confirmation', true)->json();
        $this->assertDatabaseCount('fulfillment_allocations', 0);
        $this->actingAs($u)->postJson($url.'/recommended', ['plan_key' => $keys[$o->id]])->assertUnprocessable()->assertJsonValidationErrors('cost_confirmation');
        $response = $this->actingAs($u)->postJson($url.'/recommended', ['plan_key' => $keys[$o->id], 'cost_confirmation' => $quote['confirmation_token']]);
        $this->assertSame(200, $response->status(), $response->getContent());
        $saved = $o->fresh()->fulfillmentAllocation->plan_snapshot['cost_confirmation'];
        $this->assertSame('confirmed', $saved['confirmation_status']);
        $this->assertEquals($quote['known_subtotal_cny'], $saved['known_subtotal_cny']);
        $body = ['order_ids' => [$b->id], 'action' => 'recommend', 'plan_keys' => [$b->id => $keys[$b->id]], 'confirmed' => true];
        $quote = $this->actingAs($u)->postJson('/logistics/wait-stock/batch-allocation/cost-preview', $body)->assertOk()->assertJsonPath('results.0.ok', true)->json('results.0.quote');
        $this->actingAs($u)->postJson('/logistics/wait-stock/batch-allocation', $body)->assertOk()->assertJsonPath('failed', 1);
        $this->actingAs($u)->postJson('/logistics/wait-stock/batch-allocation', [...$body, 'cost_tokens' => [$b->id => $quote['confirmation_token']]])->assertOk()->assertJsonPath('succeeded', 1);
        $this->assertSame('confirmed', $b->fresh()->fulfillmentAllocation->plan_snapshot['cost_confirmation']['confirmation_status']);
    }
}
