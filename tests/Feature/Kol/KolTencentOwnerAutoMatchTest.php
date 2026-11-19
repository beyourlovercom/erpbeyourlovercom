<?php

namespace Tests\Feature\Kol;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Identity\Application\RolePreset;
use App\Modules\Kol\Application\ImportActivation\KolImportActivationService;
use App\Modules\Kol\Application\ImportPreview\KolImportPreviewService;
use App\Modules\Kol\Application\OwnerMapping\KolOwnerMappingService;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolOwnerSourceMapping;
use App\Modules\Kol\Infrastructure\Models\KolReconciliationBatch;
use App\Modules\Kol\Infrastructure\Models\KolReconciliationRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class KolTencentOwnerAutoMatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_tencent_belongs_to_auto_resolves_unique_active_kol_staff_and_activation_plan(): void
    {
        $admin = $this->admin();
        $emma = $this->presetUser('Emma', RolePreset::KolSpecialist);
        $guts = $this->presetUser('Guts Login', RolePreset::KolManager, 'Guts');
        $batch = $this->batch();
        $this->row($batch, 2, 'emma@example.test', '  emMA  ');
        $this->row($batch, 3, 'guts@example.test', 'GUTS');
        $preview = $this->app->make(KolImportPreviewService::class)->generate($batch->id, $admin);
        $workspace = $this->app->make(KolOwnerMappingService::class)->workspace($admin, $preview->id);

        self::assertSame(2, $workspace['summary']['resolved_candidates']);
        $emmaRow = collect($workspace['source_owners'])->firstWhere('key', 'emma');
        $gutsRow = collect($workspace['source_owners'])->firstWhere('key', 'guts');
        self::assertSame($emma->id, $emmaRow['auto_match']['user']['id']);
        self::assertSame($guts->id, $gutsRow['auto_match']['user']['id']);
        self::assertNull($emmaRow['mapping']);
        self::assertSame('matched', $gutsRow['auto_match']['status']);
        self::assertSame(0, KolOwnerSourceMapping::count());
        self::assertSame(0, Kol::count());

        $plan = $this->app->make(KolImportActivationService::class)->prepare($preview->id, $admin);
        self::assertSame(2, $plan->ready_count);
        self::assertEqualsCanonicalizing([$emma->id, $guts->id], $plan->items->pluck('effective_owner_id')->all());
        self::assertSame(0, Kol::count());
    }

    public function test_duplicate_names_non_kol_roles_and_inactive_users_fail_closed(): void
    {
        $admin = $this->admin();
        $this->presetUser('Emma', RolePreset::KolSpecialist);
        $this->presetUser(' EMMA ', RolePreset::KolManager);
        $this->userWith(['kol.view'], 'Liang');
        $inactive = $this->presetUser('Hiko', RolePreset::KolSpecialist);
        $inactive->update(['is_active' => false]);
        $batch = $this->batch();
        $this->row($batch, 2, 'duplicate@example.test', 'Emma');
        $this->row($batch, 3, 'otherrole@example.test', 'Liang');
        $this->row($batch, 4, 'inactive@example.test', 'Hiko');
        $preview = $this->app->make(KolImportPreviewService::class)->generate($batch->id, $admin);
        $workspace = $this->app->make(KolOwnerMappingService::class)->workspace($admin, $preview->id);

        self::assertSame(0, $workspace['summary']['resolved_candidates']);
        self::assertSame('ambiguous', collect($workspace['source_owners'])->firstWhere('key', 'emma')['auto_match']['status']);
        self::assertSame('not_found', collect($workspace['source_owners'])->firstWhere('key', 'liang')['auto_match']['status']);
        self::assertSame('not_found', collect($workspace['source_owners'])->firstWhere('key', 'hiko')['auto_match']['status']);
        self::assertSame(0, $this->app->make(KolImportActivationService::class)->prepare($preview->id, $admin)->ready_count);
    }

    public function test_explicit_source_mapping_overrides_automatic_match_without_changing_kol(): void
    {
        $admin = $this->admin();
        $this->presetUser('Emma', RolePreset::KolSpecialist);
        $manual = $this->userWith(['kol.view'], 'Confirmed Manual Owner');
        $batch = $this->batch();
        $this->row($batch, 2, 'override@example.test', 'Emma');
        $preview = $this->app->make(KolImportPreviewService::class)->generate($batch->id, $admin);
        $workspace = $this->app->make(KolOwnerMappingService::class)->saveSourceMapping($preview->id, 'Emma', $manual->id, $admin);

        self::assertSame($manual->id, $workspace['source_owners'][0]['mapping']['mapped_user_id']);
        self::assertNull($workspace['source_owners'][0]['auto_match']);
        self::assertSame('mapped', $this->app->make(KolImportActivationService::class)->prepare($preview->id, $admin)->items->first()->source_owner_evidence['resolution_status']);
        self::assertSame(0, Kol::count());
    }

    public function test_auto_owner_role_change_after_plan_preparation_prevents_apply(): void
    {
        config(['kol.canonical_import_enabled' => true]);
        $admin = $this->admin();
        $owner = $this->presetUser('Guts', RolePreset::KolManager);
        $batch = $this->batch();
        $this->row($batch, 2, 'rolechange@example.test', 'Guts');
        $preview = $this->app->make(KolImportPreviewService::class)->generate($batch->id, $admin);
        $service = $this->app->make(KolImportActivationService::class);
        $plan = $service->prepare($preview->id, $admin);
        self::assertSame(1, $plan->ready_count);

        $otherRole = Role::create(['key' => 'other_with_kol_view', 'label' => 'Unrelated role']);
        $otherRole->permissions()->attach(Permission::firstOrCreate(['key' => 'kol.view'], ['label' => 'kol.view']));
        $owner->roles()->sync([$otherRole->id]);
        try {
            $service->apply($plan, $plan->plan_checksum, $plan->ready_count, $admin);
            self::fail('Revoked KOL staff role must invalidate an automatic match.');
        } catch (HttpException $e) {
            self::assertSame(422, $e->getStatusCode());
        }
        self::assertSame(0, Kol::count());
    }

    private function batch(): KolReconciliationBatch
    {
        return KolReconciliationBatch::create([
            'source_type' => 'tencent_xlsx', 'original_filename' => 'synthetic-tencent.xlsx',
            'checksum' => str_repeat('d', 64), 'connection_key' => 'test.example.com', 'status' => 'preview',
        ]);
    }

    private function row(KolReconciliationBatch $batch, int $number, string $email, string $owner): void
    {
        $raw = ['Contact Email' => $email, 'Belongs to' => $owner];
        KolReconciliationRow::create([
            'batch_id' => $batch->id, 'source_row_number' => $number,
            'row_hash' => hash('sha256', json_encode($raw)), 'raw_source' => $raw,
            'normalized_identity' => ['email' => $email, 'social' => [], 'key' => 'email:'.$email],
            'source_identity_key' => 'email:'.$email,
            'kol_status' => 'unresolved', 'affiliate_status' => 'missing', 'reconciliation_status' => 'unresolved',
        ]);
    }

    private function presetUser(string $name, RolePreset $preset, ?string $contactName = null): User
    {
        $role = Role::firstOrCreate(['key' => $preset->roleKey()], ['label' => $preset->label()]);
        $role->permissions()->syncWithoutDetaching([Permission::firstOrCreate(['key' => 'kol.view'], ['label' => 'kol.view'])->id]);
        $user = User::factory()->create(['is_active' => true, 'name' => $name, 'contact_name' => $contactName]);
        $user->roles()->attach($role);

        return $user;
    }

    private function admin(): User
    {
        return $this->userWith(['kol.view', 'kol.operate', 'kol.approvals.manage', 'kol.affiliates.manage', 'system.permissions.manage'], 'Admin');
    }

    private function userWith(array $keys, string $name): User
    {
        $role = Role::create(['key' => 'test_tencent_auto_'.uniqid(), 'label' => 'Test']);
        foreach ($keys as $key) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key]));
        }
        $user = User::factory()->create(['is_active' => true, 'name' => $name]);
        $user->roles()->attach($role);

        return $user;
    }
}
