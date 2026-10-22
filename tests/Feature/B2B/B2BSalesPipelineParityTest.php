<?php

namespace Tests\Feature\B2B;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\B2B\Infrastructure\Models\B2BCase;
use App\Modules\B2B\Infrastructure\Models\B2BContact;
use App\Modules\B2B\Infrastructure\Models\B2BOrganization;
use App\Modules\B2B\Infrastructure\Models\B2BSalesOpportunity;
use App\Modules\B2B\Infrastructure\Models\B2BSalesStageEvent;
use App\Modules\B2B\Sales\B2BSalesPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class B2BSalesPipelineParityTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $key, array $rights): User
    {
        $role = Role::create(['key' => $key, 'label' => $key]);
        foreach ($rights as $right) {
            $p = Permission::firstOrCreate(['key' => $right], ['label' => $right]);
            $role->permissions()->attach($p);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }

    private function company(User $owner): B2BOrganization
    {
        return B2BOrganization::create(['display_name' => 'UAT ONLY Distributor', 'country_code' => 'MY', 'owner_id' => $owner->id]);
    }

    private function caseFor(B2BOrganization $company, User $owner): B2BCase
    {
        return B2BCase::create([
            'case_number' => 'UAT-CASE-'.bin2hex(random_bytes(4)),
            'organization_id' => $company->id,
            'owner_id' => $owner->id,
            'title' => 'Synthetic distributor inquiry',
            'case_status' => 'open',
            'case_stage' => 'qualified',
            'handling_state' => 'waiting',
            'source_channel' => 'manual',
            'first_inbound_at' => now(),
            'last_inbound_at' => now(),
        ]);
    }

    private function payload(B2BOrganization $company, ?B2BCase $case = null): array
    {
        return [
            'organization_id' => $company->id,
            'case_id' => $case?->id,
            'title' => 'S1 UAT Sales Opportunity',
            'currency' => 'USD',
            'expected_value_micros' => 250000000,
        ];
    }

    public function test_yby_five_stage_semantics_and_expected_money_truth_are_reused(): void
    {
        $this->assertSame(['discovery','qualified','solution','quoted','negotiating'], B2BSalesPipeline::STAGES);
        $this->assertSame([1000,3000,5000,6500,8000], array_map(
            fn ($key) => B2BSalesPipeline::PROBABILITIES[$key],
            B2BSalesPipeline::STAGES
        ));
        $owner = $this->actor('b2b-sales-one', ['b2b.view','b2b.operate']);
        $company = $this->company($owner);
        $case = $this->caseFor($company, $owner);
        $pipeline = app(B2BSalesPipeline::class);
        $first = $pipeline->create($this->payload($company, $case), $owner->id);
        $replayed = $pipeline->create($this->payload($company, $case), $owner->id);
        $this->assertSame($first->id, $replayed->id);
        $this->assertSame($company->id, $first->organization_id);
        $this->assertSame(1, B2BSalesOpportunity::count());
        $this->assertSame(1000, $first->probability_bps);
        $this->assertSame(250000000, $first->expected_value_micros);
        $this->assertDatabaseCount('b2b_sales_stage_events', 1);
        $pipeline->advance($first, 'qualified', $owner->id);
        $pipeline->advance($first, 'solution', $owner->id);
        $pipeline->advance($first, 'quoted', $owner->id);
        $this->assertSame(6500, $first->fresh()->probability_bps);
        $this->assertDatabaseCount('b2b_sales_stage_events', 4);
        $visited = collect($pipeline->roadmap($first->fresh()))->pluck('key', 'visited');
        $this->assertCount(6, $pipeline->roadmap($first->fresh()), 'Five YBY active stages plus final Won/Lost node');
        $this->assertTrue($pipeline->roadmap($first->fresh())[3]['current']);
        $this->assertDatabaseCount('customers', 0);
        $this->assertFalse(\Schema::hasTable('b2b_sales_payment_receipts'));
    }

    public function test_normal_user_cannot_view_or_write_another_salesperson_opportunity(): void
    {
        $a = $this->actor('b2b-sales-a', ['b2b.view','b2b.operate']);
        $b = $this->actor('b2b-sales-b', ['b2b.view','b2b.operate']);
        $company = $this->company($a);
        $row = app(B2BSalesPipeline::class)->create($this->payload($company), $a->id);

        $this->actingAs($b)->get('/b2b/sales')->assertOk()->assertDontSee($row->opportunity_number);
        $this->actingAs($b)->get("/b2b/sales/opportunities/{$row->id}")->assertNotFound();
        $this->actingAs($b)->patch("/b2b/sales/opportunities/{$row->id}/stage", ['pipeline_stage' => 'quoted'])->assertNotFound();
        $this->actingAs($b)->post('/b2b/sales/opportunities', $this->payload($company))->assertForbidden();
        $this->assertSame('discovery', $row->fresh()->pipeline_stage);
    }

    public function test_consumer_only_cannot_read_or_create_b2b_sales(): void
    {
        $consumer = $this->actor('b2b-consumer-only', ['customers.view','customers.operate']);
        $owner = $this->actor('b2b-sales-test-owner', ['b2b.view','b2b.operate']);
        $company = $this->company($owner);
        $this->actingAs($consumer)->get('/b2b/sales')->assertForbidden();
        $this->actingAs($consumer)->post('/b2b/sales/opportunities', $this->payload($company))->assertForbidden();
        $this->assertDatabaseCount('b2b_sales_opportunities', 0);
    }

    public function test_contact_must_belong_to_selected_b2b_company(): void
    {
        $owner = $this->actor('b2b-sales-contact-owner', ['b2b.view','b2b.operate']);
        $one = $this->company($owner);
        $two = $this->company($owner);
        $foreignContact = $two->contacts()->create(['name'=>'Unrelated Contact','email_normalized'=>'foreign@example.invalid']);
        $data = $this->payload($one);
        $data['contact_id'] = $foreignContact->id;
        $this->actingAs($owner)->post('/b2b/sales/opportunities', $data)->assertSessionHasErrors(['contact_id']);
        $this->assertDatabaseCount('b2b_sales_opportunities', 0);
    }

    public function test_readonly_b2b_user_cannot_modify_pipeline(): void
    {
        $owner = $this->actor('b2b-sales-readonly', ['b2b.view']);
        $company = $this->company($owner);
        $this->actingAs($owner)->get('/b2b/sales')->assertOk();
        $this->actingAs($owner)->post('/b2b/sales/opportunities', $this->payload($company))->assertForbidden();
    }

    public function test_opportunity_show_has_real_roadmap_and_integrity_audit(): void
    {
        $owner = $this->actor('b2b-sales-show', ['b2b.view','b2b.operate']);
        $company = $this->company($owner);
        $row = app(B2BSalesPipeline::class)->create($this->payload($company), $owner->id);
        $this->actingAs($owner)->get("/b2b/sales/opportunities/{$row->id}")->assertOk()
            ->assertSee($row->opportunity_number)->assertSee('discovery');
        $this->actingAs($owner)->patch("/b2b/sales/opportunities/{$row->id}/stage", ['pipeline_stage' => 'qualified'])->assertRedirect();
        $this->assertSame('qualified', $row->fresh()->pipeline_stage);
        $this->assertSame(2, B2BSalesStageEvent::count());
    }
}
