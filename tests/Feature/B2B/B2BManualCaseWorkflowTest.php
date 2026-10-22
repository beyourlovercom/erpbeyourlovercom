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

final class B2BManualCaseWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $key, array $permissions): User
    {
        $role = Role::create(['key' => $key, 'label' => $key]);
        foreach ($permissions as $permissionKey) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $permissionKey], ['label' => $permissionKey]));
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    public function test_same_company_can_create_multiple_manual_cases_without_touching_consumer_table(): void
    {
        $sales = $this->actor('b2b-manual-case-author', ['b2b.view', 'b2b.operate']);
        $org = B2BOrganization::create(['display_name' => 'Test Wholesale Partner', 'owner_id' => $sales->id]);
        $before = DB::table('customers')->count();

        $payload = [
            'organization_id' => $org->id,
            'title' => 'Naughty Clock Trial',
            'product_interest' => 'Naughty Clock',
            'requested_quantity' => 100,
            'inquiry_kind' => 'wholesale',
            'priority' => 'normal',
            'initial_note' => 'Synthetic test inquiry only',
        ];

        $this->actingAs($sales)->post('/b2b/inquiries', $payload)->assertRedirect();
        $this->actingAs($sales)->post('/b2b/inquiries', [
            ...$payload, 'title' => 'Different Product',
        ])->assertRedirect();

        $this->assertDatabaseCount('b2b_cases', 2);
        $this->assertSame($before, DB::table('customers')->count());
        $case = B2BCase::query()->orderBy('id')->firstOrFail();
        $this->assertMatchesRegularExpression('/^CASE-\d{8}-\d{6,}$/', $case->case_number);
        $this->assertSame('manual', $case->source_channel);
        $this->assertDatabaseHas('b2b_case_activities', ['case_id' => $case->id, 'activity_type' => 'note_added']);
        $this->assertDatabaseHas('b2b_case_activities', ['case_id' => $case->id, 'activity_type' => 'case_created']);
    }

    public function test_salesperson_cannot_update_other_owner_case_or_forge_contact_membership(): void
    {
        $owner = $this->actor('b2b-case-write-owner', ['b2b.view', 'b2b.operate']);
        $other = $this->actor('b2b-case-write-other', ['b2b.view', 'b2b.operate']);
        $org = B2BOrganization::create(['display_name' => 'Owner Organization', 'owner_id' => $owner->id]);
        $case = B2BCase::create([
            'organization_id' => $org->id,
            'owner_id' => $owner->id,
            'title' => 'Private Commercial Case',
            'first_inbound_at' => now(), 'last_inbound_at' => now(),
        ]);

        $this->actingAs($other)->patch("/b2b/cases/{$case->id}", [
            'case_stage' => 'quoted', 'next_follow_up_at' => null,
        ])->assertNotFound();
        $this->actingAs($other)->post("/b2b/cases/{$case->id}/notes", [
            'body' => 'Cross-owner injection',
        ])->assertNotFound();
        $this->actingAs($other)->post('/b2b/inquiries', [
            'organization_id' => $org->id, 'title' => 'Forged inquiry',
            'inquiry_kind' => 'wholesale', 'priority' => 'normal',
        ])->assertNotFound();

        $this->assertDatabaseMissing('b2b_case_activities', ['body_text' => 'Cross-owner injection']);
    }

    public function test_notes_stage_and_terminal_status_append_events_without_erasing_history(): void
    {
        $owner = $this->actor('b2b-case-stage-owner', ['b2b.view', 'b2b.operate']);
        $org = B2BOrganization::create(['display_name' => 'Test Company', 'owner_id' => $owner->id]);
        $case = B2BCase::create([
            'organization_id' => $org->id, 'owner_id' => $owner->id,
            'title' => 'Prospect B2B', 'first_inbound_at' => now(), 'last_inbound_at' => now(),
        ]);

        $this->actingAs($owner)->post("/b2b/cases/{$case->id}/notes", ['body' => 'First call completed'])
            ->assertRedirect();
        $this->actingAs($owner)->patch("/b2b/cases/{$case->id}", [
            'case_stage' => 'qualified', 'next_follow_up_at' => null,
        ])->assertRedirect();
        $this->actingAs($owner)->patch("/b2b/cases/{$case->id}", [
            'case_status' => 'lost', 'loss_reason_code' => 'pricing', 'next_follow_up_at' => null,
        ])->assertRedirect();

        $this->assertDatabaseHas('b2b_case_activities', ['case_id' => $case->id, 'body_text' => 'First call completed']);
        $this->assertDatabaseHas('b2b_case_activities', ['case_id' => $case->id, 'activity_type' => 'stage_changed']);
        $this->assertDatabaseHas('b2b_case_activities', ['case_id' => $case->id, 'activity_type' => 'status_changed']);
        $this->assertSame('lost', $case->fresh()->case_status);
        $this->assertSame('none', $case->fresh()->handling_state);
        $this->assertNotNull($case->fresh()->closed_at);
        $this->actingAs($owner)->get("/b2b/cases/{$case->id}")
            ->assertOk()->assertSee('pricing')->assertSee('lost');
    }

    public function test_consumer_role_cannot_create_or_modify_b2b_case(): void
    {
        $consumer = $this->actor('consumer-does-not-operate-b2b', ['customers.view', 'customers.operate']);
        $org = B2BOrganization::create(['display_name' => 'Separated Business']);

        $this->actingAs($consumer)->get('/b2b/inquiries/create')->assertForbidden();
        $this->actingAs($consumer)->post('/b2b/inquiries', [
            'organization_id' => $org->id, 'title' => 'Denied',
            'inquiry_kind' => 'wholesale', 'priority' => 'normal',
        ])->assertForbidden();
        $this->assertDatabaseCount('b2b_cases', 0);
    }
}
