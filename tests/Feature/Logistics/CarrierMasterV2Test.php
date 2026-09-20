<?php

namespace Tests\Feature\Logistics;

use App\Modules\Logistics\Application\CarrierServiceCatalog;
use App\Modules\Logistics\Infrastructure\Models\Carrier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CarrierMasterV2Test extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_carriers_have_bilingual_names_services_and_one_default(): void
    {
        $carrier = Carrier::query()->where('name', 'USPS')->with(['services', 'defaultService'])->firstOrFail();

        self::assertSame('USPS', $carrier->name_en);
        self::assertSame('美国邮政', $carrier->name_zh);
        self::assertSame('USPS / 美国邮政', $carrier->displayName());
        self::assertSame(3, $carrier->services->count());
        self::assertSame('USPS Priority Mail', $carrier->defaultService?->service_value);
    }

    public function test_catalog_defaults_are_carrier_specific_and_dhl_can_use_domestic_context(): void
    {
        $catalog = app(CarrierServiceCatalog::class);

        self::assertSame('USPS Priority Mail', $catalog->defaultService('USPS')?->service_value);
        self::assertSame('SD经济挂号-带电', $catalog->defaultService('4PX')?->service_value);
        self::assertSame('DHL PAKET International', $catalog->defaultService('DHL', 'FR')?->service_value);
        self::assertSame('DHL PAKET DOMESTIC', $catalog->defaultService('DHL', 'DE')?->service_value);
        self::assertSame('Standard', $catalog->defaultService('Weship')?->service_value);
    }

    public function test_catalog_resolves_only_active_services_and_falls_back_to_default_when_blank(): void
    {
        $catalog = app(CarrierServiceCatalog::class);
        $carrier = Carrier::query()->where('name', 'UPS')->with('services')->firstOrFail();
        $ground = $carrier->services->firstWhere('service_key', 'ground');
        $air = $carrier->services->firstWhere('service_key', 'next_day_air');

        self::assertSame($ground->id, $catalog->resolve('UPS', null)?->id);
        self::assertSame($air->id, $catalog->resolve('UPS', 'UPS Next Day Air')?->id);

        $air->forceFill(['is_active' => false])->save();
        self::assertNull($catalog->resolve('UPS', 'UPS Next Day Air'));
    }
}
