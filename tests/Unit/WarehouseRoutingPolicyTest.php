<?php

namespace Tests\Unit;

use App\Modules\Logistics\Application\WarehouseRoutingPolicy;
use Tests\TestCase;

final class WarehouseRoutingPolicyTest extends TestCase
{
    public function test_default_policy_keeps_de_automatic_and_us_assisted_only(): void
    {
        $policy = app(WarehouseRoutingPolicy::class);

        $this->assertSame(['DE'], $policy->automaticCountries());
        $this->assertTrue($policy->automaticEnabled('de'));
        $this->assertSame('fixed', $policy->strategy('DE'));
        $this->assertSame(['DE'], $policy->warehouseCodes('DE'));
        $this->assertSame('DE', $policy->fixedWarehouseCode('DE'));

        $this->assertFalse($policy->automaticEnabled('US'));
        $this->assertSame('east_zone_then_priority', $policy->strategy('us'));
        $this->assertSame(['US-NY', 'US-SJ', 'US-LA'], $policy->warehouseCodes('US'));
        $this->assertSame(['US-SJ', 'US-LA', 'US-NY'], $policy->fallbackPriority('US'));
        $this->assertNull($policy->fixedWarehouseCode('US'));
    }

    public function test_warehouse_aliases_normalize_to_three_canonical_us_codes(): void
    {
        $policy = app(WarehouseRoutingPolicy::class);
        $this->assertSame('US-NY', $policy->canonicalWarehouseCode('US-NJ'));
        $this->assertSame('US-NY', $policy->canonicalWarehouseCode('美东仓'));
        $this->assertSame('US-LA', $policy->canonicalWarehouseCode('MorePlus'));
        $this->assertSame('US-SJ', $policy->canonicalWarehouseCode('SMGJ'));
        $this->assertSame('US-SJ', $policy->canonicalWarehouseCode('世纪仓'));
        $this->assertSame('US-SJ', $policy->canonicalWarehouseCode('世迈国际仓'));
    }

    public function test_runtime_config_contains_no_business_routing_rules(): void
    {
        $runtime = config('inventory_auto_allocation');
        $this->assertArrayNotHasKey('country_allowlist', $runtime);
        $this->assertArrayNotHasKey('warehouse_by_country', $runtime);
    }
}
