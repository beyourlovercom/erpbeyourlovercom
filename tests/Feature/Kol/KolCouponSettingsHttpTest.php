<?php

namespace Tests\Feature\Kol;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Application\Contracts\WooCouponSettingsGateway;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateCoupon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class KolCouponSettingsHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.andy_core.connection_key' => 'beyourlover.com',
            'services.woocommerce.connection_key' => 'beyourlover.com',
        ]);
    }

    private function actor(bool $operate = true): User
    {
        $role = Role::create(['key' => 'test_bound_coupon_'.uniqid(), 'label' => 'KOL']);
        foreach ($operate ? ['kol.view', 'kol.operate'] : ['kol.view'] as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }

    private function bound(User $owner): array
    {
        $kol = Kol::create([
            'display_name' => 'Creator', 'status' => 'active', 'relationship_stage' => 'cooperating',
            'owner_id' => $owner->id, 'kol_profile_url' => 'https://www.instagram.com/old/',
        ]);
        KolAffiliateAccount::create([
            'connection_key' => 'beyourlover.com',
            'affiliate_id' => 701, 'kol_id' => $kol->id, 'wordpress_user_id' => 501,
            'provider_status' => 'active', 'origin' => 'erp_provisioned',
            'sync_status' => 'synced', 'reconciliation_status' => 'present', 'provisioning_status' => 'active',
        ]);
        $coupon = KolAffiliateCoupon::create([
            'connection_key' => 'beyourlover.com', 'kol_id' => $kol->id, 'affiliate_id' => 701,
            'coupon_id' => 801, 'code' => 'CREATOR20', 'normalized_code' => 'creator20',
            'discount_type' => 'percent', 'amount' => '20',
            'origin' => 'erp_provisioned', 'sync_status' => 'synced',
            'reconciliation_status' => 'present', 'provisioning_status' => 'active',
            'date_expires' => '2029-01-01 23:59:59',
        ]);
        return [$kol, $coupon];
    }

    private function body(): array
    {
        return ['kol_profile_url' => 'https://www.instagram.com/new/', 'affiliate_coupon_expiry' => '2030-12-31'];
    }

    public function test_exact_bound_coupon_update_writes_two_fields_without_new_coupon(): void
    {
        config(['services.andy_core.uat_fake' => true]);
        $user = $this->actor();
        [$kol, $coupon] = $this->bound($user);
        $mock = Mockery::mock(WooCouponSettingsGateway::class);
        $mock->shouldReceive('sync')->once()
            ->with(801, 'CREATOR20', 701, 'https://www.instagram.com/new/', '2030-12-31')
            ->andReturn(['coupon_id' => 801, 'description' => 'https://www.instagram.com/new/', 'date_expires' => '2030-12-31']);
        $this->app->instance(WooCouponSettingsGateway::class, $mock);

        $this->actingAs($user)
            ->patchJson("/kol/library/{$kol->id}/affiliate-coupon", $this->body())
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', '本地 UAT 模拟同步成功（未调用真实 WooCommerce API）。')
            ->assertJsonPath('affiliate_account.coupon.code', 'CREATOR20')
            ->assertJsonPath('kol_profile_url', 'https://www.instagram.com/new/');
        self::assertSame('2030-12-31', $coupon->fresh()->date_expires?->format('Y-m-d'));
        self::assertSame('active', $coupon->fresh()->provisioning_status);
        self::assertSame('https://www.instagram.com/new/', $kol->fresh()->kol_profile_url);
        self::assertSame('20', $coupon->fresh()->amount);
        self::assertSame(1, KolAffiliateCoupon::query()->count());
    }

    public function test_provider_timeout_preserves_erp_profile_and_reports_retryable_metadata_error(): void
    {
        $user = $this->actor();
        [$kol, $coupon] = $this->bound($user);
        $mock = Mockery::mock(WooCouponSettingsGateway::class);
        $mock->shouldReceive('sync')->once()->andThrow(new RuntimeException('Simulated remote timeout'));
        $this->app->instance(WooCouponSettingsGateway::class, $mock);
        $this->actingAs($user)
            ->patchJson("/kol/library/{$kol->id}/affiliate-coupon", $this->body())
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('affiliate_account.coupon.provisioning_status', 'metadata_sync_failed');
        self::assertSame('https://www.instagram.com/old/', $kol->fresh()->kol_profile_url);
        self::assertSame('2029-01-01', $coupon->fresh()->date_expires?->format('Y-m-d'));
        self::assertSame(1, KolAffiliateCoupon::count());
    }

    public function test_failed_write_can_recover_using_same_coupon_id_without_creating_anything(): void
    {
        $user = $this->actor();
        [$kol, $coupon] = $this->bound($user);
        $mock = Mockery::mock(WooCouponSettingsGateway::class);
        $mock->shouldReceive('sync')->twice()
            ->andReturnUsing(function () {
                static $attempts = 0;
                if (++$attempts === 1) {
                    throw new RuntimeException('Transient failure');
                }
                return ['coupon_id' => 801, 'description' => 'https://www.instagram.com/new/', 'date_expires' => '2030-12-31'];
            });
        $this->app->instance(WooCouponSettingsGateway::class, $mock);
        $this->actingAs($user)->patchJson("/kol/library/{$kol->id}/affiliate-coupon", $this->body())->assertUnprocessable();
        $this->actingAs($user)->patchJson("/kol/library/{$kol->id}/affiliate-coupon", $this->body())->assertOk();
        self::assertSame(801, $coupon->fresh()->coupon_id);
        self::assertSame('active', $coupon->fresh()->provisioning_status);
        self::assertSame(1, KolAffiliateCoupon::count());
    }

    public function test_cross_owner_cannot_call_provider(): void
    {
        $owner = $this->actor();
        $foreign = $this->actor();
        [$kol] = $this->bound($owner);
        $mock = Mockery::mock(WooCouponSettingsGateway::class);
        $mock->shouldNotReceive('sync');
        $this->app->instance(WooCouponSettingsGateway::class, $mock);
        $this->actingAs($foreign)->patchJson("/kol/library/{$kol->id}/affiliate-coupon", $this->body())->assertForbidden();
    }

    public function test_kol_without_operate_permission_is_forbidden(): void
    {
        $user = $this->actor(false);
        [$kol] = $this->bound($user);
        $this->actingAs($user)->patchJson("/kol/library/{$kol->id}/affiliate-coupon", $this->body())->assertForbidden();
    }

    public function test_invalid_url_or_date_fails_before_provider_call(): void
    {
        $user = $this->actor();
        [$kol] = $this->bound($user);
        $mock = Mockery::mock(WooCouponSettingsGateway::class);
        $mock->shouldNotReceive('sync');
        $this->app->instance(WooCouponSettingsGateway::class, $mock);
        $this->actingAs($user)->patchJson("/kol/library/{$kol->id}/affiliate-coupon", [
            'kol_profile_url' => 'javascript:alert(1)',
            'affiliate_coupon_expiry' => '2029-02-31',
        ])->assertUnprocessable();
    }

    public function test_wrong_erp_affiliate_binding_blocks_before_provider_call(): void
    {
        $user = $this->actor();
        [$kol, $coupon] = $this->bound($user);
        $coupon->update(['affiliate_id' => 999]);
        $mock = Mockery::mock(WooCouponSettingsGateway::class);
        $mock->shouldNotReceive('sync');
        $this->app->instance(WooCouponSettingsGateway::class, $mock);
        $this->actingAs($user)
            ->patchJson("/kol/library/{$kol->id}/affiliate-coupon", $this->body())
            ->assertUnprocessable();
        self::assertSame('https://www.instagram.com/old/', $kol->fresh()->kol_profile_url);
    }
}
