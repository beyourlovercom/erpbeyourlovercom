<?php

namespace Tests\Feature\Kol;

use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

final class KolSmartShapeTest extends TestCase
{
    use RefreshDatabase;

    private function actor(array $permissions): User
    {
        $user=User::factory()->create(['is_active'=>true]);
        $role=Role::create(['key'=>'shape-'.uniqid(),'label'=>'Shape staff']);
        foreach ($permissions as $p) $role->permissions()->attach(Permission::firstOrCreate(['key'=>$p],['label'=>$p]));
        $user->roles()->attach($role);
        return $user;
    }

    private function settings(): void
    {
        config(['kol_smart_input.shape_enabled'=>true,'kol_smart_input.node'=>'fake-node','kol_smart_input.shape_client'=>'fake-client','kol_smart_input.key_path'=>'fake-key']);
    }

    private function payload(): array
    {
        return ['lines'=>[['index'=>0,'label'=>null,'kind'=>'phone_like'],['index'=>1,'label'=>null,'kind'=>'text']],'missing'=>['phone','recipient_name']];
    }

    public function test_exact_shape_only_and_validated_response(): void
    {
        $this->settings();
        $p=$this->payload();
        Process::fake(fn()=>Process::result(json_encode(['source'=>'shape_review','assignments'=>[['index'=>0,'field'=>'phone']]])));
        $this->actingAs($this->actor(['kol.view','kol.operate']))->postJson('/kol/smart-input/shape',$p)->assertOk()->assertJsonPath('source','shape_review')->assertJsonPath('assignments.0.field','phone');
        $this->postJson('/kol/smart-input/shape',$p+['raw'=>'synthetic text'])->assertUnprocessable();
        $p['lines'][0]['value']='synthetic value';
        $this->postJson('/kol/smart-input/shape',$p)->assertUnprocessable();
        Process::assertRanTimes(fn($process)=>$process->command===['fake-node','fake-client'],1);
    }

    public function test_permission_disabled_and_invalid_advice_fallback(): void
    {
        $this->settings();
        Process::fake(fn()=>Process::result(json_encode(['source'=>'shape_review','assignments'=>[['index'=>1,'field'=>'recipient_name']]])));
        $p=$this->payload();
        $this->actingAs($this->actor(['kol.view']))->postJson('/kol/smart-input/shape',$p)->assertForbidden();
        $this->actingAs($this->actor(['kol.view','orders.create']))->postJson('/kol/smart-input/shape',$p)->assertOk()->assertJsonPath('source','local_rules');
        config(['kol_smart_input.shape_enabled'=>false]);
        $this->postJson('/kol/smart-input/shape',$p)->assertNotFound();
    }
}
