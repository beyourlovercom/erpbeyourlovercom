<?php

namespace Tests\Feature\Kol;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Application\AffiliateWp\ConnectorResponse;
use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use App\Modules\Kol\Application\Contracts\WooCouponSettingsGateway;
use App\Modules\Kol\Infrastructure\WooCommerce\LocalUatWooCouponSettingsGateway;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateCoupon;
use App\Modules\Kol\Infrastructure\Models\KolApprovalDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class KolCreateAutoProvisionTest extends TestCase
{
    use RefreshDatabase;

    private const CONNECTION = 'beyourlover.com';

    private function specialist(): User
    {
        $role = Role::create(['key' => 'test_kol_create_'.uniqid(), 'label' => 'KOL Specialist']);
        foreach (['kol.view', 'kol.operate'] as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    private function mockGateway(): AffiliateWpGateway&MockInterface
    {
        $mock = Mockery::mock(AffiliateWpGateway::class);
        $mock->shouldReceive('descriptor')->andReturn(['connection_key' => self::CONNECTION])->byDefault();
        $this->app->instance(AffiliateWpGateway::class, $mock);
        $this->app->instance(WooCouponSettingsGateway::class, new LocalUatWooCouponSettingsGateway);
        config([
            'kol.create_auto_provision.coupon_discount_type' => 'percent',
            'kol.create_auto_provision.coupon_amount' => '15',
            'kol.create_auto_provision.coupon_expiry' => null,
        ]);

        return $mock;
    }

    private function response(array $data, string $requestId): ConnectorResponse
    {
        return ConnectorResponse::fromArray([
            'ok' => true,
            'contract_version' => '1',
            'request_id' => $requestId,
            'connection_key' => self::CONNECTION,
            'data' => $data,
        ]);
    }

    private function payload(string $email = 'new-kol@example.test', string $code = 'NEWKOL15'): array
    {
        return [
            'display_name' => 'New Auto KOL',
            'status' => 'active',
            'relationship_stage' => 'prospect',
            'contact_email' => $email,
            'wordpress_username' => 'creator-login',
            'wordpress_password' => 'StrongPassword123',
            'wordpress_password_confirmation' => 'StrongPassword123',
            'affiliate_coupon_code' => $code,
            'joined_at' => '2026-11-12',
            'kol_profile_url' => 'https://www.instagram.com/new-kol/',
            'social_accounts' => [],
        ];
    }

    public function test_specialist_create_runs_strict_auto_approval_affiliate_coupon_and_notification_without_affiliate_manage(): void
    {
        $specialist = $this->specialist();
        $mock = $this->mockGateway();
        $mock->shouldReceive('checkAffiliateIdentity')->once()->with('new-kol@example.test')
            ->andReturn($this->response(['available' => true], 'identity-1'));
        $mock->shouldReceive('checkCoupon')->twice()->with('NEWKOL15')
            ->andReturn($this->response(['available' => true, 'normalized_code' => 'newkol15'], 'coupon-check'));

        $capturedAffiliatePayload = null;
        $mock->shouldReceive('provisionAffiliate')->once()
            ->andReturnUsing(function (array $payload, string $key) use (&$capturedAffiliatePayload): ConnectorResponse {
                $capturedAffiliatePayload = $payload;
                self::assertNotSame('', $key);

                return $this->response([
                    'affiliate_id' => 91001,
                    'wp_user_id' => 71001,
                    'affiliate_status' => 'active',
                    'affiliate_url_slug' => 'creator-login',
                    'created_user' => true,
                    'created_affiliate' => true,
                ], 'affiliate-provision');
            });
        $mock->shouldReceive('provisionCoupon')->once()
            ->withArgs(function (array $payload, string $key): bool {
                self::assertSame('NEWKOL15', $payload['code']);
                self::assertSame('percent', $payload['discount_type']);
                self::assertSame('15', $payload['amount']);
                self::assertSame('2029-11-12T23:59:59+00:00', $payload['expiry']);
                self::assertNotSame('', $key);
                return true;
            })
            ->andReturnUsing(fn (array $payload) => $this->response([
                'coupon_id' => 92001,
                'code' => $payload['code'],
                'normalized_code' => 'newkol15',
                'affiliate_id' => 91001,
                'discount_type' => 'percent',
                'amount' => '15',
                'date_expires' => $payload['expiry'],
            ], 'coupon-provision'));

        $response = $this->actingAs($specialist)->post('/kol/library', $this->payload());
        $kol = Kol::query()->where('contact_email', 'new-kol@example.test')->firstOrFail();

        $response->assertRedirect(route('kol.library.show', $kol));
        $response->assertSessionHas('success');
        self::assertSame($specialist->id, (int) $kol->owner_id);
        self::assertSame('approved', $kol->approval_status);
        self::assertFalse($specialist->can('kol.affiliates.manage'));
        self::assertSame('approved', KolApprovalDecision::query()->where('kol_id', $kol->id)->sole()->outcome);
        self::assertSame('system_auto_provision', KolApprovalDecision::query()->where('kol_id', $kol->id)->sole()->source);

        $account = KolAffiliateAccount::query()->where('kol_id', $kol->id)->firstOrFail();
        self::assertSame('active', $account->provisioning_status);
        self::assertSame(91001, $account->affiliate_id);
        self::assertSame(71001, $account->wordpress_user_id);
        $coupon = KolAffiliateCoupon::query()->where('kol_id', $kol->id)->firstOrFail();
        self::assertSame('active', $coupon->provisioning_status);
        self::assertSame(92001, $coupon->coupon_id);

        self::assertSame('StrongPassword123', $capturedAffiliatePayload['password']);
        self::assertSame('reject', $capturedAffiliatePayload['existing_account_policy']);
        self::assertSame('new-kol@example.test', $capturedAffiliatePayload['email']);
        self::assertSame('creator-login', $capturedAffiliatePayload['preferred_username']);
        self::assertSame('creator-login', $capturedAffiliatePayload['display_name']);

        self::assertDatabaseCount('notifications', 1);

        $persisted = DB::table('audit_records')->get()->toJson()
            .DB::table('notifications')->get()->toJson()
            .DB::table('kol_affiliate_accounts')->get()->toJson()
            .DB::table('kol_affiliate_coupons')->get()->toJson();
        self::assertStringNotContainsString('StrongPassword123', $persisted);
    }

    public function test_existing_provider_identity_blocks_before_kol_creation(): void
    {
        $specialist = $this->specialist();
        $mock = $this->mockGateway();
        $mock->shouldReceive('checkAffiliateIdentity')->once()->with('existing@example.test')
            ->andReturn($this->response(['available' => false, 'wp_user_id' => 77], 'identity-conflict'));
        $mock->shouldNotReceive('checkCoupon');
        $mock->shouldNotReceive('provisionAffiliate');
        $mock->shouldNotReceive('provisionCoupon');

        $this->actingAs($specialist)->from('/kol/library/create')
            ->post('/kol/library', $this->payload('existing@example.test'))
            ->assertRedirect('/kol/library/create')
            ->assertSessionHasErrors('contact_email');

        self::assertSame(0, Kol::query()->count());
    }

    public function test_existing_coupon_blocks_before_kol_creation(): void
    {
        $specialist = $this->specialist();
        $mock = $this->mockGateway();
        $mock->shouldReceive('checkAffiliateIdentity')->once()
            ->andReturn($this->response(['available' => true], 'identity-ok'));
        $mock->shouldReceive('checkCoupon')->once()->with('USED15')
            ->andReturn($this->response(['available' => false, 'coupon_id' => 5001], 'coupon-used'));
        $mock->shouldNotReceive('provisionAffiliate');
        $mock->shouldNotReceive('provisionCoupon');

        $this->actingAs($specialist)->from('/kol/library/create')
            ->post('/kol/library', $this->payload('coupon-new@example.test', 'USED15'))
            ->assertRedirect('/kol/library/create')
            ->assertSessionHasErrors('affiliate_coupon_code');

        self::assertSame(0, Kol::query()->count());
    }

    public function test_missing_influencer_link_is_rejected_before_any_provider_call(): void
    {
        $specialist = $this->specialist();
        $mock = $this->mockGateway();
        $mock->shouldNotReceive('checkAffiliateIdentity');
        $mock->shouldNotReceive('provisionAffiliate');
        $mock->shouldNotReceive('provisionCoupon');

        $payload = $this->payload();
        unset($payload['kol_profile_url']);
        $this->actingAs($specialist)->from('/kol/library/create')
            ->post('/kol/library', $payload)
            ->assertRedirect('/kol/library/create')
            ->assertSessionHasErrors('kol_profile_url');

        self::assertSame(0, Kol::query()->count());
    }

    public function test_unsafe_influencer_link_is_rejected(): void
    {
        $specialist = $this->specialist();
        $mock = $this->mockGateway();
        $mock->shouldNotReceive('provisionAffiliate');
        $payload = $this->payload();
        $payload['kol_profile_url'] = 'javascript:alert(1)';
        $this->actingAs($specialist)->from('/kol/library/create')
            ->post('/kol/library', $payload)
            ->assertSessionHasErrors('kol_profile_url');
        self::assertSame(0, Kol::query()->count());
    }

    public function test_invalid_wordpress_username_is_rejected_before_kol_creation(): void
    {
        $specialist = $this->specialist();
        $mock = $this->mockGateway();
        $mock->shouldNotReceive('checkAffiliateIdentity');
        $mock->shouldNotReceive('provisionAffiliate');
        $payload = $this->payload();
        $payload['wordpress_username'] = 'invalid login';

        $this->actingAs($specialist)->from('/kol/library/create')
            ->post('/kol/library', $payload)
            ->assertRedirect('/kol/library/create')
            ->assertSessionHasErrors('wordpress_username');

        self::assertSame(0, Kol::query()->count());
    }

    public function test_custom_affiliate_name_overrides_wordpress_display_name(): void
    {
        $specialist = $this->specialist();
        $mock = $this->mockGateway();
        $mock->shouldReceive('checkAffiliateIdentity')->once()
            ->andReturn($this->response(['available' => true], 'identity-ok'));
        $mock->shouldReceive('checkCoupon')->twice()
            ->andReturn($this->response(['available' => true], 'coupon-ok'));
        $mock->shouldReceive('provisionAffiliate')->once()
            ->withArgs(fn (array $payload) => $payload['preferred_username'] === 'creator-login'
                && $payload['display_name'] === 'Custom Affiliate Name')
            ->andReturn($this->response(['affiliate_id' => 93011, 'wp_user_id' => 73011, 'affiliate_status' => 'active', 'affiliate_url_slug' => 'creator-login'], 'affiliate-ok'));
        $mock->shouldReceive('provisionCoupon')->once()
            ->withArgs(fn (array $payload) => $payload['expiry'] === '2032-12-31T23:59:59+00:00')
            ->andReturnUsing(fn (array $payload) => $this->response([
                'coupon_id' => 94011, 'code' => 'NEWKOL15', 'normalized_code' => 'newkol15',
                'affiliate_id' => 93011, 'discount_type' => 'percent', 'amount' => '15',
                'date_expires' => $payload['expiry'],
            ], 'coupon-ok'));
        $payload = $this->payload();
        $payload['affiliate_name'] = 'Custom Affiliate Name';
        $payload['affiliate_url_slug'] = 'creator-login';
        $payload['affiliate_coupon_expiry'] = '2032-12-31';
        $this->actingAs($specialist)->post('/kol/library', $payload)->assertSessionHas('success');
        self::assertSame('Custom Affiliate Name', KolAffiliateAccount::query()->sole()->display_name);
    }

    public function test_independent_affiliate_url_slug_without_provider_support_is_rejected_before_create(): void
    {
        $specialist = $this->specialist();
        $mock = $this->mockGateway();
        $mock->shouldNotReceive('checkAffiliateIdentity');
        $mock->shouldNotReceive('checkCoupon');
        $mock->shouldNotReceive('provisionAffiliate');
        $mock->shouldNotReceive('provisionCoupon');
        $payload = $this->payload();
        $payload['affiliate_url_slug'] = 'different-link';
        $this->actingAs($specialist)->from('/kol/library/create')
            ->post('/kol/library', $payload)
            ->assertRedirect('/kol/library/create')
            ->assertSessionHasErrors('affiliate_url_slug');
        self::assertSame(0, Kol::query()->count());
    }

    public function test_custom_affiliate_url_with_uppercase_is_rejected_before_creating_a_kol(): void
    {
        $specialist = $this->specialist();
        $mock = $this->mockGateway();
        $mock->shouldNotReceive('checkAffiliateIdentity');
        $mock->shouldNotReceive('checkCoupon');
        $mock->shouldNotReceive('provisionAffiliate');
        $payload = $this->payload();
        $payload['affiliate_url_slug'] = 'Creator2026';
        $this->actingAs($specialist)->from('/kol/library/create')
            ->post('/kol/library', $payload)
            ->assertRedirect('/kol/library/create')
            ->assertSessionHasErrors('affiliate_url_slug');
        self::assertSame(0, Kol::query()->count());
    }

    public function test_password_policy_is_enforced_before_any_provider_call(): void
    {
        $specialist = $this->specialist();
        $mock = $this->mockGateway();
        $mock->shouldNotReceive('checkAffiliateIdentity');
        $mock->shouldNotReceive('checkCoupon');
        $mock->shouldNotReceive('provisionAffiliate');
        $mock->shouldNotReceive('provisionCoupon');
        $payload = $this->payload();
        $payload['wordpress_password'] = 'short1';
        $payload['wordpress_password_confirmation'] = 'short1';

        $this->actingAs($specialist)->from('/kol/library/create')
            ->post('/kol/library', $payload)
            ->assertRedirect('/kol/library/create')
            ->assertSessionHasErrors('wordpress_password');

        self::assertSame(0, Kol::query()->count());
    }

    public function test_affiliate_readback_failure_keeps_kol_and_moves_approval_to_pending_with_notification(): void
    {
        $specialist = $this->specialist();
        $mock = $this->mockGateway();
        $mock->shouldReceive('checkAffiliateIdentity')->once()
            ->andReturn($this->response(['available' => true], 'identity-ok'));
        $mock->shouldReceive('checkCoupon')->once()->with('NEWKOL15')
            ->andReturn($this->response(['available' => true], 'coupon-ok'));
        $mock->shouldReceive('provisionAffiliate')->once()
            ->andReturn($this->response([
                'affiliate_id' => 93001,
                'wp_user_id' => 73001,
                'affiliate_status' => 'pending',
            ], 'affiliate-readback'));
        $mock->shouldNotReceive('provisionCoupon');

        $response = $this->actingAs($specialist)->post('/kol/library', $this->payload());
        $kol = Kol::query()->where('contact_email', 'new-kol@example.test')->firstOrFail();

        $response->assertRedirect(route('kol.library.show', $kol));
        $response->assertSessionHas('warning');
        self::assertSame('pending', $kol->fresh()->approval_status);
        self::assertSame(['approved', 'pending'], KolApprovalDecision::query()->where('kol_id', $kol->id)->orderBy('id')->pluck('outcome')->all());
        self::assertSame('readback_mismatch', KolAffiliateAccount::query()->where('kol_id', $kol->id)->firstOrFail()->provisioning_status);
        self::assertDatabaseCount('notifications', 1);
        self::assertSame(0, KolAffiliateCoupon::query()->where('kol_id', $kol->id)->count());
    }

    public function test_missing_coupon_policy_blocks_before_kol_creation(): void
    {
        $specialist = $this->specialist();
        $mock = $this->mockGateway();
        config(['kol.create_auto_provision.coupon_amount' => null]);
        $mock->shouldNotReceive('checkAffiliateIdentity');
        $mock->shouldNotReceive('checkCoupon');

        $this->actingAs($specialist)->from('/kol/library/create')
            ->post('/kol/library', $this->payload())
            ->assertRedirect('/kol/library/create')
            ->assertSessionHasErrors('affiliate_coupon_code');

        self::assertSame(0, Kol::query()->count());
    }
}
