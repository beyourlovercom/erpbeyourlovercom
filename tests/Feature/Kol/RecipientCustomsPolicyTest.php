<?php

namespace Tests\Feature\Kol;

use App\Modules\Kol\Application\RecipientCustomsPolicy;
use PHPUnit\Framework\TestCase;

final class RecipientCustomsPolicyTest extends TestCase
{
    public function test_shipping_country_aliases_trigger_required_recipient_identification(): void
    {
        foreach (['CL', 'Chili', 'Chile', '智利', 'PE', 'Peru', '秘鲁', 'AR', 'Argentina',
            'BR', 'Brazil', 'MX', 'Mexico', '墨西哥', 'KR', 'Korea', 'South Korea', '韩国'] as $country) {
            self::assertTrue(RecipientCustomsPolicy::requiresRecipientId($country), $country);
        }
    }

    public function test_norway_is_not_a_recipient_voec_number_requirement(): void
    {
        foreach (['NO', 'Norway', '挪威'] as $country) {
            self::assertFalse(RecipientCustomsPolicy::requiresRecipientId($country));
            self::assertTrue(RecipientCustomsPolicy::showsRecipientId($country));
        }
        self::assertFalse(RecipientCustomsPolicy::showsRecipientId('US'));
        self::assertFalse(RecipientCustomsPolicy::showsRecipientId(null));
    }
}
