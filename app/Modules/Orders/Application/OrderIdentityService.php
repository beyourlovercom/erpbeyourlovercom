<?php

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderCollaborationPartner;
use App\Modules\Orders\Infrastructure\Models\OrderIdentitySetting;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class OrderIdentityService
{
    public const DEFAULT_PREFIX = '007erp';

    public function prefix(): string
    {
        return $this->setting()->prefix_token;
    }

    public function setting(): OrderIdentitySetting
    {
        return OrderIdentitySetting::query()->firstOrCreate(
            ['id' => 1],
            ['prefix_token' => self::DEFAULT_PREFIX, 'next_customer_number' => 1],
        );
    }

    public function updatePrefix(string $prefix, ?Authenticatable $actor): OrderIdentitySetting
    {
        $prefix = $this->normalizePrefix($prefix);

        return DB::transaction(function () use ($prefix, $actor): OrderIdentitySetting {
            $setting = OrderIdentitySetting::query()->lockForUpdate()->first()
                ?? new OrderIdentitySetting(['id' => 1, 'next_customer_number' => 1]);
            $setting->fill(['prefix_token' => $prefix, 'updated_by' => $actor?->getAuthIdentifier()])->save();

            return $setting;
        });
    }

    public function previewCollaboration(?int $partnerId, string $externalOrderNumber): string
    {
        $prefix = OrderCollaborationPartner::query()->whereKey($partnerId)->value('identity_prefix');
        if (! is_string($prefix) || preg_match('/^[A-Z]{2,8}$/D', $prefix) !== 1) {
            throw ValidationException::withMessages(['partner_id' => '该合作商尚未配置唯一的订单编号缩写。']);
        }
        if (preg_match('/([0-9]{5})$/D', trim($externalOrderNumber), $matches) !== 1) {
            throw ValidationException::withMessages(['external_order_number' => '合作订单来源号必须以至少5位数字结尾。']);
        }

        return $prefix.$matches[1].'-007';
    }

    public function generateCollaboration(?int $partnerId, string $externalOrderNumber): string
    {
        $identity = $this->previewCollaboration($partnerId, $externalOrderNumber);
        if (Order::withTrashed()->whereRaw('UPPER(erp_order_id) = ?', [$identity])->exists()) {
            throw ValidationException::withMessages(['external_order_number' => '合作订单编号冲突：'.$identity.'，请人工核对来源订单号。']);
        }

        return $identity;
    }

    public function allocateCustomer(string $businessDate): string
    {
        return DB::transaction(function () use ($businessDate): string {
            $setting = OrderIdentitySetting::query()->whereKey(1)->lockForUpdate()->first();
            if (! $setting) {
                $setting = OrderIdentitySetting::query()->create([
                    'id' => 1,
                    'prefix_token' => self::DEFAULT_PREFIX,
                    'next_customer_number' => 1,
                ]);
            }

            $number = (int) $setting->next_customer_number;
            $identity = $this->format($setting->prefix_token, 'c', $number, $businessDate);
            if (Order::query()->where('erp_order_id', $identity)->exists()) {
                throw new RuntimeException('Customer ERP order sequence is out of sync with an existing ERP order identity.');
            }

            $setting->next_customer_number = $number + 1;
            $setting->save();

            return $identity;
        });
    }

    public function allocateKol(): string
    {
        // The creation clock is shared across all staff and independent of the editable order date.
        $created = now('Asia/Shanghai');
        $date = $created->toDateString();
        $prefix = 'K'.$created->format('ymd');

        $startUtc = $created->copy()->startOfDay()->utc()->toDateTimeString();
        $endUtc = $created->copy()->addDay()->startOfDay()->utc()->toDateTimeString();

        return DB::transaction(function () use ($date, $prefix, $startUtc, $endUtc): string {
            $initialized = DB::table('kol_daily_order_sequences')->insertOrIgnore([
                'creation_date' => $date, 'last_number' => 0,
            ]);
            $sequence = DB::table('kol_daily_order_sequences')->where('creation_date', $date);
            $last = (int) $sequence->lockForUpdate()->first()->last_number;
            if ($initialized === 1) {
                // On rollout day continue after all already-created root KOL orders,
                // including soft-deleted ones; derived shipments do not consume a number.
                $last = max($last, DB::table('orders')
                    ->where('order_kind', OrderKind::KolFulfillment->value)
                    ->whereNull('fulfillment_parent_id')
                    ->where('created_at', '>=', $startUtc)
                    ->where('created_at', '<', $endUtc)
                    ->count());
            }
            do {
                $last++;
                $identity = sprintf('%s%02d-007', $prefix, $last);
            } while (Order::withTrashed()->where('erp_order_id', $identity)->exists());

            $sequence->update(['last_number' => $last]);

            return $identity;
        });
    }

    public function generateWooCustomer(string $externalOrderNumber, string $providerReference, string $businessDate): string
    {
        $identity = $this->previewWooCustomer($externalOrderNumber, $providerReference, $businessDate);
        if (Order::query()->where('erp_order_id', $identity)->exists()) {
            throw new RuntimeException('WooCommerce ERP order identity already exists.');
        }

        return $identity;
    }

    public function previewWooCustomer(string $externalOrderNumber, string $providerReference, string $businessDate): string
    {
        $number = $this->wooOrderNumber($externalOrderNumber, $providerReference);

        // Website order identity is stable regardless of order date or manual prefix settings.
        return sprintf('C%s-007', $number);
    }

    private function wooOrderNumber(string $externalOrderNumber, string $providerReference): string
    {
        $externalOrderNumber = trim($externalOrderNumber);
        if (preg_match('/^#?(\d+)/', $externalOrderNumber, $matches) === 1) {
            return $matches[1];
        }

        $providerReference = trim($providerReference);
        if ($providerReference !== '' && ctype_digit($providerReference)) {
            return $providerReference;
        }

        throw new RuntimeException('WooCommerce order number must provide a numeric business order number.');
    }

    public function normalizePrefix(string $prefix): string
    {
        $prefix = strtolower(trim($prefix));
        if ($prefix === '' || ! preg_match('/^[a-z0-9]{1,24}$/', $prefix)) {
            throw ValidationException::withMessages(['prefix_token' => '前缀只能包含 1-24 个小写字母或数字。']);
        }

        return $prefix;
    }

    public function generate(string $type, int|string $sourceNumber, string $businessDate, ?string $suffix = null): string
    {
        [$type, $sourceNumber, $suffix] = $this->validatedParts($type, $sourceNumber, $suffix);
        $identity = $this->format($this->prefix(), $type, $sourceNumber, $businessDate, $suffix);
        if (Order::query()->where('erp_order_id', $identity)->exists()) {
            throw new RuntimeException('ERP order identity already exists; an explicit collision suffix is required.');
        }

        return $identity;
    }

    public function previewCandidate(string $type, int|string $sourceNumber, string $businessDate, ?string $suffix = null): string
    {
        [$type, $sourceNumber, $suffix] = $this->validatedParts($type, $sourceNumber, $suffix);
        $prefix = (string) (OrderIdentitySetting::query()->whereKey(1)->value('prefix_token') ?: self::DEFAULT_PREFIX);

        return $this->format($prefix, $type, $sourceNumber, $businessDate, $suffix);
    }

    /** @return array{0:string,1:string,2:?string} */
    private function validatedParts(string $type, int|string $sourceNumber, ?string $suffix): array
    {
        if (! in_array($type, ['c', 'k'], true)) {
            throw new RuntimeException('Unsupported ERP order identity type.');
        }
        $sourceNumber = trim((string) $sourceNumber);
        if ($sourceNumber === '' || ! ctype_digit($sourceNumber)) {
            throw new RuntimeException('ERP order identity source number must be numeric.');
        }
        if ($suffix !== null && ! preg_match('/^[A-Z]$/', $suffix)) {
            throw new RuntimeException('ERP order identity suffix must be one uppercase letter.');
        }

        return [$type, $sourceNumber, $suffix];
    }

    private function format(string $prefix, string $type, int|string $number, string $businessDate, ?string $suffix = null): string
    {
        $date = date_create_immutable($businessDate);
        if (! $date) {
            throw new RuntimeException('ERP order identity date is invalid.');
        }

        return sprintf('%s-%s-%s%s%s', $prefix, $date->format('md'), $type, $number, $suffix ?? '');
    }
}
