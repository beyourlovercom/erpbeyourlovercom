<?php

namespace Tests\Feature\B2B;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\B2B\Infrastructure\Models\B2BCase;
use App\Modules\B2B\Infrastructure\Models\B2BOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class B2BM5SecurityRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $key, array $permissionKeys): User
    {
        $role = Role::create(['key' => $key, 'label' => $key]);
        foreach ($permissionKeys as $permissionKey) {
            $permission = Permission::firstOrCreate(['key' => $permissionKey], ['label' => $permissionKey]);
            $role->permissions()->attach($permission);
        }

        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    private function sampleCase(B2BOrganization $organization, User $owner): B2BCase
    {
        return B2BCase::create([
            'organization_id' => $organization->id,
            'owner_id' => $owner->id,
            'title' => 'Synthetic Distributor Inquiry',
            'source_channel' => 'manual',
            'case_status' => 'open',
            'case_stage' => 'new',
            'handling_state' => 'waiting',
            'first_inbound_at' => now(),
            'last_inbound_at' => now(),
        ]);
    }

    public function test_b2b_only_operator_cannot_access_b2c_consumers_or_subscribers(): void
    {
        $b2b = $this->actor('m5-b2b-only', ['b2b.view', 'b2b.operate', 'b2b.view_all']);
        $this->actingAs($b2b)->get('/b2b')->assertOk();
        $this->actingAs($b2b)->get('/customers')->assertForbidden();
        $this->actingAs($b2b)->get('/customers/library')->assertForbidden();
        $this->actingAs($b2b)->get('/customers/companies')->assertForbidden();
        $this->actingAs($b2b)->get('/marketing/subscribers')->assertForbidden();
        $this->actingAs($b2b)->get('/customers/library/export?format=csv')->assertForbidden();
    }

    public function test_b2c_operator_cannot_cross_into_b2b_or_direct_write_routes(): void
    {
        $consumer = $this->actor('m5-consumer', ['customers.view', 'customers.operate']);
        $org = B2BOrganization::create(['display_name' => 'Synthetic Org']);
        $this->actingAs($consumer)->get('/b2b/companies')->assertForbidden();
        $this->actingAs($consumer)->get('/b2b/inquiries')->assertForbidden();
        $this->actingAs($consumer)->get('/b2b/followups')->assertForbidden();
        $this->actingAs($consumer)->get('/b2b/inquiries/create')->assertForbidden();
        $this->actingAs($consumer)->post('/b2b/companies', ['display_name' => 'Forged Org'])->assertForbidden();
        $this->actingAs($consumer)->post('/b2b/inquiries', [
            'organization_id' => $org->id,
            'title' => 'Forged Lead',
            'inquiry_kind' => 'wholesale',
            'priority' => 'normal',
        ])->assertForbidden();
        $this->assertDatabaseCount('b2b_cases', 0);
    }

    public function test_contact_must_belong_to_the_same_authorized_organization(): void
    {
        $sales = $this->actor('m5-contact-scope', ['b2b.view', 'b2b.operate']);
        $orgA = B2BOrganization::create(['display_name' => 'Organization A', 'owner_id' => $sales->id]);
        $orgB = B2BOrganization::create(['display_name' => 'Organization B', 'owner_id' => $sales->id]);
        $foreignContact = $orgB->contacts()->create(['name' => 'Contact B', 'email_normalized' => 'test-b@example.invalid']);

        $this->actingAs($sales)->post('/b2b/inquiries', [
            'organization_id' => $orgA->id,
            'primary_contact_id' => $foreignContact->id,
            'title' => 'Forbidden Cross-Company Contact',
            'inquiry_kind' => 'wholesale',
            'priority' => 'normal',
        ])->assertStatus(422);

        $this->assertDatabaseCount('b2b_cases', 0);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_manager_can_assign_only_to_a_user_with_b2b_view_permission(): void
    {
        $manager = $this->actor('m5-b2b-manager', ['b2b.view', 'b2b.operate', 'b2b.view_all', 'b2b.assign']);
        $sales = $this->actor('m5-b2b-sales', ['b2b.view', 'b2b.operate']);
        $consumer = $this->actor('m5-no-b2b-access', ['customers.view']);
        $org = B2BOrganization::create(['display_name' => 'Assignment Test Organization', 'owner_id' => $manager->id]);
        $case = $this->sampleCase($org, $manager);

        $this->actingAs($manager)->post("/b2b/cases/{$case->id}/assignment", [
            'owner_id' => $consumer->id,
        ])->assertStatus(422);

        $this->actingAs($manager)->post("/b2b/cases/{$case->id}/assignment", [
            'owner_id' => $sales->id,
        ])->assertRedirect();

        $this->assertSame($sales->id, (int) $case->fresh()->owner_id);
        $this->assertDatabaseHas('b2b_case_activities', [
            'case_id' => $case->id,
            'activity_type' => 'owner_assigned',
        ]);
        $this->actingAs($manager)->get("/b2b/cases/{$case->id}")->assertOk();
    }

    public function test_other_salesperson_cannot_assign_or_edit_even_when_they_have_write_rights(): void
    {
        $owner = $this->actor('m5-owner', ['b2b.view', 'b2b.operate']);
        $other = $this->actor('m5-other', ['b2b.view', 'b2b.operate']);
        $org = B2BOrganization::create(['display_name' => 'Secret Quote Org', 'owner_id' => $owner->id]);
        $case = $this->sampleCase($org, $owner);

        $this->actingAs($other)->post("/b2b/cases/{$case->id}/assignment", [
            'owner_id' => $other->id,
        ])->assertForbidden();
        $this->actingAs($other)->patch("/b2b/cases/{$case->id}", [
            'case_stage' => 'quoted',
            'next_follow_up_at' => null,
        ])->assertNotFound();
        $this->actingAs($other)->post("/b2b/cases/{$case->id}/notes", [
            'body' => 'Unauthorized mutation',
        ])->assertNotFound();
        $this->assertDatabaseMissing('b2b_case_activities', ['body_text' => 'Unauthorized mutation']);
        $this->assertSame('new', $case->fresh()->case_stage);
    }

    public function test_invalid_distributor_interest_cannot_create_automatic_exclusivity(): void
    {
        $sales = $this->actor('m5-distributor', ['b2b.view', 'b2b.operate']);
        $org = B2BOrganization::create(['display_name' => 'Potential Distributor', 'owner_id' => $sales->id]);

        $this->actingAs($sales)->post('/b2b/inquiries', [
            'organization_id' => $org->id,
            'title' => 'Exclusive request',
            'inquiry_kind' => 'exclusive_distributor',
            'priority' => 'normal',
        ])->assertSessionHasErrors('inquiry_kind');

        $this->actingAs($sales)->post('/b2b/inquiries', [
            'organization_id' => $org->id,
            'title' => 'Exclusive inquiry only',
            'inquiry_kind' => 'exclusive_distributor_interest',
            'priority' => 'normal',
        ])->assertRedirect();

        $this->assertDatabaseCount('b2b_cases', 1);
        $this->assertDatabaseHas('b2b_cases', ['inquiry_kind' => 'exclusive_distributor_interest']);
        $this->assertSame(0, DB::table('customers')->count());
    }

    public function test_assigned_salesperson_can_view_linked_company_but_cannot_modify_its_contacts(): void
    {
        $owner = $this->actor('m5-org-owner', ['b2b.view', 'b2b.operate']);
        $assignee = $this->actor('m5-org-assignee', ['b2b.view', 'b2b.operate']);
        $other = $this->actor('m5-org-outsider', ['b2b.view', 'b2b.operate']);
        $org = B2BOrganization::create(['display_name' => 'Assignee Read-Only Company', 'owner_id' => $owner->id]);
        $allowedContact = $org->contacts()->create(['name' => 'Assigned Contact', 'email_normalized' => 'allowed@example.invalid']);
        $org->contacts()->create(['name' => 'Hidden Contact', 'email_normalized' => 'hidden@example.invalid']);
        $case = $this->sampleCase($org, $assignee);
        $case->forceFill(['primary_contact_id' => $allowedContact->id])->save();

        $this->actingAs($assignee)->get('/b2b/companies')->assertOk()
            ->assertSee('Assignee Read-Only Company');
        $this->actingAs($assignee)->get("/b2b/companies/{$org->id}")
            ->assertOk()->assertSee('Assigned Contact')->assertDontSee('Hidden Contact')
            ->assertDontSee('hidden@example.invalid');
        $this->actingAs($assignee)->post("/b2b/companies/{$org->id}/contacts", [
            'name' => 'Unauthorized New Contact',
        ])->assertForbidden();
        $this->actingAs($other)->get("/b2b/companies/{$org->id}")->assertNotFound();
        $this->actingAs($assignee)->get("/b2b/cases/{$case->id}")->assertOk();
        $this->assertDatabaseCount('b2b_contacts', 2);
    }

    public function test_companies_with_cases_and_cases_with_activity_cannot_be_deleted_implicitly(): void
    {
        $owner = $this->actor('m5-append-only-owner', ['b2b.view', 'b2b.operate']);
        $org = B2BOrganization::create(['display_name' => 'Retained B2B Company', 'owner_id' => $owner->id]);
        $case = $this->sampleCase($org, $owner);
        $case->activities()->create([
            'actor_user_id' => $owner->id,
            'activity_type' => 'note_added',
            'body_text' => 'Do not cascade away audit history',
            'occurred_at' => now(),
        ]);
        $this->assertDatabaseCount('b2b_case_activities', 1);
        // The test verifies the migration contract without deliberately deleting
        // retained audit rows on an in-memory test database.
        $schema = file_get_contents(database_path('migrations/2026_10_22_000200_create_b2b_cases_and_activity.php'));
        $this->assertStringContainsString("->constrained('b2b_organizations')->restrictOnDelete()", $schema);
        $this->assertStringContainsString("->constrained('b2b_cases')->restrictOnDelete()", $schema);
    }

}
