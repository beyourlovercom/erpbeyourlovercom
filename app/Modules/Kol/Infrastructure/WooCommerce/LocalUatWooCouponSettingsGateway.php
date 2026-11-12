<?php

namespace App\Modules\Kol\Infrastructure\WooCommerce;

use App\Modules\Kol\Application\Contracts\WooCouponSettingsGateway;

// Explicit testing/local-only stub, NEVER a live provider verification.
final class LocalUatWooCouponSettingsGateway implements WooCouponSettingsGateway
{
    public function sync(int $couponId, string $code, int $affiliateId, string $profileUrl, string $expiryDate): array
    {
        return ['coupon_id' => $couponId, 'description' => $profileUrl, 'date_expires' => $expiryDate];
    }
}
