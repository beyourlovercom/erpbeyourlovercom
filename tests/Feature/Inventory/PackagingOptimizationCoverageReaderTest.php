<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Application\PackagingOptimizationCoverageReader;
use App\Modules\Inventory\Infrastructure\Models\PackagingMeasurementRecord;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductPackagingProfile;
use App\Modules\Products\Infrastructure\Models\ProductWeightEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PackagingOptimizationCoverageReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalogue_coverage_distinguishes_missing_evidence_from_workflow_and_excludes_retired_skus(): void
    {
        $actor = User::factory()->create();
        $make = fn (string $sku, array $fields = []) => Product::create([
            'sku' => $sku, 'name' => 'Synthetic '.$sku, 'is_active' => true, ...$fields,
        ]);
        $complete = $make('COVER-COMPLETE', ['material_full_name' => 'Synthetic material']);
        $reference = $make('COVER-REFERENCE');
        $unmeasured = $make('COVER-UNMEASURED', ['weight_kg' => 1.8]);
        $inactive = $make('COVER-INACTIVE', ['is_active' => false]);
        $excluded = $make('00w-COVER');
        $record = PackagingMeasurementRecord::create([
            'product_id' => $complete->id, 'status' => 'approved', 'packaging_method_key' => 'carton',
            'packaging_length_cm' => 39, 'packaging_width_cm' => 25.5, 'packaging_height_cm' => 18.7,
            'packaged_weight_kg' => 2.232, 'measured_at' => now(), 'created_by' => $actor->id,
        ]);
        ProductPackagingProfile::create(['product_id' => $complete->id, 'preset_key' => 'carton',
            'current_measurement_record_id' => $record->id, 'is_active' => true]);
        $evidence = ProductWeightEvidence::create(['product_id' => $reference->id, 'weight_kind' => 'shipping_reference_weight',
            'observed_weight_kg' => 2.5, 'source_type' => 'logistics_scan', 'status' => 'adopted']);
        $reference->update(['shipping_weight_kg' => 2.5, 'shipping_weight_evidence_id' => $evidence->id]);
        foreach (['submitted', 'rejected', 'approved'] as $status) {
            PackagingMeasurementRecord::create([
                'product_id' => $unmeasured->id, 'status' => $status, 'packaging_method_key' => 'carton',
                'created_by' => $actor->id, 'packaged_weight_kg' => 1.9,
                'packaging_length_cm' => 10, 'packaging_width_cm' => 10, 'packaging_height_cm' => 10,
            ]);
        }

        $reader = app(PackagingOptimizationCoverageReader::class);
        $rows = $reader->forProductIds([$complete->id, $reference->id, $unmeasured->id, $inactive->id, $excluded->id, 999999, $complete->id]);
        self::assertCount(3, $rows);
        self::assertSame('complete_adopted_standard', $rows[$complete->id]['coverage_status']);
        self::assertSame('Synthetic material', $rows[$complete->id]['material_name']);
        self::assertFalse($rows[$complete->id]['needs_measurement']);
        self::assertSame('missing_dimensions', $rows[$reference->id]['coverage_status']);
        self::assertSame(2.5, $rows[$reference->id]['input']['weight_kg']);
        self::assertTrue($rows[$reference->id]['needs_measurement']);
        self::assertSame('missing_weight', $rows[$unmeasured->id]['coverage_status']);
        self::assertNull($rows[$unmeasured->id]['input']['weight_kg']);
        self::assertSame(['draft'=>0,'submitted'=>1,'approved'=>1,'rejected'=>1], $rows[$unmeasured->id]['measurement_counts']);
        self::assertTrue($rows[$unmeasured->id]['awaiting_review']);
        self::assertTrue($rows[$unmeasured->id]['needs_measurement']);
        self::assertSame('awaiting_a4', $rows[$complete->id]['price_status']);
        self::assertSame([], $reader->forProductIds([]));
        self::assertSame([], $reader->forProductIds([$inactive->id, $excluded->id]));
        $this->assertDatabaseCount('packaging_measurement_records', 4);
        $this->assertDatabaseCount('product_packaging_profiles', 1);
    }
}
