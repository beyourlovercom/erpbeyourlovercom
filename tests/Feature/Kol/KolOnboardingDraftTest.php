<?php

namespace Tests\Feature\Kol;

use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class KolOnboardingDraftTest extends TestCase
{
    use RefreshDatabase;

    private function staff(array $permissions=['kol.view','kol.operate','orders.create']): User
    {
        $actor=User::factory()->create(['is_active'=>true]);
        $role=Role::create(['key'=>'draft-'.uniqid(),'label'=>'Draft staff']);
        foreach ($permissions as $key) $role->permissions()->attach(Permission::firstOrCreate(['key'=>$key], ['label'=>$key]));
        $actor->roles()->attach($role);
        return $actor;
    }

    public function test_encrypted_draft_resume_confirm_and_consume_with_database_readback(): void
    {
        $this->withoutVite();
        $staff=$this->staff();
        $kol=Kol::query()->create(['display_name'=>'demo_profile','owner_id'=>$staff->id,'status'=>'active','relationship_stage'=>'cooperating','shipping_country'=>'US','shipping_recipient_name'=>'Old Synthetic','shipping_address_line_1'=>'Old Street']);
        $product=Product::create(['source_type'=>'manual','sku'=>'DRAFT-01','name'=>'Synthetic Product','is_active'=>true]);
        $response=$this->actingAs($staff)->post('/kol/onboarding-draft',[
            'smart'=>['username_hint'=>'demo_profile','recipient_name'=>'New Synthetic','country'=>'US','address_line_1'=>'New Street','product_hint'=>'Synthetic Product'],
            'order'=>['currency'=>'USD','items'=>[['sku'=>'DRAFT-01','product_id'=>$product->id,'quantity'=>1,'unit_price'=>'0.00']]],
        ]);
        $response->assertRedirect();
        $token=session('kol.onboarding.draft.token');
        self::assertNotEmpty($token);
        self::assertStringNotContainsString('New Synthetic',session('kol.onboarding.draft.payload'));
        self::assertDatabaseCount('orders',0);
        $this->get('/kol/library/create?onboarding_token='.$token)->assertOk()->assertInertia(fn($p)=>$p->where('onboardingToken',$token)->where('onboardingSmartDraft.username_hint','demo_profile'));
        $this->get('/kol/orders/create?kol_id='.$kol->id.'&onboarding_token='.$token)->assertOk()->assertInertia(fn($p)=>$p->where('resumeOrderDraft.smart.product_hint','Synthetic Product')->where('selectedKolId',$kol->id));
        $order=['external_order_number'=>'DRAFT-ORDER-1','kol_id'=>$kol->id,'currency'=>'USD','onboarding_token'=>$token,'address_confirmed'=>false,'recipient_name'=>'New Synthetic','country'=>'US','address_line_1'=>'New Street','items'=>[['product_id'=>$product->id,'product_summary'=>'Synthetic Product','quantity'=>1,'unit_price'=>'0.00']]];
        $this->postJson('/kol/orders',$order)->assertUnprocessable();
        self::assertDatabaseCount('orders',0);
        $order['address_confirmed']=true;
        $this->post('/kol/orders',$order)->assertRedirect();
        $saved=Order::query()->sole();
        self::assertSame($kol->id,(int)$saved->kol_id);
        self::assertSame('New Street',$saved->address->address_line_1);
        self::assertSame('Old Street',$kol->fresh()->shipping_address_line_1);
        self::assertNull(session('kol.onboarding.draft'));
        $this->postJson('/kol/orders',$order)->assertUnprocessable();
        self::assertDatabaseCount('orders',1);
    }

    public function test_draft_denial_and_expiry(): void
    {
        $this->withoutVite();
        $staff=$this->staff(['kol.view','orders.create']);
        $this->actingAs($staff)->postJson('/kol/onboarding-draft',['smart'=>['username_hint'=>'demo']])->assertForbidden();
        $owner=$this->staff();
        $this->actingAs($owner)->post('/kol/onboarding-draft',['smart'=>['username_hint'=>'demo']])->assertRedirect();
        $token=session('kol.onboarding.draft.token');
        $this->travel(31)->minutes();
        $this->get('/kol/library/create?onboarding_token='.$token)->assertOk()->assertInertia(fn($p)=>$p->where('onboardingToken',null)->where('onboardingSmartDraft',null));
    }
}
