<?php

namespace App\Modules\Logistics\Infrastructure\Xlwms;

use RuntimeException;

final class XlwmsWebTrackKey
{
    /** @param array<string,mixed> $body */
    public static function sign(array $body, string $secret): string
    {
        if ($secret === '') {
            throw new RuntimeException('OMS_WEB_HMAC_KEY_MISSING');
        }

        $canonical = self::canonicalJson($body);
        $digest = hash('sha256', $canonical);

        return 'v2:'.base64_encode(hash_hmac('sha256', 'POST'.$digest, $secret, true));
    }

    /** The native web HMAC and outgoing POST must use identical JSON bytes. */
    public static function canonicalJson(array $body): string
    {
        return json_encode(self::sort($body), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private static function sort(mixed $value): mixed
    {
        if (! is_array($value)) return $value;
        if (array_is_list($value)) return array_map(self::sort(...), $value);
        ksort($value);
        foreach ($value as $key => $child) $value[$key] = self::sort($child);
        return $value;
    }
}
