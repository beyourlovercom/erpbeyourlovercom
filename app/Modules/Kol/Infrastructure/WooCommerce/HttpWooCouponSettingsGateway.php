<?php

namespace App\Modules\Kol\Infrastructure\WooCommerce;

use App\Modules\Kol\Application\Contracts\WooCouponSettingsGateway;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class HttpWooCouponSettingsGateway implements WooCouponSettingsGateway
{
    public function sync(int $couponId, string $code, int $affiliateId, string $profileUrl, string $expiryDate): array
    {
        $base = rtrim(trim((string) config('services.woocommerce.url')), '/');
        $key = trim((string) config('services.woocommerce.consumer_key'));
        $secret = trim((string) config('services.woocommerce.consumer_secret'));
        if (! str_starts_with($base, 'https://') || ! filter_var($base, FILTER_VALIDATE_URL)
            || $key === '' || $secret === '' || $couponId < 1 || $affiliateId < 1) {
            throw new RuntimeException('WooCommerce Coupon REST credentials, HTTPS URL, or exact IDs missing.');
        }
        if (! filter_var($profileUrl, FILTER_VALIDATE_URL) || ! in_array(strtolower((string) parse_url($profileUrl, PHP_URL_SCHEME)), ['https', 'http'], true)
            || strlen($profileUrl) > 500 || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $expiryDate)
            || ! checkdate((int) substr($expiryDate, 5, 2), (int) substr($expiryDate, 8, 2), (int) substr($expiryDate, 0, 4))) {
            throw new RuntimeException('Invalid KOL Coupon profile URL or local calendar date.');
        }

        $url = $base.'/wp-json/wc/v3/coupons/'.$couponId;
        $http = Http::acceptJson()->withBasicAuth($key, $secret)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; BYL-ERP-WooCommerce-Inbound/1.0; +https://www.beyourlover.com/)'])
            ->timeout(max(1, (int) config('services.woocommerce.timeout', 15)));

        $before = $http->get($url)->throw()->json();
        $this->assertExactIdentity($before, $couponId, $code, $affiliateId);

        // These two keys are the entire permitted mutation. WooCommerce treats
        // date_expires as the site's local calendar time; no website timezone guesses.
        $response = $http->put($url, [
            'description' => $profileUrl,
            'date_expires' => $expiryDate.'T23:59:59',
        ])->throw()->json();
        $this->assertExactIdentity($response, $couponId, $code, $affiliateId);

        // Re-read canonical provider data. A timed-out PUT may have succeeded:
        // safe retry repeats PUT to the same ID, and NEVER creates a second Coupon.
        $after = $http->get($url)->throw()->json();
        $this->assertExactIdentity($after, $couponId, $code, $affiliateId);
        if ((string) ($after['description'] ?? '') !== $profileUrl
            || substr((string) ($after['date_expires'] ?? ''), 0, 19) !== $expiryDate.'T23:59:59') {
            throw new RuntimeException('WooCommerce Coupon Description/expiry exact read-back mismatch.');
        }
        foreach (['discount_type', 'amount', 'usage_limit', 'usage_limit_per_user', 'individual_use', 'free_shipping', 'product_ids', 'excluded_product_ids'] as $field) {
            if (array_key_exists($field, $before) && ($after[$field] ?? null) !== $before[$field]) {
                throw new RuntimeException('WooCommerce Coupon non-target property changed unexpectedly: '.$field);
            }
        }

        return [
            'coupon_id' => $couponId,
            'description' => $profileUrl,
            'date_expires' => $expiryDate,
        ];
    }

    /** @param mixed $coupon */
    private function assertExactIdentity(mixed $coupon, int $expectedId, string $code, int $affiliateId): void
    {
        if (! is_array($coupon)
            || (int) ($coupon['id'] ?? 0) !== $expectedId
            || mb_strtolower(trim((string) ($coupon['code'] ?? ''))) !== mb_strtolower(trim($code))) {
            throw new RuntimeException('WooCommerce Coupon ID or normalized code does not match the ERP binding.');
        }
        $found = null;
        foreach (($coupon['meta_data'] ?? []) as $meta) {
            if (is_array($meta) && ($meta['key'] ?? null) === 'affwp_discount_affiliate') {
                $found = $meta['value'] ?? null;
                break;
            }
        }
        if (! is_scalar($found) || ! ctype_digit((string) $found) || (int) $found !== $affiliateId) {
            throw new RuntimeException('WooCommerce Coupon Affiliate owner cannot be verified; metadata mutation blocked.');
        }
    }
}
