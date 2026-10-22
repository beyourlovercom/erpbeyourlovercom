<?php

namespace Tests\Feature\B2B;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\B2B\Infrastructure\Models\B2BOrganization;
use App\Modules\B2B\Infrastructure\Models\B2BSalesOpportunity;
use App\Modules\B2B\Infrastructure\Models\B2BSalesQuote;
use App\Modules\B2B\Sales\B2BSalesPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class B2BSalesFollowupQuoteParityTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $key, array $rights): User
    {
        $role = Role::create(['key'=>$key,'label'=>$key]);
        foreach ($rights as $right) {
            $p=Permission::firstOrCreate(['key'=>$right],['label'=>$right]);
            $role->permissions()->attach($p);
        }
        $user=User::factory()->create(['is_active'=>true]);
        $user->roles()->attach($role);
        return $user;
    }

    private function opportunity(User $owner): B2BSalesOpportunity
    {
        $org=B2BOrganization::create(['display_name'=>'UAT S2 Synthetic Org','owner_id'=>$owner->id,'country_code'=>'US']);
        return app(B2BSalesPipeline::class)->create([
            'organization_id'=>$org->id,
            'title'=>'UAT ONLY quotation',
            'currency'=>'USD',
            'expected_value_micros'=>500000000,
        ],$owner->id);
    }

    public function test_yby_followup_quote_versions_won_are_not_actual_revenue(): void
    {
        $operator=$this->actor('s2-sales-user',['b2b.view','b2b.operate','b2b.quotes_manage']);
        $row=$this->opportunity($operator);
        $svc=app(B2BSalesPipeline::class);
        $fu=$svc->followUp($row,[
            'stage_key'=>'discovery','channel'=>'whatsapp',
            'summary'=>'Buyer confirmed requirements',
            'next_plan'=>'Prepare draft offer',
            'next_follow_up_at'=>now()->addDay(),
        ],$operator->id);
        $this->assertSame('whatsapp',$fu->channel);
        $first=$svc->quote($row,['subtotal_micros'=>500000000,'discount_micros'=>25000000,'tax_micros'=>0,'currency'=>'USD'],$operator->id);
        $second=$svc->quote($row,['subtotal_micros'=>475000000,'discount_micros'=>0,'currency'=>'USD'],$operator->id);
        $this->assertSame([1,2],B2BSalesQuote::query()->orderBy('version')->pluck('version')->all());
        $this->assertSame(475000000,$first->total_micros);
        $this->assertSame('draft',$first->status);
        $this->assertSame(6500,$row->fresh()->probability_bps);
        $this->assertSame('quoted',$row->fresh()->pipeline_stage);
        $this->actingAs($operator)->get("/b2b/sales/opportunities/{$row->id}")->assertOk()
            ->assertSee('Buyer confirmed requirements')->assertSee($first->quote_number);
        $won=$svc->close($row,'won',$operator->id);
        $this->assertSame('won',$won->status);
        $this->assertSame(10000,$won->probability_bps);
        $this->assertSame(0,\DB::table('customers')->count());
        $this->assertFalse(\Schema::hasTable('b2b_sales_payment_receipts'));
        $this->assertSame(3,$won->stageEvents()->count()); // created, quoted, won
        $this->assertSame(1,$won->followUps()->count());
        $this->assertSame(2,$won->quotes()->count());
    }

    public function test_lost_reopen_records_immutable_history_and_requires_reason(): void
    {
        $sales=$this->actor('s2-lost-reopen',['b2b.view','b2b.operate']);
        $row=$this->opportunity($sales);
        $svc=app(B2BSalesPipeline::class);
        $lost=$svc->close($row,'lost',$sales->id,'price','Budget insufficient');
        $this->assertSame('lost',$lost->status);
        $this->assertSame('price',$lost->lost_reason_code);
        $this->assertSame(0,$lost->probability_bps);
        $this->assertSame(2,$lost->stageEvents()->count());
        $this->actingAs($sales)->post("/b2b/sales/opportunities/{$row->id}/reopen",[
            'stage'=>'qualified','reason'=>'Buyer resumed negotiation',
        ])->assertRedirect();
        $this->assertSame('open',$row->fresh()->status);
        $this->assertSame('qualified',$row->fresh()->pipeline_stage);
        $this->assertSame(3000,$row->fresh()->probability_bps);
        $this->assertSame(3,$row->stageEvents()->count());
        $this->assertDatabaseHas('b2b_sales_stage_events',['opportunity_id'=>$row->id,'from_stage'=>'lost','to_stage'=>'qualified','reason'=>'Buyer resumed negotiation']);
        $this->actingAs($sales)->post("/b2b/sales/opportunities/{$row->id}/lost",[
            'reason_code'=>'',
        ])->assertSessionHasErrors('reason_code');
        $this->assertSame('open',$row->fresh()->status);
    }

    public function test_quote_requires_explicit_permission_and_no_quote_after_close(): void
    {
        $operator=$this->actor('s2-no-quote',['b2b.view','b2b.operate']);
        $row=$this->opportunity($operator);
        $input=['subtotal_micros'=>100000000,'currency'=>'USD'];
        $this->actingAs($operator)->post("/b2b/sales/opportunities/{$row->id}/quotes",$input)->assertForbidden();
        $this->assertDatabaseCount('b2b_sales_quotes',0);
        $quoted=$this->actor('s2-with-quote',['b2b.view','b2b.operate','b2b.quotes_manage','b2b.view_all']);
        $this->actingAs($quoted)->post("/b2b/sales/opportunities/{$row->id}/quotes",$input)->assertRedirect();
        app(B2BSalesPipeline::class)->close($row,'lost',$quoted->id,'timing');
        $this->actingAs($quoted)->post("/b2b/sales/opportunities/{$row->id}/quotes",$input)->assertSessionHasErrors('status');
        $this->assertDatabaseCount('b2b_sales_quotes',1);
    }

    public function test_private_attachments_are_restricted_to_scoped_opportunity(): void
    {
        Storage::fake('local');
        $a=$this->actor('s2-attach-owner',['b2b.view','b2b.operate']);
        $b=$this->actor('s2-attach-other',['b2b.view','b2b.operate']);
        $row=$this->opportunity($a);
        $fu=app(B2BSalesPipeline::class)->followUp($row,[
            'stage_key'=>'discovery','channel'=>'note','summary'=>'Private document evidence',
            'attachments'=>[UploadedFile::fake()->image('sample.png')],
        ],$a->id);
        $attachment=$fu->attachments()->firstOrFail();
        $this->assertSame('local',$attachment->disk);
        $this->assertStringStartsWith('b2b-sales/followups/',$attachment->path);
        Storage::disk('local')->assertExists($attachment->path);
        $route="/b2b/sales/opportunities/{$row->id}/followups/{$fu->id}/attachments/{$attachment->id}";
        $this->actingAs($b)->get($route)->assertNotFound();
        $this->actingAs($a)->get($route)->assertOk();
    }

    public function test_closed_opportunity_cannot_change_stage_without_reopening(): void
    {
        $sales=$this->actor('s2-terminal',['b2b.view','b2b.operate']);
        $row=$this->opportunity($sales);
        $svc=app(B2BSalesPipeline::class);
        $svc->close($row,'won',$sales->id);
        $this->actingAs($sales)->patch("/b2b/sales/opportunities/{$row->id}/stage",['pipeline_stage'=>'negotiating'])
            ->assertSessionHasErrors('pipeline_stage');
        $this->actingAs($sales)->post("/b2b/sales/opportunities/{$row->id}/reopen",['stage'=>'qualified','reason'=>'Test'])
            ->assertSessionHasErrors('status');
        $this->assertSame('won',$row->fresh()->status);
        $this->assertDatabaseCount('b2b_sales_stage_events',2);
    }
}
