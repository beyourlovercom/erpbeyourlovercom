<?php

namespace Tests\Unit;

use App\Support\Countries\CountryStandard;
use App\Modules\Orders\Infrastructure\Models\OrderAddress;
use App\Modules\Kol\Infrastructure\Models\Kol;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CountryStandardTest extends TestCase
{
    public function test_country_dictionary_and_exact_aliases(): void
    {
        $this->assertCount(249, CountryStandard::dictionary());
        foreach (['norway','Norway (NO)','NO','挪威','Norge'] as $value) $this->assertSame('NO', CountryStandard::resolve($value));
        foreach (['Germany'=>'DE','Italy'=>'IT','México'=>'MX','USA'=>'US','UK'=>'GB','Viet Nam'=>'VN','中国'=>'CN'] as $value=>$code) {
            $this->assertSame($code, CountryStandard::resolve($value));
        }
        foreach (['EU','OTHER','ASIA','XX','Korea','Congo','Philippi','nor'] as $value) $this->assertNull(CountryStandard::resolve($value));
    }

    public function test_model_writes_store_iso_codes_and_keep_nationality_separate(): void
    {
        $address = new OrderAddress(['country'=>'Norway']);
        $this->assertSame('NO', $address->getAttributes()['country']);
        $kol = new Kol(['country_code'=>'Mexico','shipping_country'=>'Germany']);
        $this->assertSame('MX', $kol->getAttributes()['country_code']);
        $this->assertSame('DE', $kol->getAttributes()['shipping_country']);
    }

    public function test_invalid_new_country_is_rejected_but_unchanged_legacy_value_is_preserved(): void
    {
        $this->assertSame('EU', CountryStandard::forWrite('EU','country','EU'));
        $this->expectException(ValidationException::class);
        CountryStandard::forWrite('OTHER','country');
    }
}
