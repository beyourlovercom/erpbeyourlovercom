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

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'xlwms-test-'.count($permissions), 'label' => 'Test']);
        foreach ($permissions as $key) {
            $role->permissions()->create(['key' => $key, 'label' => $key]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    public function test_unauthorized_users_cannot_view_or_verify_keys(): void
    {
        Http::fake();
        $this->get('/logistics/integrations/xlwms')->assertRedirect('/login');
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $this->actingAs($user)->get('/logistics/integrations/xlwms')->assertForbidden();
        $this->actingAs($user)->postJson('/logistics/integrations/xlwms/verify', [
            'app_key' => 'test-app-key',
            'app_secret' => 'test-app-secret',
        ])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_admin_sees_form_but_secrets_are_never_rendered(): void
    {
        $admin = $this->userWith(['system.permissions.manage']);
        config()->set('xlwms.manual_probe_enabled', true);
        $this->actingAs($admin)->get('/logistics/integrations/xlwms')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Logistics/Integrations/XlwmsCredential')
                ->where('probeEnabled', true)
                ->missing('appKey')
                ->missing('appSecret'));
    }

    public function test_manual_test_stays_off_by_default_even_with_credentials(): void
    {
        $admin = $this->userWith(['system.permissions.manage']);
        config()->set('xlwms.manual_probe_enabled', false);
        Http::fake();
        $this->actingAs($admin)->postJson('/logistics/integrations/xlwms/verify', [
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

        $this->actingAs($admin)->postJson('/logistics/integrations/xlwms/verify', [
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
        $this->actingAs($admin)->postJson('/logistics/integrations/xlwms/verify', [
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
        $this->actingAs($admin)->post('/logistics/integrations/xlwms/verify', [
            'app_key' => 'short',
            'app_secret' => 'SUPER-SECRET-DO-NOT-FLASH',
        ])->assertStatus(422)->assertJsonPath('status', 'invalid_credentials_format');
        $this->assertStringNotContainsString('SUPER-SECRET-DO-NOT-FLASH', json_encode(session()->all()));
        Http::assertNothingSent();
    }
}
