<?php

namespace Tests\Feature\Finance;

use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class FxSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_admin_sees_ecb_reference_and_manual_first_effective_rates(): void
    {
        Http::fake(['ecb.europa.eu/*' => Http::response($this->ecbXml(), 200)]);
        $admin = $this->userWith(['system.permissions.manage']);

        $this->actingAs($admin)->get('/settings/fx')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Fx')
            ->where('reference.source', 'ECB')
            ->where('reference.referenceDate', '2026-09-07')
            ->where('reference.stale', false)
            ->where('rows.0.currency', 'USD')
            ->where('rows.0.ecbRate', 7.1)
            ->where('rows.0.source', 'ecb'));

        $this->actingAs($admin)->put('/settings/fx', ['rates' => [
            'USD' => 7.12, 'EUR' => null, 'GBP' => null, 'CAD' => null, 'AUD' => null,
        ]])->assertRedirect();

        $this->assertDatabaseHas('finance_fx_manual_rates', [
            'currency' => 'USD', 'manual_rate_to_cny' => 7.12, 'updated_by' => $admin->id,
        ]);
        $this->actingAs($admin)->get('/settings/fx')->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.manualRate', 7.12)
            ->where('rows.0.effectiveRate', 7.12)
            ->where('rows.0.source', 'manual'));
    }

    public function test_clearing_manual_rate_restores_ecb_without_touching_finance_facts(): void
    {
        Http::fake(['ecb.europa.eu/*' => Http::response($this->ecbXml(), 200)]);
        $admin = $this->userWith(['system.permissions.manage']);
        $this->actingAs($admin)->put('/settings/fx', ['rates' => ['USD' => 7.12]])->assertRedirect();
        $this->actingAs($admin)->put('/settings/fx', ['rates' => ['USD' => null]])->assertRedirect();
        $this->assertDatabaseMissing('finance_fx_manual_rates', ['currency' => 'USD']);
        $this->actingAs($admin)->get('/settings/fx')->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.effectiveRate', 7.1)
            ->where('rows.0.source', 'ecb'));
    }

    public function test_settings_permission_and_rate_validation_are_enforced(): void
    {
        $viewer = $this->userWith([]);
        $this->actingAs($viewer)->get('/settings/fx')->assertForbidden();
        $admin = $this->userWith(['system.permissions.manage']);
        $this->actingAs($admin)->from('/settings/fx')->put('/settings/fx', ['rates' => ['USD' => -1]])
            ->assertRedirect('/settings/fx')->assertSessionHasErrors('rates.USD');
        $this->assertDatabaseMissing('finance_fx_manual_rates', ['currency' => 'USD']);
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'fx-test-'.uniqid(), 'label' => 'FX Test']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->attach($role);
        return $user;
    }

    private function ecbXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
  <Cube><Cube time="2026-09-07"><Cube currency="USD" rate="1.10"/><Cube currency="GBP" rate="0.85"/><Cube currency="CAD" rate="1.50"/><Cube currency="AUD" rate="1.70"/><Cube currency="CNY" rate="7.81"/></Cube></Cube>
</gesmes:Envelope>
XML;
    }
}
