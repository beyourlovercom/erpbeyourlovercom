<?php

namespace App\Modules\Kol\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Kol\Application\AffiliateWp\ReferralResolutionService;
use App\Modules\Kol\Application\AffiliateWp\ConnectorResponse;
use App\Modules\Kol\Application\AffiliateWp\AffiliateSnapshotSyncService;
use App\Modules\Kol\Application\AffiliateWp\CouponSnapshotSyncService;
use App\Modules\Kol\Application\Approval\KolApprovalService;
use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateCoupon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class KolHistoricalAccountBinding
{
    public function __construct(
        private readonly AffiliateWpGateway $gateway,
        private readonly KolService $kols,
        private readonly KolApprovalService $approvals,
        private readonly ReferralResolutionService $referrals,
        private readonly AuditRecorder $audit,
        private readonly AffiliateSnapshotSyncService $affiliateSnapshot,
        private readonly CouponSnapshotSyncService $couponSnapshot,
    ) {}

    private function key(): string
    {
        return (string) ($this->gateway->descriptor()['connection_key'] ?? '');
    }

    private function normalize(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    private function matches(string $connection, string $username)
    {
        return KolAffiliateAccount::query()->where('connection_key', $connection)
            ->whereRaw('LOWER(TRIM(wordpress_user_login)) = ?', [$this->normalize($username)]);
    }

    public function preview(string $username): ?array
    {
        if ($this->normalize($username) === '' || ($key = $this->key()) === '') return null;
        $accounts = $this->matches($key, $username)->limit(2)->get();
        if ($accounts->count() !== 1) return null;
        $account = $accounts->first();
        if ($account->kol_id !== null || ! $this->eligible($account)) return null;

        // No historical email or other personal data is disclosed through username lookup.
        return [
            'wordpress_user_id' => $account->wordpress_user_id,
            'affiliate_id' => $account->affiliate_id,
        ];
    }

    private function eligible(KolAffiliateAccount $account): bool
    {
        return $account->affiliate_id > 0 && $account->wordpress_user_id > 0
            && $account->provider_status === 'active'
            && $account->reconciliation_status === 'present'
            && $account->sync_status === 'synced'
            && $this->normalize($account->wordpress_user_email) !== '';
    }

    public function bindIfPresent(string $connection, string $username, array $data, array $socialAccounts, array $salesPerformanceEntries, Authenticatable $actor, ?ConnectorResponse $verified = null): ?array
    {
        if (! KolScope::canOperate($actor)) throw new AuthorizationException;
        $accounts = $this->matches($connection, $username)->limit(2)->get();
        if ($accounts->isEmpty()) return null;
        if ($accounts->count() !== 1) $this->fail('官网用户名对应多个历史账号，请管理员核查。');
        $account = $accounts->first();
        $email = $this->normalize($data['contact_email'] ?? null);
        if ($account->kol_id !== null || ! $this->eligible($account)) $this->fail('历史官网账号已绑定或状态不可用，请管理员核查。');
        if ($email === '' || $email !== $this->normalize($account->wordpress_user_email)) $this->fail('邮箱与历史 WordPress 账号不一致，禁止自动绑定。', 'contact_email');

        // Read-only live verification outside the DB transaction. Never send password/provision requests.
        try {
            $live = $verified ?? $this->gateway->checkAffiliateIdentity($email);
        } catch (RuntimeException $e) {
            report($e);
            $this->fail('官网历史账号实时核验失败，请稍后重试。', 'contact_email');
        }
        $facts = $live->data;
        if (! $live->ok || $live->connectionKey !== $connection || ! is_array($facts)
            || ($facts['available'] ?? null) !== false
            || (int) ($facts['wp_user_id'] ?? $facts['wordpress_user_id'] ?? 0) !== $account->wordpress_user_id
            || (int) ($facts['affiliate_id'] ?? 0) !== $account->affiliate_id) {
            $this->fail('官网返回的 WordPress/AffiliateWP 身份与历史记录不一致，请管理员核查。', 'contact_email');
        }

        return DB::transaction(function () use ($connection, $username, $data, $socialAccounts, $salesPerformanceEntries, $actor, $account, $email): array {
            $locked = $this->matches($connection, $username)->lockForUpdate()->get();
            $row = $locked->first();
            if ($locked->count() !== 1 || ! $row || $row->id !== $account->id || $row->kol_id !== null
                || ! $this->eligible($row) || $row->affiliate_id !== $account->affiliate_id
                || $row->wordpress_user_id !== $account->wordpress_user_id
                || $this->normalize($row->wordpress_user_email) !== $email) {
                $this->fail('历史账号已被绑定或发生变化，请重新查询。');
            }
            if (KolDuplicateIdentity::find($username, (string) ($data['display_name'] ?? ''))
                || Kol::query()->whereRaw('LOWER(TRIM(contact_email)) = ?', [$email])->exists()
                || KolAffiliateAccount::query()->where('connection_key', $connection)->whereKeyNot($row->id)
                    ->where(fn ($q) => $q->where('wordpress_user_id', $row->wordpress_user_id)
                        ->orWhereRaw('LOWER(TRIM(wordpress_user_email)) = ?', [$email])
                        ->orWhereRaw('LOWER(TRIM(email)) = ?', [$email]))->exists()) {
                $this->fail('存在其他 ERP 网红或官网身份冲突，请管理员核查。');
            }
            $coupons = KolAffiliateCoupon::query()->where('connection_key', $connection)
                ->where('affiliate_id', $row->affiliate_id)->lockForUpdate()->get();
            if ($coupons->contains(fn ($coupon) => $coupon->kol_id !== null)) {
                $this->fail('历史优惠码已绑定其他网红，请管理员核查。');
            }
            // Maintain exactly the same owner restrictions as the create controller.
            $profile = $data;
            if (! KolScope::isAllScope($actor)) {
                $profile['owner_id'] = (int) $actor->getAuthIdentifier();
                $profile['approval_manager_id'] = null;
                $profile['finance_owner_id'] = null;
            }
            $profile['contact_email'] = $email;
            $kol = $this->kols->create($profile, $socialAccounts, $actor, $salesPerformanceEntries, false);
            $this->approvals->approveVerifiedCreate($kol, $actor);
            $row->update(['kol_id' => $kol->id]);
            $this->audit->record(AuditActor::user($actor, AuditSource::Web), 'kol.affiliate.bound', get_class($row), $row->id,
                before: ['kol_id' => null], after: ['kol_id' => $kol->id, 'source' => 'historical_jit_binding']);
            foreach ($coupons as $coupon) {
                if (! $coupon->coupon_id || $coupon->reconciliation_status !== 'present' || $coupon->sync_status !== 'synced') continue;
                $coupon->update(['kol_id' => $kol->id]);
                $this->audit->record(AuditActor::user($actor, AuditSource::Web), 'kol.coupon.bound', get_class($coupon), $coupon->id,
                    before: ['kol_id' => null], after: ['kol_id' => $kol->id, 'source' => 'historical_jit_binding']);
            }
            $this->referrals->resolve($connection, $row->affiliate_id);

            return ['kol' => $kol->fresh(), 'success' => true, 'affiliate_status' => $row->provider_status,
                'coupon_status' => $coupons->isNotEmpty() ? 'existing' : null, 'historical_binding' => true];
        }, 3);
    }

    /**
     * Slow path only when an existing provider identity is absent from the local mirror.
     * Read the authoritative snapshots, retaining ONLY the exact requested affiliate and coupons.
     * Never import other accounts, change provider credentials or provision an account on this path.
     */
    public function bindFromProvider(string $connection, string $username, array $data, array $socialAccounts, array $salesPerformanceEntries, Authenticatable $actor, ConnectorResponse $identity): array
    {
        if (! KolScope::canOperate($actor)) throw new AuthorizationException;
        $facts = is_array($identity->data) ? $identity->data : [];
        $wpId = (int) ($facts['wp_user_id'] ?? $facts['wordpress_user_id'] ?? 0);
        $affiliateId = (int) ($facts['affiliate_id'] ?? 0);
        $email = $this->normalize($data['contact_email'] ?? null);
        if (! $identity->ok || $identity->connectionKey !== $connection
            || ($facts['available'] ?? null) !== false || $wpId <= 0 || $affiliateId <= 0) {
            $this->fail('官网已有账号未能确认完整身份，请管理员核查。', 'contact_email');
        }
        try {
            $affiliate = null;
            foreach ($this->snapshotItems($connection, 'affiliates') as $item) {
                if ((int) ($item['affiliate_id'] ?? 0) !== $affiliateId) continue;
                if ($affiliate !== null) $this->fail('官网返回重复 Affiliate ID，禁止自动绑定。');
                $affiliate = $this->affiliateSnapshot->normalizeItem($item, $affiliateId);
            }
            if (! $affiliate || (int) $affiliate['wordpress_user_id'] !== $wpId
                || $this->normalize($affiliate['wordpress_user_login']) !== $this->normalize($username)
                || $this->normalize($affiliate['wordpress_user_email']) !== $email
                || $affiliate['provider_status'] !== 'active') {
                $this->fail('官网用户名、邮箱、账号状态或 ID 不一致，禁止自动绑定。', 'contact_email');
            }
            $coupons = [];
            foreach ($this->snapshotItems($connection, 'coupons') as $item) {
                if ((int) ($item['linked_affiliate_id'] ?? 0) !== $affiliateId) continue;
                $fields = $this->couponSnapshot->normalizeItem($item);
                if (isset($coupons[$fields['coupon_id']]) || $fields['normalized_code'] === ''
                    || ! in_array($fields['discount_type'], ['percent', 'fixed_cart', 'fixed_product'], true)) {
                    $this->fail('官网历史优惠码数据存在冲突，请管理员核查。');
                }
                $coupons[$fields['coupon_id']] = $fields;
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            report($e);
            $this->fail('官网历史账号同步核验失败，请稍后重试。', 'contact_email');
        }

        return DB::transaction(function () use ($connection, $username, $data, $socialAccounts, $salesPerformanceEntries, $actor, $identity, $affiliate, $coupons, $affiliateId, $wpId, $email): array {
            $providerFields = ['sync_status' => 'synced', 'reconciliation_status' => 'present',
                'last_synced_at' => now(), 'last_successful_sync_at' => now()];
            $row = KolAffiliateAccount::query()->firstOrCreate(
                ['connection_key' => $connection, 'affiliate_id' => $affiliateId],
                [...$affiliate, ...$providerFields, 'origin' => 'external_sync'],
            );
            $row = KolAffiliateAccount::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            if ($row->kol_id !== null || $row->wordpress_user_id !== $wpId
                || $this->normalize($row->wordpress_user_login) !== $this->normalize($username)
                || $this->normalize($row->wordpress_user_email) !== $email) {
                $this->fail('官网账号已绑定或同步身份发生变化，请重新查询。');
            }
            foreach ($coupons as $couponId => $fields) {
                $coupon = KolAffiliateCoupon::query()->firstOrCreate(
                    ['connection_key' => $connection, 'coupon_id' => $couponId],
                    [...$fields, ...$providerFields, 'origin' => 'external_sync'],
                );
                if ($coupon->kol_id !== null || $coupon->affiliate_id !== $affiliateId
                    || $coupon->normalized_code !== $fields['normalized_code']) {
                    $this->fail('官网历史优惠码绑定发生冲突，请管理员核查。');
                }
            }
            $result = $this->bindIfPresent($connection, $username, $data, $socialAccounts, $salesPerformanceEntries, $actor, $identity);
            if ($result === null) $this->fail('历史账号状态发生变化，请重新查询。');
            return $result;
        }, 3);
    }

    /** @return iterable<array<string,mixed>> */
    private function snapshotItems(string $connection, string $resource): iterable
    {
        $cursor = null;
        $visited = [];
        for ($page = 0; $page < 100; $page++) {
            $response = $this->gateway->snapshot($resource, $cursor, null);
            if (! $response->ok || $response->connectionKey !== $connection
                || ! is_array($response->data) || ! is_array($response->data['items'] ?? null)) {
                $this->fail('官网历史记录查询不完整，禁止自动绑定。');
            }
            foreach ($response->data['items'] as $item) {
                if (! is_array($item)) $this->fail('官网历史记录格式异常。');
                yield $item;
            }
            $next = $response->data['next_cursor'] ?? null;
            if ($next === null || $next === '') return;
            if (! is_string($next) || isset($visited[$next])) $this->fail('官网历史记录分页异常。');
            $visited[$next] = true;
            $cursor = $next;
        }
        $this->fail('官网历史记录超过实时查询上限，请先同步账号后重试。');
    }

    private function fail(string $message, string $field = 'wordpress_username'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
