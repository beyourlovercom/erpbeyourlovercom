<?php

namespace Tests\Feature\Logistics;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class XlwmsCredentialPageTest extends TestCase
{
    use RefreshDatabase;

    private int $roleCounter = 0;

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'xlwms-test-'.(++$this->roleCounter), 'label' => 'Test']);
        foreach ($permissions as $key) {
            $role->permissions()->create(['key' => $key, 'label' => $key]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    public function test_warehouse_settings_hub_is_admin_only_and_not_a_placeholder(): void
    {
        $user = $this->userWith(['logistics.view']);
        $this->actingAs($user)->get('/settings/warehouses')->assertForbidden();

        $admin = $this->userWith(['system.permissions.manage']);
        $this->actingAs($admin)->get('/settings/warehouses')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Warehouses/Index')
                ->has('integrations', 1)
                ->where('integrations.0.url', '/settings/warehouses/xlwms')
                ->where('integrations.0.configured', false)
                ->where('integrations.0.auto_dispatch_enabled', false));
    }

    public function test_old_logistics_bookmark_redirects_but_does_not_accept_key_submissions(): void
    {
        Http::fake();
        $admin = $this->userWith(['system.permissions.manage']);
        $this->actingAs($admin)->get('/logistics/integrations/xlwms')
            ->assertRedirect('/settings/warehouses/xlwms');
        $this->actingAs($admin)->postJson('/logistics/integrations/xlwms/verify', [
            'app_key' => 'test-app-key',
            'app_secret' => 'test-app-secret',
        ])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_unauthorized_users_cannot_view_or_verify_keys(): void
    {
        Http::fake();
        $this->get('/settings/warehouses/xlwms')->assertRedirect('/login');
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $this->actingAs($user)->get('/settings/warehouses/xlwms')->assertForbidden();
        $this->actingAs($user)->postJson('/settings/warehouses/xlwms/verify', [
            'app_key' => 'test-app-key',
            'app_secret' => 'test-app-secret',
        ])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_admin_sees_form_but_secrets_are_never_rendered(): void
    {
        $admin = $this->userWith(['system.permissions.manage']);
        config()->set('xlwms.manual_probe_enabled', true);
        $this->actingAs($admin)->get('/settings/warehouses/xlwms')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Warehouses/Xlwms')
                ->where('probeEnabled', true)
                ->missing('appKey')
                ->missing('appSecret'));
    }

    public function test_manual_test_stays_off_by_default_even_with_credentials(): void
    {
        $admin = $this->userWith(['system.permissions.manage']);
        config()->set('xlwms.manual_probe_enabled', false);
        Http::fake();
        $this->actingAs($admin)->postJson('/settings/warehouses/xlwms/verify', [
            'app_key' => 'test-app-key',
            'app_secret' => 'test-app-secret',
        ])->assertOk()->assertJsonPath('status', 'disabled');
        Http::assertNothingSent();
    }

    public function test_manual_test_only_queries_warehouses_and_does_not_persist_secrets(): void
    {
        $admin = $this->userWith(['system.permissions.manage']);
        config()->set('xlwms.manual_probe_enabled', true);
        Http::fake([
            'https://api.xlwms.com/*' => Http::response([
                'code' => 200,
                'data' => [['warehouseCode' => 'W-01']],
            ]),
        ]);

        $this->actingAs($admin)->postJson('/settings/warehouses/xlwms/verify', [
            'app_key' => 'test-app-key',
            'app_secret' => 'test-app-secret',
        ])->assertOk()
            ->assertJsonPath('status', 'verified')
            ->assertJsonPath('warehouse_count', 1)
            ->assertDontSee('test-app-secret')
            ->assertDontSee('test-app-key');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/warehouse/options?authcode=')
            && ! str_contains($request->url(), 'outboundOrder/create')
            && ! str_contains($request->url(), 'test-app-secret'));
        $this->assertStringNotContainsString('test-app-secret', json_encode(session()->all()));
        $this->assertStringNotContainsString('test-app-key', json_encode(session()->all()));
    }

    public function test_business_rejection_does_not_claim_api_authenticated(): void
    {
        $admin = $this->userWith(['system.permissions.manage']);
        config()->set('xlwms.manual_probe_enabled', true);
        Http::fake([
            'https://api.xlwms.com/*' => Http::response(['code' => 40101, 'message' => 'Invalid signature']),
        ]);
        $this->actingAs($admin)->postJson('/settings/warehouses/xlwms/verify', [
            'app_key' => 'test-app-key',
            'app_secret' => 'test-app-secret',
        ])->assertOk()
            ->assertJsonPath('status', 'business_rejected')
            ->assertJsonPath('code', '40101')
            ->assertDontSee('Invalid signature');
    }

    public function test_invalid_input_does_not_redirect_or_flash_credentials(): void
    {
        $admin = $this->userWith(['system.permissions.manage']);
        config()->set('xlwms.manual_probe_enabled', true);
        Http::fake();
        $this->actingAs($admin)->post('/settings/warehouses/xlwms/verify', [
            'app_key' => 'short',
            'app_secret' => 'SUPER-SECRET-DO-NOT-FLASH',
        ])->assertStatus(422)->assertJsonPath('status', 'invalid_credentials_format');
        $this->assertStringNotContainsString('SUPER-SECRET-DO-NOT-FLASH', json_encode(session()->all()));
        Http::assertNothingSent();
    }
}
