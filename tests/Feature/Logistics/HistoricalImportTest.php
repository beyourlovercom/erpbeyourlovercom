<?php

namespace Tests\Feature\Logistics;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Logistics\Infrastructure\Models\HistoricalImportBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class HistoricalImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_requires_view_permission_and_preview_requires_operate_permission(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user)->get('/logistics/historical-import')->assertForbidden();

        $viewer = $this->userWith(['logistics.view']);
        $this->actingAs($viewer)->get('/logistics/historical-import')->assertOk();
        $this->actingAs($viewer)->post('/logistics/historical-import/preview')->assertForbidden();
    }

    public function test_commit_gate_is_off_by_default_and_never_creates_current_shipments(): void
    {
        $operator = $this->userWith(['logistics.view', 'logistics.operate']);
        $batch = HistoricalImportBatch::create([
            'original_filename' => 'fixture.xlsx', 'source_checksum' => str_repeat('a', 64), 'source_sheet' => '工作表1',
            'status' => 'preview', 'total_rows' => 0, 'created_by' => $operator->id,
        ]);
        $this->actingAs($operator)->postJson("/logistics/historical-import/{$batch->id}/commit")->assertForbidden();
        $this->assertDatabaseCount('shipments', 0);
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'historical-import-'.uniqid(), 'label' => 'Historical Import Test']);
        foreach ($permissions as $permission) $role->permissions()->attach(Permission::firstOrCreate(['key' => $permission], ['label' => $permission]));
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }
}
