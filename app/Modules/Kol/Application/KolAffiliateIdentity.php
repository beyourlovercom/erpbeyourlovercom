<?php

namespace App\Modules\Kol\Application;

use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use Illuminate\Database\Eloquent\Builder;

final class KolAffiliateIdentity
{
    public const CONNECTION_KEY = 'beyourlover.com';

    public const MISSING_MESSAGE = '该网红尚未绑定有效的 BeYourLover AffiliateWP 账号，请先完成联盟账号绑定，再创建新网红履约订单。';

    public static function scopeActiveAffiliate(Builder $query): Builder
    {
        return $query->where('connection_key', self::CONNECTION_KEY)
            ->where('provider_status', 'active')
            ->where('affiliate_id', '>', 0);
    }

    public static function isReady(int $kolId): bool
    {
        return self::scopeActiveAffiliate(
            KolAffiliateAccount::query()->where('kol_id', $kolId)
        )->exists();
    }
}
