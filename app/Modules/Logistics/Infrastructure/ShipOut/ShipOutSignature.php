<?php

namespace App\Modules\Logistics\Infrastructure\ShipOut;

use InvalidArgumentException;

/** ShipOut OpenAPI v1: uppercase MD5 of sorted path/timestamp/version fields + secret. */
final class ShipOutSignature
{
    public static function sign(string $path, string $timestamp, string $secret): string
    {
        if (! str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, '?')
            || preg_match('/^\d{13}$/', $timestamp) !== 1
            || trim($secret) === '') {
            throw new InvalidArgumentException('SHIPOUT_SIGN_INPUT_INVALID');
        }

        return strtoupper(md5('path'.$path.'timestamp'.$timestamp.'version1.0.0'.$secret));
    }
}
