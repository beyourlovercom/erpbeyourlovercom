<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Application\PackagingOptimizationInputReader;
use App\Modules\Inventory\Infrastructure\Models\PackagingMeasurementRecord;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductPackagingProfile;
use App\Modules\Products\Infrastructure\Models\ProductWeightEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PackagingOptimizationInputReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_reader_prefers_adopted_measurement_and_ignores_unadopted_candidate(): void
    {
        $actor = User::factory()->create();
        $p = Product::create(['sku' => 'PACK-OPT-001', 'name' => 'Synthetic packaging test']);
        $approved = PackagingMeasurementRecord::create([
            'product_id' => $p->id, 'status' => 'approved', 'packaging_method_key' => 'carton',
            'packaging_length_cm' => 39, 'packaging_width_cm' => 25.5, 'packaging_height_cm' => 18.7,
            'packaged_weight_kg' => 2.232, 'measured_at' => now(), 'created_by' => $actor->id,
            'reviewed_by' => $actor->id, 'reviewed_at' => now(),
        ]);
        $profile = ProductPackagingProfile::create([
            'product_id' => $p->id, 'preset_key' => 'carton',
            'current_measurement_record_id' => $approved->id, 'is_active' => true,
        ]);
        $candidate = PackagingMeasurementRecord::create([
            'product_id' => $p->id, 'status' => 'submitted', 'packaging_method_key' => 'carton',
            'packaging_length_cm' => 39, 'packaging_width_cm' => 25.5, 'packaging_height_cm' => 13.4,
            'packaged_weight_kg' => 2.1, 'created_by' => $actor->id,
        ]);
        $e = ProductWeightEvidence::create(['product_id' => $p->id, 'weight_kind' => 'shipping_reference_weight',
            'observed_weight_kg' => 3.5, 'source_type' => 'logistics_scan', 'status' => 'adopted']);
        $p->update(['shipping_weight_kg' => 3.5, 'shipping_weight_evidence_id' => $e->id]);

        $r = app(PackagingOptimizationInputReader::class)->forProductIds([$p->id])[$p->id];
        self::assertSame('adopted_packaging_measurement', $r['source']);
        self::assertSame(2.232, $r['weight_kg']);
        self::assertSame(18.7, $r['height_cm']);
        self::assertSame($approved->id, $r['evidence']['measurement_record_id']);
        $this->assertDatabaseHas('product_packaging_profiles', ['id' => $profile->id, 'current_measurement_record_id' => $approved->id]);
        $this->assertDatabaseHas('packaging_measurement_records', ['id' => $candidate->id, 'status' => 'submitted']);
        $this->assertDatabaseHas('products', ['id' => $p->id, 'shipping_weight_kg' => 3.5]);
    }

    public function test_reader_keeps_reference_weight_distinct_from_ambiguous_product_weight(): void
    {
        $p = Product::create(['sku' => 'PACK-OPT-002', 'name' => 'Synthetic weight test']);
        $e = ProductWeightEvidence::create(['product_id' => $p->id, 'weight_kind' => 'shipping_reference_weight',
            'observed_weight_kg' => 2.5, 'source_type' => 'logistics_scan', 'status' => 'adopted']);
        $p->update(['shipping_weight_kg' => 2.5, 'shipping_weight_evidence_id' => $e->id]);
        $unknown = Product::create(['sku' => 'PACK-OPT-003', 'name' => 'Synthetic unknown test', 'weight_kg' => 1.8]);
        $results = app(PackagingOptimizationInputReader::class)->forProductIds([$p->id, $unknown->id]);
        self::assertSame('adopted_shipping_reference', $results[$p->id]['source']);
        self::assertSame(2.5, $results[$p->id]['weight_kg']);
        self::assertSame('missing_dimensions', $results[$p->id]['status']);
        self::assertNull($results[$p->id]['height_cm']);
        self::assertSame('missing_weight', $results[$unknown->id]['status']);
        self::assertNull($results[$unknown->id]['weight_kg']);
    }
}
