<?php

namespace Tests\Feature\Kol;

use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

final class KolSmartInputFixtureTest extends TestCase
{
    use RefreshDatabase;

    private function actor(array $permissions): User
    {
        $actor = User::factory()->create(['is_active'=>true]);
        $role = Role::create(['key'=>'smart-input-'.uniqid(), 'label'=>'Smart input']);
        foreach ($permissions as $key) $role->permissions()->attach(Permission::firstOrCreate(['key'=>$key], ['label'=>$key]));
        $actor->roles()->attach($role);
        return $actor;
    }

    public function test_fixture_only_endpoint_and_schema_validation(): void
    {
        config(['kol_smart_input.dev_enabled'=>true, 'kol_smart_input.node'=>'fake-node', 'kol_smart_input.client'=>'fake-client', 'kol_smart_input.key_path'=>'fake-key']);
        $expected = json_decode(file_get_contents(base_path('tools/byl-ai-worker-dev/fixtures.json')), true)[0]['expected'];
        Process::fake(fn () => Process::result(json_encode(['source'=>'hybrid_review','ai_suggestions'=>$expected])));
        $actor = $this->actor(['kol.view','kol.operate']);
        $this->actingAs($actor)->postJson('/kol/smart-input/fixture',['fixture_id'=>'es'])
            ->assertOk()->assertJsonPath('source','hybrid_review')->assertJsonPath('ai_suggestions.username','demo_es');
        $this->postJson('/kol/smart-input/fixture',['fixture_id'=>'es','text'=>'real raw text'])->assertUnprocessable();
        $this->postJson('/kol/smart-input/fixture',['fixture_id'=>'unknown'])->assertUnprocessable();
        Process::assertRanTimes(fn ($process) => $process->command === ['fake-node','fake-client','--fixture','es'], 1);
    }

    public function test_denial_and_failure_fallback(): void
    {
        config(['kol_smart_input.dev_enabled'=>true, 'kol_smart_input.node'=>'fake-node', 'kol_smart_input.client'=>'fake-client', 'kol_smart_input.key_path'=>'fake-key']);
        Process::fake(fn () => Process::result('invalid json'));
        $this->actingAs($this->actor(['kol.view']))->postJson('/kol/smart-input/fixture',['fixture_id'=>'es'])->assertForbidden();
        $this->actingAs($this->actor(['kol.view','orders.create']))->postJson('/kol/smart-input/fixture',['fixture_id'=>'es'])->assertOk()->assertJsonPath('source','local_rules');
        config(['kol_smart_input.dev_enabled'=>false]);
        $this->postJson('/kol/smart-input/fixture',['fixture_id'=>'es'])->assertNotFound();
    }
}
