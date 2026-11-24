<?php

namespace Tests\Feature;

use App\Support\Countries\CountryBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CountryBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_apply_preserves_unknown_and_is_idempotent(): void
    {
        // Use marketing country fields to exercise conversion without touching real orders or external systems.
        $service = new CountryBackfill;
        // Use a deliberately raw historical fixture so model mutators cannot normalize it before migration.
        $columns = ['display_name' => 'Synthetic country migration', 'status' => 'active', 'relationship_stage' => 'prospect',
            'country_code' => 'Norway', 'shipping_country' => 'EU', 'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00'];
        $id = DB::table('kols')->insertGetId($columns);
        $plan = $service->plan();
        $this->assertSame(1, $plan['summary']['kols.country_code']['convert']);
        $this->assertSame(1, $plan['summary']['kols.shipping_country']['unresolved']);
        $this->assertSame('Norway', DB::table('kols')->where('id', $id)->value('country_code'));
        $service->apply($plan['fingerprint']);
        $row = DB::table('kols')->where('id', $id)->first();
        $this->assertSame('NO', $row->country_code);
        $this->assertSame('EU', $row->shipping_country);
        $this->assertSame('2026-01-01 00:00:00', $row->updated_at);
        $this->assertDatabaseHas('audit_records', ['action'=>'country.standardized', 'entity_type'=>'kols','entity_id'=>(string)$id]);
        $this->assertSame([], $service->plan()['changes']);
    }
}
