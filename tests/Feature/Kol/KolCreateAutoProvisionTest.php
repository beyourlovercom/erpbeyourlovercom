<?php

namespace Tests\Feature\Kol;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Application\AffiliateWp\ConnectorResponse;
use App\Modules\Kol\Application\AffiliateWp\KolProvisioningService;
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
            'wordpress_password' => 'Abcdefg',
            'wordpress_password_confirmation' => 'Abcdefg',
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

        self::assertSame('Abcdefg', $capturedAffiliatePayload['password']);
        self::assertSame('reject', $capturedAffiliatePayload['existing_account_policy']);
        self::assertSame('new-kol@example.test', $capturedAffiliatePayload['email']);
        self::assertSame('creator-login', $capturedAffiliatePayload['preferred_username']);
        self::assertSame('creator-login', $capturedAffiliatePayload['display_name']);
        self::assertArrayNotHasKey('affiliate_url_slug', $capturedAffiliatePayload,
            'An Affiliate URL equal to Username must use the default referral identifier, not Custom Slugs.');

        self::assertDatabaseCount('notifications', 1);

        $persisted = DB::table('audit_records')->get()->toJson()
            .DB::table('notifications')->get()->toJson()
            .DB::table('kol_affiliate_accounts')->get()->toJson()
            .DB::table('kol_affiliate_coupons')->get()->toJson();
        self::assertStringNotContainsString('Abcdefg', $persisted);
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

    public function test_manual_wordpress_username_is_not_reformatted_or_length_limited_by_erp(): void
    {
        self::assertSame('_dolordemuelas_', KolProvisioningService::resolveUsername('_dolordemuelas_', 'fallback'));
        self::assertSame(' Spaces AND @punctuation! ', KolProvisioningService::resolveUsername(' Spaces AND @punctuation! ', 'fallback'));
        self::assertSame(str_repeat('X', 300), KolProvisioningService::resolveUsername(str_repeat('X', 300), 'fallback'));
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

    public function test_leading_underscore_affiliate_url_passes_erp_validation_to_provider_preflight(): void
    {
        $specialist = $this->specialist();
        $mock = $this->mockGateway();
        $mock->shouldReceive('checkAffiliateIdentity')->once()
            ->andReturn($this->response(['available' => false], 'provider-preflight'));
        $mock->shouldNotReceive('checkCoupon');
        $mock->shouldNotReceive('provisionAffiliate');
        $payload = $this->payload();
        $payload['wordpress_username'] = '_dolordemuelas_';
        $payload['affiliate_url_slug'] = '_dolordemuelas_';

        $this->actingAs($specialist)->from('/kol/library/create')
            ->post('/kol/library', $payload)
            ->assertSessionHasErrors('contact_email')
            ->assertSessionDoesntHaveErrors(['wordpress_username', 'affiliate_url_slug']);
        self::assertSame(0, Kol::query()->count());
    }

    public function test_custom_affiliate_url_keeps_operator_case_characters_and_length(): void
    {
        $requestedSlug = ' Creator-2026_? '.str_repeat('X', 100);
        $profile = KolProvisioningService::resolveAffiliateProfile(
            'Affiliate Display', $requestedSlug, 'original_username'
        );
        self::assertSame('Affiliate Display', $profile['name']);
        self::assertSame($requestedSlug, $profile['slug']);
    }

    public function test_password_policy_requires_english_letter_first_and_more_than_six_characters(): void
    {
        $specialist = $this->specialist();
        $mock = $this->mockGateway();
        $mock->shouldNotReceive('checkAffiliateIdentity');
        $mock->shouldNotReceive('checkCoupon');
        $mock->shouldNotReceive('provisionAffiliate');
        $mock->shouldNotReceive('provisionCoupon');

        foreach (['1Abcdef', 'Abcdef', 'shor1'] as $invalidPassword) {
            $payload = $this->payload();
            $payload['wordpress_password'] = $invalidPassword;
            $payload['wordpress_password_confirmation'] = $invalidPassword;

            $this->actingAs($specialist)->from('/kol/library/create')
                ->post('/kol/library', $payload)
                ->assertRedirect('/kol/library/create')
                ->assertSessionHasErrors('wordpress_password');
        }

        self::assertSame(0, Kol::query()->count());
    }

    public function test_letter_first_password_without_digits_or_length_ceiling_reaches_identity_preflight(): void
    {
        $specialist = $this->specialist();
        $mock = $this->mockGateway();
        $mock->shouldReceive('checkAffiliateIdentity')->once()
            ->andReturn($this->response(['available' => false], 'identity-exists'));
        $mock->shouldNotReceive('checkCoupon');
        $mock->shouldNotReceive('provisionAffiliate');
        $mock->shouldNotReceive('provisionCoupon');

        $payload = $this->payload();
        $payload['wordpress_password'] = 'A'.str_repeat('b', 180);
        $payload['wordpress_password_confirmation'] = $payload['wordpress_password'];

        $this->actingAs($specialist)->from('/kol/library/create')
            ->post('/kol/library', $payload)
            ->assertSessionHasErrors('contact_email')
            ->assertSessionDoesntHaveErrors('wordpress_password');
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
        $response->assertSessionHas('error', fn ($value) => str_contains($value, '自动开户失败')
            && str_contains($value, 'AffiliateWP 账号未确认 Active')
            && str_contains($value, 'READBACK_MISMATCH')
            && str_contains($value, 'WordPress 核查'));
        self::assertSame('pending', $kol->fresh()->approval_status);
        self::assertSame(['approved', 'pending'], KolApprovalDecision::query()->where('kol_id', $kol->id)->orderBy('id')->pluck('outcome')->all());
        self::assertSame('readback_mismatch', KolAffiliateAccount::query()->where('kol_id', $kol->id)->firstOrFail()->provisioning_status);
        self::assertDatabaseCount('notifications', 1);
        $this->actingAs($specialist)->get('/kol/library/'.$kol->id)->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('affiliateReady', false)
                ->where('affiliateAdminUrl', 'https://www.beyourlover.com/wp-admin/admin.php?page=affiliate-wp-affiliates&affiliate_id=93001&action=edit_affiliate')
                ->where('kolCreateFailureReason', 'affiliate_readback_mismatch')
                ->where('affiliateAccount.last_error_code', 'READBACK_MISMATCH'));
        $notification = json_decode((string) DB::table('notifications')->firstOrFail()->data, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('网红自动开户失败 · 需核查 WordPress', $notification['title']);
        self::assertStringContainsString('READBACK_MISMATCH', $notification['message']);
        self::assertStringContainsString('WordPress', $notification['message']);
        self::assertStringNotContainsString('Abcdefg', json_encode($notification));
        self::assertSame(0, KolAffiliateCoupon::query()->where('kol_id', $kol->id)->count());
    }

    public function test_coupon_readback_failure_shows_explicit_failure_without_new_affiliate_or_coupon_retry(): void
    {
        $specialist = $this->specialist();
        $mock = $this->mockGateway();
        $mock->shouldReceive('checkAffiliateIdentity')->once()
            ->andReturn($this->response(['available' => true], 'identity-ok'));
        $mock->shouldReceive('checkCoupon')->twice()
            ->andReturn($this->response(['available' => true], 'coupon-ok'));
        $mock->shouldReceive('provisionAffiliate')->once()
            ->andReturn($this->response(['affiliate_id' => 93088, 'wp_user_id' => 73088, 'affiliate_status' => 'active', 'affiliate_url_slug' => 'creator-login'], 'affiliate-ok'));
        $mock->shouldReceive('provisionCoupon')->once()
            ->andReturn($this->response(['coupon_id' => 94088, 'code' => 'WRONGCODE', 'affiliate_id' => 93088], 'coupon-mismatch'));

        $response = $this->actingAs($specialist)->post('/kol/library', $this->payload());
        $kol = Kol::query()->where('contact_email', 'new-kol@example.test')->firstOrFail();
        $response->assertRedirect(route('kol.library.show', $kol))
            ->assertSessionHas('error', fn ($value) => str_contains($value, 'WooCommerce Coupon 未确认创建成功')
                && str_contains($value, 'READBACK_MISMATCH'));
        self::assertSame('pending', $kol->fresh()->approval_status);
        self::assertSame('active', KolAffiliateAccount::where('kol_id', $kol->id)->firstOrFail()->provisioning_status);
        self::assertSame('readback_mismatch', KolAffiliateCoupon::where('kol_id', $kol->id)->firstOrFail()->provisioning_status);
        $this->actingAs($specialist)->get('/kol/library/'.$kol->id)->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('affiliateReady', true)
                ->where('kolCreateFailureReason', 'coupon_readback_mismatch')
                ->where('affiliateAccount.coupon.last_error_code', 'READBACK_MISMATCH'));
        self::assertDatabaseCount('notifications', 1);
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

    public function test_order_onboarding_creates_and_approves_profile_before_resuming_draft(): void
    {
        $this->withoutVite();
        $specialist = $this->specialist();
        $specialist->roles()->firstOrFail()->permissions()->attach(
            Permission::firstOrCreate(['key'=>'orders.create'], ['label'=>'orders.create'])
        );
        $this->actingAs($specialist)->post('/kol/onboarding-draft', [
            'smart'=>['username_hint'=>'creator-login','product_hint'=>'Synthetic Product'],
            'order'=>['currency'=>'USD'],
        ])->assertRedirect();
        $token = session('kol.onboarding.draft.token');

        $mock = $this->mockGateway();
        $mock->shouldReceive('checkAffiliateIdentity')->once()->andReturn($this->response(['available'=>true], 'draft-identity'));
        $mock->shouldReceive('checkCoupon')->twice()->andReturn($this->response(['available'=>true,'normalized_code'=>'newkol15'], 'draft-coupon-check'));
        $mock->shouldReceive('provisionAffiliate')->once()->andReturn($this->response([
            'affiliate_id'=>91001,'wp_user_id'=>71001,'affiliate_status'=>'active',
            'affiliate_url_slug'=>'creator-login',
        ],'draft-affiliate'));
        $mock->shouldReceive('provisionCoupon')->once()->andReturnUsing(fn (array $payload) => $this->response([
            'coupon_id'=>92001,'code'=>$payload['code'],'normalized_code'=>'newkol15',
            'affiliate_id'=>91001,'discount_type'=>'percent','amount'=>'15','date_expires'=>$payload['expiry'],
        ],'draft-coupon'));

        $response = $this->post('/kol/library', array_merge($this->payload(), ['onboarding_token'=>$token]));
        $kol = Kol::query()->where('display_name','New Auto KOL')->sole();
        $response->assertRedirect(route('kol.orders.create',['kol_id'=>$kol->id,'onboarding_token'=>$token]));
        self::assertSame('approved',$kol->approval_status);
        self::assertSame('active',KolAffiliateAccount::query()->where('kol_id',$kol->id)->sole()->provisioning_status);
        self::assertSame('active',KolAffiliateCoupon::query()->where('kol_id',$kol->id)->sole()->provisioning_status);
        self::assertDatabaseCount('orders',0);
        $this->get(route('kol.orders.create',['kol_id'=>$kol->id,'onboarding_token'=>$token]))
            ->assertOk()->assertInertia(fn($page)=>$page
                ->where('selectedKolId',$kol->id)
                ->where('resumeOrderDraft.smart.product_hint','Synthetic Product'));
    }
}
