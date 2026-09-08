<?php

namespace Tests\Feature\Finance;

use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FxSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_admin_sees_explicit_auto_and_manual_modes(): void
    {
        Http::fake(['ecb.europa.eu/*' => Http::response($this->ecbXml(), 200)]);
        $admin = $this->userWith(['system.permissions.manage']);

        $this->actingAs($admin)->get('/settings/fx')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Fx')
            ->where('reference.source', 'ECB')
            ->where('reference.referenceDate', '2026-09-07')
            ->where('reference.policy', 'explicit_mode')
            ->where('rows.0.currency', 'USD')
            ->where('rows.0.automatic', true)
            ->where('rows.0.ecbRate', 7.1)
            ->where('rows.0.source', 'ecb'));

        $this->actingAs($admin)->put('/settings/fx', $this->payload(
            rates: ['USD' => 7.12, 'PHP' => 0.125],
            automatic: ['USD' => false, 'PHP' => true],
        ))->assertRedirect();

        $this->assertDatabaseHas('finance_fx_manual_rates', [
            'currency' => 'USD', 'manual_rate_to_cny' => 7.12, 'use_auto' => 0, 'updated_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('finance_fx_manual_rates', [
            'currency' => 'PHP', 'manual_rate_to_cny' => 0.125, 'use_auto' => 1, 'updated_by' => $admin->id,
        ]);

        $this->actingAs($admin)->get('/settings/fx')->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.manualRate', 7.12)
            ->where('rows.0.automatic', false)
            ->where('rows.0.effectiveRate', 7.12)
            ->where('rows.0.source', 'manual')
            ->where('rows.5.currency', 'PHP')
            ->where('rows.5.manualRate', 0.125)
            ->where('rows.5.automatic', true)
            ->where('rows.5.effectiveRate', 0.12203125)
            ->where('rows.5.source', 'ecb'));
    }

    public function test_switching_to_auto_preserves_manual_value_but_uses_ecb(): void
    {
        Http::fake(['ecb.europa.eu/*' => Http::response($this->ecbXml(), 200)]);
        $admin = $this->userWith(['system.permissions.manage']);

        $this->actingAs($admin)->put('/settings/fx', $this->payload(
            rates: ['USD' => 7.12],
            automatic: ['USD' => false],
        ))->assertRedirect();

        $this->actingAs($admin)->put('/settings/fx', $this->payload(
            rates: ['USD' => 7.12],
            automatic: ['USD' => true],
        ))->assertRedirect();

        $this->assertDatabaseHas('finance_fx_manual_rates', [
            'currency' => 'USD', 'manual_rate_to_cny' => 7.12, 'use_auto' => 1,
        ]);
        $this->actingAs($admin)->get('/settings/fx')->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.manualRate', 7.12)
            ->where('rows.0.automatic', true)
            ->where('rows.0.effectiveRate', 7.1)
            ->where('rows.0.source', 'ecb'));
    }

    public function test_manual_mode_requires_a_manual_rate(): void
    {
        Http::fake(['ecb.europa.eu/*' => Http::response($this->ecbXml(), 200)]);
        $admin = $this->userWith(['system.permissions.manage']);

        $this->actingAs($admin)->from('/settings/fx')->put('/settings/fx', $this->payload(
            rates: ['USD' => null],
            automatic: ['USD' => false],
        ))->assertRedirect('/settings/fx')->assertSessionHasErrors('rates.USD');
    }

    public function test_settings_permission_and_rate_validation_are_enforced(): void
    {
        Http::fake(['ecb.europa.eu/*' => Http::response($this->ecbXml(), 200)]);
        $plainUser = $this->userWith([]);
        $admin = $this->userWith(['system.permissions.manage']);

        $this->actingAs($plainUser)->get('/settings/fx')->assertForbidden();
        $this->actingAs($admin)->from('/settings/fx')->put('/settings/fx', $this->payload(
            rates: ['USD' => -1],
            automatic: ['USD' => false],
        ))->assertRedirect('/settings/fx')->assertSessionHasErrors('rates.USD');
        $this->assertDatabaseMissing('finance_fx_manual_rates', ['currency' => 'USD']);
    }

    private function payload(array $rates = [], array $automatic = []): array
    {
        $currencies = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'PHP'];
        return [
            'rates' => collect($currencies)->mapWithKeys(fn (string $currency): array => [$currency => $rates[$currency] ?? null])->all(),
            'automatic' => collect($currencies)->mapWithKeys(fn (string $currency): array => [$currency => $automatic[$currency] ?? true])->all(),
        ];
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::query()->create(['key' => 'fx_'.uniqid(), 'label' => 'FX test role']);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['key' => $permissionName], ['label' => $permissionName]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->attach($role->id);
        return $user;
    }

    private function ecbXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
  <Cube><Cube time="2026-09-07"><Cube currency="USD" rate="1.10"/><Cube currency="GBP" rate="0.85"/><Cube currency="CAD" rate="1.50"/><Cube currency="AUD" rate="1.70"/><Cube currency="PHP" rate="64.00"/><Cube currency="CNY" rate="7.81"/></Cube></Cube>
</gesmes:Envelope>
XML;
    }
}
