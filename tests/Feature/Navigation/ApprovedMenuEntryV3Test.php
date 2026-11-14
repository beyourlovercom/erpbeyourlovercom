<?php

namespace Tests\Feature\Navigation;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ApprovedMenuEntryV3Test extends TestCase
{
    use RefreshDatabase;

    public function test_accounting_is_renamed_without_losing_children_or_route(): void
    {
        $accounting = collect(config('erp_shell_navigation'))->firstWhere('key', 'accounting');
        self::assertSame('算账', $accounting['label']);
        self::assertSame('/accounting', $accounting['path']);
        self::assertCount(3, $accounting['children']);
        self::assertSame('算账', config('erp_navigation.accounting.label'));
    }

    public function test_p4_link_is_only_offered_to_users_with_every_existing_route_permission(): void
    {
        $manager = $this->userWith(['kol.view', 'kol.operate', 'kol.affiliates.manage', 'kol.approvals.manage']);
        $this->actingAs($manager)->get('/kol/import-preview')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Kol/ImportPreview')
                ->where('canOpenImportActivation', false)
        );
        $this->actingAs($manager)->get('/kol/import-activation')->assertForbidden();

        $admin = $this->userWith(['kol.view', 'kol.operate', 'kol.affiliates.manage', 'kol.approvals.manage', 'system.permissions.manage']);
        $this->actingAs($admin)->get('/kol/import-preview')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Kol/ImportPreview')
                ->where('canOpenImportActivation', true)
        );
    }

    public function test_p5_link_does_not_grant_affiliate_manager_access_to_activation(): void
    {
        $manager = $this->userWith(['kol.view', 'kol.operate', 'kol.affiliates.manage']);
        $this->actingAs($manager)->get('/kol/affiliatewp')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Kol/AffiliateWp/Index')
                ->where('canOpenBindingActivation', false)
        );
        $this->actingAs($manager)->get('/kol/affiliatewp/binding-activation')->assertForbidden();

        $admin = $this->userWith(['kol.view', 'kol.operate', 'kol.affiliates.manage', 'system.permissions.manage']);
        $this->actingAs($admin)->get('/kol/affiliatewp')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Kol/AffiliateWp/Index')
                ->where('canOpenBindingActivation', true)
        );
    }

    public function test_historical_link_only_appears_for_authorized_admins(): void
    {
        $viewer = $this->userWith(['logistics.view']);
        $this->actingAs($viewer)->get('/logistics/wait-stock')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Logistics/FulfillmentAllocation')
                ->where('canViewHistoricalImportTool', false)
        );

        $admin = $this->userWith(['logistics.view', 'system.permissions.manage']);
        $this->actingAs($admin)->get('/logistics/wait-stock')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Logistics/FulfillmentAllocation')
                ->where('canViewHistoricalImportTool', true)
        );
    }

    private function userWith(array $keys): User
    {
        $role = Role::create(['key' => 'approved_menu_v3_'.uniqid(), 'label' => 'Menu V3 Test']);
        foreach ($keys as $key) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key]));
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }
}
