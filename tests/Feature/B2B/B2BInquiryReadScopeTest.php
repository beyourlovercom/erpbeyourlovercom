<?php

namespace Tests\Feature\B2B;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\B2B\Infrastructure\Models\B2BCase;
use App\Modules\B2B\Infrastructure\Models\B2BOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class B2BInquiryReadScopeTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(string $roleKey, array $keys): User
    {
        $role = Role::create(['key' => $roleKey, 'label' => $roleKey]);
        foreach ($keys as $key) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key]));
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }

    public function test_unassigned_and_other_owner_cases_are_not_visible_to_salesperson(): void
    {
        $first = $this->userWith('b2b-case-sales-a', ['b2b.view']);
        $second = $this->userWith('b2b-case-sales-b', ['b2b.view']);
        $org = B2BOrganization::create(['display_name' => 'Example Company', 'owner_id' => $first->id]);

        $visible = B2BCase::create([
            'organization_id' => $org->id, 'owner_id' => $first->id, 'title' => 'Visible Inquiry',
            'source_channel' => 'manual', 'first_inbound_at' => now(), 'last_inbound_at' => now(),
        ]);
        $invisible = B2BCase::create([
            'organization_id' => $org->id, 'owner_id' => $second->id, 'title' => 'Private Inquiry',
            'source_channel' => 'manual', 'first_inbound_at' => now(), 'last_inbound_at' => now(),
        ]);

        $this->actingAs($first)->get('/b2b/inquiries')->assertOk()->assertSee('Visible Inquiry')->assertDontSee('Private Inquiry');
        $this->actingAs($first)->get("/b2b/cases/{$visible->id}")->assertOk();
        $this->actingAs($first)->get("/b2b/cases/{$invisible->id}")->assertNotFound();
    }

    public function test_consumer_only_user_cannot_open_inquiries_or_case_details(): void
    {
        $customer = $this->userWith('consumer-no-b2b', ['customers.view']);
        $org = B2BOrganization::create(['display_name' => 'Example B2B']);
        $case = B2BCase::create([
            'organization_id' => $org->id, 'title' => 'Confidential B2B Lead',
            'source_channel' => 'manual', 'first_inbound_at' => now(), 'last_inbound_at' => now(),
        ]);
        $this->actingAs($customer)->get('/b2b/inquiries')->assertForbidden();
        $this->actingAs($customer)->get("/b2b/cases/{$case->id}")->assertForbidden();
    }
}
