<?php

namespace Tests\Feature\Orders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class OrderOmsProductionGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Simulating Production environment inside PHPUnit activates CSRF middleware;
        // bypass only CSRF in this feature test to reach the real authorization gates.
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
        $this->withHeader('Accept', 'application/json');
    }

    private function user(): User
    {
        $role = Role::create(['key' => 'oms-prod-canary', 'label' => 'OMS Production Canary']);
        foreach (['orders.view', 'orders.create'] as $key) {
            $role->permissions()->firstOrCreate(['key' => $key], ['label' => $key]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    private function asProduction(callable $test): void
    {
        $previous = app()->environment();
        app()->detectEnvironment(fn (): string => 'production');
        try {
            $test();
        } finally {
            app()->detectEnvironment(fn (): string => $previous);
        }
    }

    public function test_production_defaults_fail_closed_even_with_oms_query_and_order_permissions(): void
    {
        $user = $this->user();
        $this->asProduction(function () use ($user): void {
            config()->set('oms_grid.production_enabled', false);
            config()->set('oms_grid.production_user_ids', (string) $user->id);
            config()->set('oms_grid.production_export_enabled', false);
            config()->set('oms_grid.production_import_enabled', false);
            $this->actingAs($user)->get('/orders?oms_grid=1')
                ->assertInertia(fn (Assert $page) => $page
                    ->where('omsGridAvailable', false)
                    ->where('omsGridEnabled', false));
            $this->actingAs($user)->get('/orders/oms/export')->assertNotFound();
            $this->actingAs($user)->get('/orders/oms/import/template')->assertNotFound();
            $this->actingAs($user)->post('/orders/oms/import/preview', [])->assertNotFound();
            $this->actingAs($user)->post('/orders/oms/import/confirm', [])->assertNotFound();
            $this->assertDatabaseCount('orders', 0);
        });
    }

    public function test_allowlist_is_mandatory_before_readonly_grid_canary_opens(): void
    {
        $user = $this->user();
        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->roles()->attach($user->roles()->first()->id);
        $this->asProduction(function () use ($user, $outsider): void {
            config()->set('oms_grid.production_enabled', true);
            config()->set('oms_grid.production_user_ids', ' ,invalid,-1,  ');
            $this->actingAs($user)->get('/orders?oms_grid=1')
                ->assertInertia(fn (Assert $page) => $page->where('omsGridEnabled', false));
            config()->set('oms_grid.production_user_ids', (string) $user->id);
            $this->actingAs($outsider)->get('/orders?oms_grid=1')
                ->assertInertia(fn (Assert $page) => $page->where('omsGridEnabled', false));
            $this->actingAs($user)->get('/orders?oms_grid=1')
                ->assertInertia(fn (Assert $page) => $page
                    ->where('omsGridAvailable', true)
                    ->where('omsGridEnabled', true)
                    ->where('canImportOmsOrders', false)
                    ->where('canExportOmsOrders', false));
            $this->actingAs($user)->get('/orders/oms/export')->assertNotFound();
            $this->actingAs($user)->get('/orders/oms/import/template')->assertNotFound();
        });
    }

    public function test_import_and_export_are_independent_production_canary_switches(): void
    {
        $user = $this->user();
        $this->asProduction(function () use ($user): void {
            config()->set('oms_grid.production_enabled', true);
            config()->set('oms_grid.production_user_ids', (string) $user->id);
            config()->set('oms_grid.production_export_enabled', true);
            config()->set('oms_grid.production_import_enabled', false);
            $this->actingAs($user)->get('/orders?oms_grid=1')
                ->assertInertia(fn (Assert $page) => $page
                    ->where('canExportOmsOrders', true)
                    ->where('canImportOmsOrders', false));
            $this->actingAs($user)->get('/orders/oms/export?format=csv')->assertOk();
            $this->actingAs($user)->get('/orders/oms/import/template')->assertNotFound();

            config()->set('oms_grid.production_import_enabled', true);
            $this->actingAs($user)->get('/orders?oms_grid=1')
                ->assertInertia(fn (Assert $page) => $page->where('canImportOmsOrders', true));
            $this->actingAs($user)->get('/orders/oms/import/template')->assertOk();
            $this->actingAs($user)->post('/orders/oms/import/preview', [])
                ->assertUnprocessable(); // endpoint opened, but file validation is still enforced
            $this->assertDatabaseCount('orders', 0);
        });
    }
}
