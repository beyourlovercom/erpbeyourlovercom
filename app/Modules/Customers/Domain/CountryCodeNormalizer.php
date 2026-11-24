<?php

namespace App\Modules\Customers\Domain;

use App\Modules\Customers\Domain\Exceptions\InvalidCompanyAttributeException;

final class CountryCodeNormalizer
{
    /**
     * Resolve an exact country name or ISO 3166-1 alpha-2 code.
     */
    public static function normalize(string $countryCode): string
    {
        $normalized = \App\Support\Countries\CountryStandard::resolve($countryCode);

        if ($normalized === null) {
            throw new InvalidCompanyAttributeException('无法识别国家，请选择标准国家代码。');
        }

        return $normalized;
    }
}
