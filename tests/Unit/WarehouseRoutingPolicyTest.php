<?php

namespace Tests\Unit;

use App\Modules\Logistics\Application\WarehouseRoutingPolicy;
use Tests\TestCase;

final class WarehouseRoutingPolicyTest extends TestCase
{
    public function test_default_policy_keeps_de_automatic_and_us_in_discovery(): void
    {
        $policy = app(WarehouseRoutingPolicy::class);

        $this->assertSame(['DE'], $policy->automaticCountries());
        $this->assertTrue($policy->automaticEnabled('de'));
        $this->assertSame('fixed', $policy->strategy('DE'));
        $this->assertSame(['DE'], $policy->warehouseCodes('DE'));
        $this->assertSame('DE', $policy->fixedWarehouseCode('DE'));

        $this->assertFalse($policy->automaticEnabled('US'));
        $this->assertSame('discovery', $policy->strategy('us'));
        $this->assertSame(['US-LA', 'US-NY', 'SMGJ'], $policy->warehouseCodes('US'));
        $this->assertNull($policy->fixedWarehouseCode('US'));
    }

    public function test_runtime_config_contains_no_business_routing_rules(): void
    {
        $runtime = config('inventory_auto_allocation');
        $this->assertArrayNotHasKey('country_allowlist', $runtime);
        $this->assertArrayNotHasKey('warehouse_by_country', $runtime);
    }
}
