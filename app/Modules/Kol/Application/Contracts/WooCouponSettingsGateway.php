<?php

namespace App\Modules\Kol\Application\Contracts;

interface WooCouponSettingsGateway
{
    /** @return array{coupon_id:int,description:string,date_expires:string} */
    public function sync(int $couponId, string $code, int $affiliateId, string $profileUrl, string $expiryDate): array;
}
