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

        $this->assertFalse($policy->automaticEnabled('CA'));
        $this->assertSame('fixed', $policy->strategy('CA'));
        $this->assertSame(['CA'], $policy->warehouseCodes('CA'));
        $this->assertSame('CA', $policy->fixedWarehouseCode('CA'));

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
        $this->assertSame('CA', $policy->canonicalWarehouseCode('CA02'));
        $this->assertSame('CA', $policy->canonicalWarehouseCode('加拿大仓'));
    }

    public function test_canada_carrier_policy_is_canada_post_only(): void
    {
        $ca = config('carrier_routing.countries.CA');
        $this->assertTrue($ca['automatic']);
        $this->assertSame('fixed_by_warehouse', $ca['strategy']);
        $this->assertSame('Canada Post', $ca['warehouses']['CA']['carrier']);
        $this->assertSame([], $ca['manual_overrides']);
        $this->assertFalse($ca['auto_rank_carriers']);
    }


    public function test_germany_three_tier_policy_is_explicit_and_non_de_countries_stay_assisted_only(): void
    {
        $policy = app(WarehouseRoutingPolicy::class);

        foreach (['CZ', 'PL', 'SK', 'HU', 'SI', 'AT', 'NL', 'BE'] as $country) {
            $this->assertFalse($policy->automaticEnabled($country));
            $this->assertSame('de_preferred', $policy->strategy($country));
            $this->assertSame(['DE'], $policy->warehouseCodes($country));
        }

        foreach (['FR', 'ES', 'IT', 'PT', 'GR', 'EE'] as $country) {
            $this->assertFalse($policy->automaticEnabled($country));
            $this->assertSame('de_cost_gate', $policy->strategy($country));
            $this->assertSame(['DE'], $policy->warehouseCodes($country));
            $this->assertSame(1.0, (float) $policy->deCostGate($country)['per_order_handling_cost_eur']);
        }

        foreach (['DK', 'FI', 'SE'] as $country) {
            $this->assertFalse($policy->automaticEnabled($country));
            $this->assertSame('blocked', $policy->strategy($country));
            $this->assertNotNull($policy->blockedReason($country));
        }
    }

    public function test_germany_alias_and_dhl_only_carrier_policy_are_frozen(): void
    {
        $policy = app(WarehouseRoutingPolicy::class);
        $this->assertSame('DE', $policy->canonicalWarehouseCode('天运德国'));
        $this->assertSame('DE', $policy->canonicalWarehouseCode('天运仓'));

        foreach (['DE', 'CZ', 'PL', 'SK', 'HU', 'SI', 'AT', 'NL', 'BE', 'FR', 'ES', 'IT', 'PT', 'GR', 'EE'] as $country) {
            $carrier = config("carrier_routing.countries.{$country}");
            $this->assertSame('DHL', $carrier['warehouses']['DE']['carrier']);
            $this->assertSame([], $carrier['manual_overrides']);
            $this->assertFalse($carrier['compare_price']);
        }
    }

    public function test_global_policy_allows_split_preview_but_never_automatic_split(): void
    {
        $policy = app(WarehouseRoutingPolicy::class);
        $this->assertTrue($policy->splitShipmentAllowed());
        $this->assertFalse($policy->automaticSplitShipmentEnabled());
        $this->assertSame('human_review_only', $policy->splitExecutionMode());
        $this->assertSame('SD经济挂号-带电', $policy->factoryFallback()['carrier']);
        $this->assertSame('SD经济挂号-带电', config('carrier_routing.factory_fallback.carrier'));
        $this->assertTrue($policy->isUsMilitaryDestination(['city' => 'FPO', 'province_state' => 'AP']));
        $this->assertFalse($policy->factoryAllowed(['city' => 'FPO', 'province_state' => 'AP']));
        $this->assertTrue($policy->isUsAkHiDestination(['province_state' => 'AK']));
        $this->assertFalse($policy->isUsAkHiDestination(['province_state' => 'AE', 'city' => 'APO']));
    }

    public function test_runtime_config_contains_no_business_routing_rules(): void
    {
        $runtime = config('inventory_auto_allocation');
        $this->assertArrayNotHasKey('country_allowlist', $runtime);
        $this->assertArrayNotHasKey('warehouse_by_country', $runtime);
    }
}
