<?php

namespace Tests\Feature\Logistics;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class LogisticsRateCenterFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $source = DB::table('logistics_rate_source_files')->insertGetId([
            'sha256' => str_repeat('a', 64),
            'original_filename' => 'synthetic-rate-fixture.xlsx',
            'private_storage_path' => 'logistics-rate-source/synthetic-rate-fixture.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'byte_size' => 1024,
        ]);
        $book = DB::table('logistics_rate_books')->insertGetId([
            'key' => 'synthetic-rate-book', 'supplier_name' => 'Synthetic', 'display_name' => 'Fixture',
        ]);
        $version = DB::table('logistics_rate_versions')->insertGetId([
            'book_id' => $book, 'source_file_id' => $source, 'version_number' => 1,
        ]);
        $component = DB::table('logistics_rate_components')->insertGetId([
            'version_id' => $version, 'component_key' => 'fixture.usps.transport.us.zone1',
            'warehouse_code' => 'US-NY', 'carrier' => 'USPS', 'service' => 'ground_advantage',
            'destination_country' => 'US', 'destination_zone' => '1*', 'component_type' => 'transport',
            'currency' => 'USD', 'weight_unit' => 'lb', 'pricing_mode' => 'fixed_band',
            'rule_spec' => json_encode(['basis' => 'conditional_max', 'dim_divisor' => 166, 'volume_threshold' => 1728]),
            'source_sheet' => 'Synthetic', 'source_cells' => 'C6:C6',
        ]);
        $band = DB::table('logistics_rate_bands')->insertGetId([
            'component_id' => $component, 'sequence' => 1, 'min_weight' => '0.000000', 'max_weight' => '0.062500',
            'min_inclusive' => false, 'max_inclusive' => true, 'fixed_amount' => '3.872500',
            'source_cells' => 'A6:C6', 'raw_values' => json_encode(['unit' => 'oz', 'weight' => '1', 'amount' => '3.8725']),
        ]);
        $zone = DB::table('logistics_rate_zones')->insertGetId([
            'version_id' => $version, 'zone_key' => 'fixture.usps.zone.005',
            'warehouse_code' => 'US-NY', 'carrier' => 'USPS', 'service' => 'ground_advantage',
            'destination_country' => 'US', 'postal_match_type' => 'prefix',
            'postal_start' => '005', 'zone_label' => '1*',
            'source_sheet' => 'Synthetic ZIP', 'source_cells' => 'A2:B2',
        ]);

        return compact('source', 'book', 'version', 'component', 'band', 'zone');
    }

    public function test_draft_and_unreviewed_source_values_preserved_without_activation(): void
    {
        $ids = $this->fixture();
        $version = DB::table('logistics_rate_versions')->find($ids['version']);
        $component = DB::table('logistics_rate_components')->find($ids['component']);
        $band = DB::table('logistics_rate_bands')->find($ids['band']);
        $zone = DB::table('logistics_rate_zones')->find($ids['zone']);
        $this->assertSame('draft', $version->status);
        $this->assertNull($version->approved_at);
        $this->assertSame('needs_review', $component->review_status);
        $this->assertSame('needs_review', $zone->review_status);
        $this->assertSame('005', $zone->postal_start);
        $this->assertSame('1*', $zone->zone_label);
        $this->assertSame('A2:B2', $zone->source_cells);
        $this->assertSame('lb', $component->weight_unit);
        $this->assertSame(166, json_decode($component->rule_spec, true)['dim_divisor']);
        $this->assertSame('0.062500', number_format((float) $band->max_weight, 6, '.', ''));
        $this->assertSame('3.872500', number_format((float) $band->fixed_amount, 6, '.', ''));
        $this->assertSame('3.8725', json_decode($band->raw_values, true)['amount']);
        $this->assertSame('A6:C6', $band->source_cells);
        $this->assertSame(0, DB::table('logistics_rate_versions')->where('status', 'approved')->count());
    }

    public function test_duplicate_file_fingerprint_is_rejected(): void
    {
        $this->fixture();
        $this->expectException(QueryException::class);
        DB::table('logistics_rate_source_files')->insert([
            'sha256' => str_repeat('a', 64),
            'original_filename' => 'renamed.xlsx', 'private_storage_path' => 'private/renamed.xlsx',
            'mime_type' => 'application/octet-stream', 'byte_size' => 1024,
        ]);
    }

    public function test_duplicate_book_version_is_rejected_but_new_version_is_allowed(): void
    {
        $ids = $this->fixture();
        DB::table('logistics_rate_versions')->insert([
            'book_id' => $ids['book'], 'source_file_id' => $ids['source'], 'version_number' => 2,
        ]);
        $this->assertSame(2, DB::table('logistics_rate_versions')->where('book_id', $ids['book'])->count());
        $this->expectException(QueryException::class);
        DB::table('logistics_rate_versions')->insert([
            'book_id' => $ids['book'], 'source_file_id' => $ids['source'], 'version_number' => 1,
        ]);
    }

    public function test_referenced_source_cannot_be_deleted(): void
    {
        $ids = $this->fixture();
        $this->expectException(QueryException::class);
        DB::table('logistics_rate_source_files')->where('id', $ids['source'])->delete();
    }

    public function test_referenced_rate_version_cannot_be_deleted(): void
    {
        $ids = $this->fixture();
        $this->expectException(QueryException::class);
        DB::table('logistics_rate_versions')->where('id', $ids['version'])->delete();
    }

    public function test_migration_can_rollback_and_reapply_only_new_tables(): void
    {
        $this->assertSame('testing', app()->environment());
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            $this->markTestSkipped('DDL down/up is only tested against disposable in-memory SQLite; MySQL uses the migration and constraint tests.');
        }
        $migration = require database_path('migrations/2026_11_27_110000_create_logistics_rate_center_foundation.php');
        $tables = ['logistics_rate_zones', 'logistics_rate_bands', 'logistics_rate_components', 'logistics_rate_versions', 'logistics_rate_books', 'logistics_rate_source_files'];
        $migration->down();
        foreach ($tables as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $this->assertTrue(Schema::hasTable('orders'));
        $this->assertTrue(Schema::hasTable('products'));
        $migration->up();
        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
    }
}
