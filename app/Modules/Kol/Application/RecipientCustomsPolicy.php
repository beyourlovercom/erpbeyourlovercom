<?php

namespace App\Modules\Kol\Application;

final class RecipientCustomsPolicy
{
    /** Country code, common full country names, Chinese aliases, and operator's "Chili" spelling. */
    private const COUNTRY_ALIASES = [
        'CL' => ['CL', 'CHILE', 'CHILI', '智利'],
        'PE' => ['PE', 'PERU', 'PERÚ', '秘鲁'],
        'AR' => ['AR', 'ARGENTINA', '阿根廷'],
        'BR' => ['BR', 'BRAZIL', 'BRASIL', '巴西'],
        'MX' => ['MX', 'MEXICO', 'MÉXICO', '墨西哥'],
        'KR' => ['KR', 'KOREA', 'SOUTH KOREA', 'REPUBLIC OF KOREA', '韩国', '한국'],
        'NO' => ['NO', 'NORWAY', 'NORGE', '挪威'],
    ];

    public static function code(?string $country): ?string
    {
        if (! is_string($country) || trim($country) === '') {
            return null;
        }
        $normalized = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $country)), 'UTF-8');
        foreach (self::COUNTRY_ALIASES as $code => $aliases) {
            foreach ($aliases as $alias) {
                if (mb_strtoupper($alias, 'UTF-8') === $normalized) {
                    return $code;
                }
            }
        }

        return null;
    }

    public static function requiresRecipientId(?string $country): bool
    {
        return in_array(self::code($country), ['CL', 'PE', 'AR', 'BR', 'MX', 'KR'], true);
    }

    public static function showsRecipientId(?string $country): bool
    {
        return self::requiresRecipientId($country) || self::code($country) === 'NO';
    }
}
