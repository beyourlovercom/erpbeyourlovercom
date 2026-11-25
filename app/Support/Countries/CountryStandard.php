<?php

namespace App\Support\Countries;

use Illuminate\Validation\ValidationException;

/** Exact, locale-independent ISO 3166-1 alpha-2 contract shared by ERP inputs. */
final class CountryStandard
{
    private static ?array $dictionary = null;
    private static ?array $index = null;

    public static function dictionary(): array
    {
        return self::$dictionary ??= json_decode(file_get_contents(dirname(__DIR__, 3).'/resources/data/countries/iso3166-alpha2.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function key(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)), 'UTF-8');
    }

    public static function resolve(?string $value): ?string
    {
        if ($value === null || trim($value) === '') return null;
        if (self::$index === null) {
            self::$index = [];
            foreach (self::dictionary() as $row) {
                foreach ([$row['code'], $row['en'], $row['zh'], ...$row['aliases'], $row['en'].' ('.$row['code'].')'] as $alias) {
                    $key = self::key($alias);
                    if (array_key_exists($key, self::$index) && self::$index[$key] !== $row['code']) {
                        self::$index[$key] = null;
                    } else {
                        self::$index[$key] = $row['code'];
                    }
                }
            }
        }
        return self::$index[self::key($value)] ?? null;
    }

    public static function forWrite(?string $value, string $field, ?string $original = null): ?string
    {
        if ($value === null || trim($value) === '') return null;
        $code = self::resolve($value);
        if ($code !== null) return $code;
        // Preserve an existing unresolved historical value on an unrelated edit.
        if ($original !== null && $value === $original) return $original;
        throw ValidationException::withMessages([$field => '无法识别国家，请选择准确的国家名称或两位国家代码。']);
    }
}
