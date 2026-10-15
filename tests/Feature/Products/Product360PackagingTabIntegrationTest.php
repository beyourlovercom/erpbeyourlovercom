<?php

namespace Tests\Feature\Products;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class Product360PackagingTabIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        $user=User::factory()->create(['is_active'=>true]);
        $role=Role::create(['key'=>'packaging-tab-'.uniqid(),'label'=>'Packaging Tab Test']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key'=>$permission],['label'=>$permission]);
        }
        $user->roles()->attach($role);
        return $user;
    }

    public function test_legacy_packaging_contract_is_embedded_in_product360_tab_with_same_profile_and_presets(): void
    {
        $p=Product::create(['sku'=>'PACK-IN-TAB-1','name'=>'Product 360 packaging','product_type'=>'simple','weight_kg'=>2.5,'shipping_weight_kg'=>3.5]);
        $manager=$this->userWith(['products.view','products.operate']);
        $this->actingAs($manager)->get('/products/'.$p->id)->assertOk()
            ->assertInertia(fn($page)=>$page->component('Products/Show')
                ->where('packagingProfile',null)->has('packagingPresets',4));
        $this->put('/products/'.$p->id.'/packaging',[
            'preset_key'=>'carton',
            'scenario'=>'Synthetic package only',
            'version'=>'uat-v1',
            'units_per_carton'=>4,
        ])->assertRedirect();
        $this->assertDatabaseCount('product_packaging_profiles',1);
        $this->get('/products/'.$p->id)->assertOk()
            ->assertInertia(fn($page)=>$page->component('Products/Show')
                ->where('packagingProfile.preset_key','carton')
                ->where('packagingProfile.scenario','Synthetic package only')
                ->where('packagingProfile.version','uat-v1')
                ->where('packagingProfile.units_per_carton',4)
                ->has('packagingPresets',4));
        $this->get('/products/'.$p->id.'/packaging')->assertOk()
            ->assertInertia(fn($page)=>$page->component('Products/Packaging')
                ->where('packagingProfile.preset_key','carton')
                ->where('packagingProfile.units_per_carton',4)
                ->has('presets',4));
        $this->assertEquals(2.5,(float)$p->fresh()->weight_kg);
        $this->assertEquals(3.5,(float)$p->fresh()->shipping_weight_kg);
    }

    public function test_images_on_old_packaging_endpoint_are_visible_in_new_tab_without_copying_files(): void
    {
        Storage::fake('local');
        $p=Product::create(['sku'=>'PACK-IN-TAB-2','name'=>'Photo reference','product_type'=>'simple']);
        $manager=$this->userWith(['products.view','products.operate']);
        $this->actingAs($manager)->put('/products/'.$p->id.'/packaging',[
            'preset_key'=>'carton','version'=>'images-v1',
        ])->assertRedirect();
        $this->post('/products/'.$p->id.'/packaging/media',[
            'kind'=>'rendered','files'=>[UploadedFile::fake()->image('rendered.jpg')],
        ])->assertRedirect();
        $this->post('/products/'.$p->id.'/packaging/media',[
            'kind'=>'example','files'=>[UploadedFile::fake()->image('example.png')],
        ])->assertRedirect();
        $this->assertDatabaseCount('product_packaging_profile_media',2);
        $this->get('/products/'.$p->id)->assertOk()
            ->assertInertia(fn($page)=>$page->component('Products/Show')
                ->where('packagingProfile.rendered_image.name','rendered.jpg')
                ->where('packagingProfile.examples.0.name','example.png')
                ->has('packagingProfile.examples',1));
        $this->get('/products/'.$p->id.'/packaging')->assertOk()
            ->assertInertia(fn($page)=>$page->component('Products/Packaging')
                ->where('packagingProfile.rendered_image.name','rendered.jpg')
                ->has('packagingProfile.examples',1));
    }

    public function test_no_factory_product_master_access_is_granted_by_packaging_tab(): void
    {
        $p=Product::create(['sku'=>'PACK-IN-TAB-3','name'=>'Private Package','product_type'=>'simple']);
        $factory=$this->userWith(['dashboard.view','products.parameters.submit']);
        $viewer=$this->userWith(['products.view']);
        $this->actingAs($factory)->get('/products/'.$p->id)->assertForbidden();
        $this->get('/products/'.$p->id.'/packaging')->assertForbidden();
        $this->put('/products/'.$p->id.'/packaging',['preset_key'=>'carton'])->assertForbidden();
        $this->actingAs($viewer)->get('/products/'.$p->id)->assertOk()
            ->assertInertia(fn($page)=>$page->component('Products/Show')
                ->where('canOperate',false)
                ->where('packagingProfile',null)
                ->where('packagingPresets',[]));
        $this->get('/products/'.$p->id.'/packaging')->assertForbidden();
        $this->assertDatabaseCount('product_packaging_profiles',0);
    }
}
