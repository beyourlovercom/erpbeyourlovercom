<?php

namespace Tests\Feature\B2B;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\B2B\Infrastructure\Models\B2BOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class B2BCompanyIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function createActor(string $roleKey, array $keys): User
    {
        $role = Role::create(['key' => $roleKey, 'label' => $roleKey]);
        foreach ($keys as $key) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key]));
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    public function test_manual_company_and_contact_do_not_insert_a_consumer_customer(): void
    {
        $sales = $this->createActor('b2b-company-sales', ['b2b.view', 'b2b.operate']);
        $before = \DB::table('customers')->count();

        $this->actingAs($sales)->post('/b2b/companies', [
            'display_name' => 'Example Wholesale LLC',
            'country_code' => 'US',
            'contact_name' => 'Example Buyer',
            'contact_email' => 'buyer@example.invalid',
        ])->assertRedirect();

        $this->assertDatabaseHas('b2b_organizations', ['display_name' => 'Example Wholesale LLC', 'owner_id' => $sales->id]);
        $this->assertDatabaseHas('b2b_contacts', ['email_normalized' => 'buyer@example.invalid']);
        $this->assertSame($before, \DB::table('customers')->count());
    }

    public function test_b2b_organization_is_invisible_to_another_owner_and_consumer_only_role(): void
    {
        $owner = $this->createActor('b2b-company-owner', ['b2b.view', 'b2b.operate']);
        $other = $this->createActor('b2b-company-other', ['b2b.view', 'b2b.operate']);
        $consumer = $this->createActor('b2b-company-consumer', ['customers.view']);
        $org = B2BOrganization::create(['display_name' => 'Private Business', 'owner_id' => $owner->id]);

        $this->actingAs($owner)->get("/b2b/companies/{$org->id}")->assertOk();
        $this->actingAs($other)->get("/b2b/companies/{$org->id}")->assertNotFound();
        $this->actingAs($other)->post("/b2b/companies/{$org->id}/contacts", ['name' => 'Forbidden'])->assertNotFound();
        $this->actingAs($consumer)->get("/b2b/companies/{$org->id}")->assertForbidden();
    }

    public function test_owner_only_list_does_not_leak_other_organizations(): void
    {
        $owner = $this->createActor('b2b-list-owner', ['b2b.view']);
        $other = $this->createActor('b2b-list-other', ['b2b.view']);
        B2BOrganization::create(['display_name' => 'Owned', 'owner_id' => $owner->id]);
        B2BOrganization::create(['display_name' => 'Not Owned', 'owner_id' => $other->id]);

        $this->actingAs($owner)->get('/b2b/companies')->assertOk()
            ->assertSee('Owned')->assertDontSee('Not Owned');
    }
}
