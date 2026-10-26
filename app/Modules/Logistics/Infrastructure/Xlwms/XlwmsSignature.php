<?php

namespace App\Modules\Logistics\Infrastructure\Xlwms;

use InvalidArgumentException;
use JsonException;

/**
 * XLWMS OpenAPI v1 signature: HMAC-SHA256(appKey + sorted-data-JSON + reqTime).
 * Official data sorting is case-insensitive by key. List order is preserved.
 */
final class XlwmsSignature
{
    public static function normalize(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = (array) $value;
            $keys = array_keys($value);
            usort($keys, static fn (string|int $a, string|int $b): int =>
                strcasecmp((string) $a, (string) $b) ?: strcmp((string) $a, (string) $b));
            $sorted = new \stdClass();
            foreach ($keys as $key) {
                $sorted->{(string) $key} = self::normalize($value[$key]);
            }

            return $sorted;
        }

        if (is_array($value)) {
            if (! array_is_list($value)) {
                return self::normalize((object) $value);
            }

            return array_map(self::normalize(...), $value);
        }

        return $value;
    }

    public static function dataJson(mixed $data): string
    {
        try {
            return json_encode(
                self::normalize($data),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('XLWMS request data cannot be serialized.', 0, $exception);
        }
    }

    public static function sign(string $appKey, string $appSecret, string $reqTime, mixed $data = null): string
    {
        if ($appKey === '' || $appSecret === '' || preg_match('/^\d{10}$/', $reqTime) !== 1) {
            throw new InvalidArgumentException('XLWMS signing parameters are invalid.');
        }

        $plain = $appKey.($data === null ? '' : self::dataJson($data)).$reqTime;

        return hash_hmac('sha256', $plain, $appSecret);
    }
}
