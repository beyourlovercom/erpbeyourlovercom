<?php

namespace Tests\Feature\Kol;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Application\AffiliateWp\KolAffiliateBindingActivationService;
use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use App\Modules\Kol\Application\ImportActivation\KolImportActivationService;
use App\Modules\Kol\Application\ImportPreview\KolImportPreviewService;
use App\Modules\Kol\Application\OwnerMapping\KolOwnerMappingService;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use App\Modules\Kol\Infrastructure\Models\KolImportPreviewBatch;
use App\Modules\Kol\Infrastructure\Models\KolReconciliationBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

class TencentKolImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_administrators_can_open_or_upload_tencent_source(): void
    {
        $manager = $this->userWithPermissions(['kol.view', 'kol.operate', 'kol.affiliates.manage']);
        $this->actingAs($manager)->get('/kol/affiliatewp/tencent-import')->assertForbidden();
        $this->actingAs($manager)->post('/kol/affiliatewp/tencent-import/preview', [
            'file' => $this->xlsx(), 'sheet_name' => '网红-KOL', 'email_column' => 'Contact Email',
            'owner_confirmed' => false,
        ])->assertForbidden();
        $admin = $this->admin();
        $this->actingAs($admin)->get('/kol/affiliatewp/tencent-import')->assertOk();
    }

    public function test_selected_worksheet_and_identity_whitelist_exclude_shipping_pii(): void
    {
        $admin = $this->admin();
        $kol = Kol::create([
            'display_name' => 'Creator', 'status' => 'active', 'relationship_stage' => 'prospect',
            'owner_id' => $admin->id, 'contact_email' => 'creator@example.test',
        ]);
        $affiliate = KolAffiliateAccount::create([
            'connection_key' => (string) $this->app->make(AffiliateWpGateway::class)->descriptor()['connection_key'], 'affiliate_id' => 2169,
            'email' => 'creator@example.test', 'provider_status' => 'active',
            'origin' => 'external_sync', 'sync_status' => 'synced',
            'reconciliation_status' => 'present', 'provisioning_status' => 'not_requested',
        ]);
        $this->actingAs($admin)->post('/kol/affiliatewp/tencent-import/preview', [
            'file' => $this->xlsx(), 'sheet_name' => '网红-KOL',
            'email_column' => 'Contact Email', 'profile_url_column' => 'Influencer`s Link/URL',
            'owner_column' => 'Belongs to', 'owner_confirmed' => true,
        ])->assertOk()->assertJsonPath('batch.rows.0.source_owner', 'Liang');
        $batch = KolReconciliationBatch::query()->latest('id')->firstOrFail();
        self::assertSame('tencent_xlsx', $batch->source_type);
        self::assertSame(1, $batch->total_rows);
        $row = $batch->rows()->firstOrFail();
        self::assertSame($kol->id, $row->matched_kol_id);
        self::assertSame($affiliate->id, $row->matched_affiliate_account_id);
        self::assertSame('ready_for_binding', $row->reconciliation_status);
        self::assertSame('Liang', $row->raw_source['Belongs to']);
        self::assertSame(
            ['Contact Email', 'Influencer`s Link/URL', 'Belongs to'],
            array_keys($row->raw_source),
        );
        $serialized = json_encode($row->getAttributes(), JSON_UNESCAPED_UNICODE);
        foreach (['PRIVATE-TRACKING-001', '555-PRIVATE-PHONE', 'Sensitive Street 99'] as $secret) {
            self::assertStringNotContainsString($secret, $serialized);
        }
        self::assertSame(1, Kol::query()->count());
        self::assertNull($affiliate->fresh()->kol_id);
    }

    public function test_business_owner_column_requires_explicit_confirmation(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/kol/affiliatewp/tencent-import/preview', [
            'file' => $this->xlsx(), 'sheet_name' => '网红-KOL', 'email_column' => 'Contact Email',
            'owner_column' => 'Belongs to', 'owner_confirmed' => false,
        ])->assertStatus(422);
        self::assertSame(0, KolReconciliationBatch::query()->count());
    }

    public function test_missing_sheet_is_blocked_without_persisting_source(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/kol/affiliatewp/tencent-import/preview', [
            'file' => $this->xlsx(), 'sheet_name' => '不存在',
            'email_column' => 'Contact Email', 'owner_confirmed' => false,
        ])->assertStatus(422);
        self::assertSame(0, KolReconciliationBatch::query()->count());
    }

    public function test_tencent_batch_cannot_be_enumerated_or_generated_by_kol_manager(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/kol/affiliatewp/tencent-import/preview', [
            'file' => $this->xlsx(), 'sheet_name' => '网红-KOL',
            'email_column' => 'Contact Email', 'profile_url_column' => 'Influencer`s Link/URL',
            'owner_confirmed' => false,
        ])->assertOk();
        $batch = KolReconciliationBatch::query()->firstOrFail();
        $manager = $this->userWithPermissions(['kol.view', 'kol.operate', 'kol.affiliates.manage']);
        self::assertSame([], $this->app->make(KolImportPreviewService::class)->workspace($manager));
        $this->actingAs($manager)->post('/kol/import-preview/generate', [
            'reconciliation_batch_id' => $batch->id,
        ])->assertForbidden();
        $this->actingAs($admin)->post('/kol/import-preview/generate', [
            'reconciliation_batch_id' => $batch->id,
        ])->assertOk();
        $managerWithApprovals = $this->userWithPermissions([
            'kol.view', 'kol.operate', 'kol.affiliates.manage', 'kol.approvals.manage',
        ]);
        $previewId = (int) KolImportPreviewBatch::query()->firstOrFail()->id;
        $workspace = $this->app->make(KolOwnerMappingService::class)
            ->workspace($managerWithApprovals, $previewId);
        self::assertSame([], $workspace['previews']);
        $this->actingAs($managerWithApprovals)->post('/kol/owner-mapping/source', [
            'preview_batch_id' => $previewId, 'source_owner_value' => 'Liang',
            'mapped_user_id' => $managerWithApprovals->id,
        ])->assertForbidden();
    }

    public function test_reviewed_tencent_import_reuses_canonical_creation_and_exact_affiliate_binding(): void
    {
        config(['kol.canonical_import_enabled' => true, 'kol.affiliate_binding_activation_enabled' => true]);
        $admin = $this->admin();
        $owner = $this->userWithPermissions(['kol.view']);
        $account = KolAffiliateAccount::create([
            'connection_key' => (string) $this->app->make(AffiliateWpGateway::class)->descriptor()['connection_key'],
            'affiliate_id' => 2169, 'email' => 'creator@example.test',
            'provider_status' => 'active', 'origin' => 'external_sync',
            'sync_status' => 'synced', 'reconciliation_status' => 'present',
            'provisioning_status' => 'not_requested',
        ]);
        $this->actingAs($admin)->post('/kol/affiliatewp/tencent-import/preview', [
            'file' => $this->xlsx(), 'sheet_name' => '网红-KOL',
            'email_column' => 'Contact Email', 'profile_url_column' => 'Influencer`s Link/URL',
            'owner_column' => 'Belongs to', 'owner_confirmed' => true,
        ])->assertOk();
        $batch = KolReconciliationBatch::query()->firstOrFail();
        $preview = $this->app->make(KolImportPreviewService::class)->generate($batch->id, $admin);
        $import = $this->app->make(KolImportActivationService::class);
        $blocked = $import->prepare($preview->id, $admin);
        self::assertSame(0, $blocked->ready_count);
        self::assertSame(0, Kol::query()->count());
        self::assertNull($account->fresh()->kol_id);

        $this->app->make(KolOwnerMappingService::class)
            ->saveSourceMapping($preview->id, 'Liang', $owner->id, $admin);
        $plan = $import->prepare($preview->id, $admin);
        self::assertSame(1, $plan->ready_count);
        $applied = $import->apply($plan, $plan->plan_checksum, $plan->ready_count, $admin);
        $kol = Kol::query()->where('contact_email', 'creator@example.test')->firstOrFail();
        self::assertSame($owner->id, $kol->owner_id);
        self::assertNull($account->fresh()->kol_id);

        $binding = $this->app->make(KolAffiliateBindingActivationService::class);
        $bindPlan = $binding->prepare($applied->id, $admin);
        self::assertSame(1, $bindPlan->ready_count);
        $bound = $binding->apply($bindPlan, $bindPlan->plan_checksum, $bindPlan->ready_count, $admin);
        self::assertSame('applied', $bound->status);
        self::assertSame($kol->id, $account->fresh()->kol_id);
        self::assertSame('active', $account->fresh()->provider_status);
        $binding->apply($bound, $bindPlan->plan_checksum, $bindPlan->ready_count, $admin);
        self::assertSame(1, Kol::query()->count());
        self::assertSame(1, KolAffiliateAccount::query()->count());
    }

    private function admin(): User
    {
        return $this->userWithPermissions([
            'kol.view', 'kol.operate', 'kol.affiliates.manage', 'kol.approvals.manage', 'system.permissions.manage',
        ]);
    }

    private function userWithPermissions(array $keys): User
    {
        $role = Role::create(['key' => 'tencent_test_'.uniqid(), 'label' => 'Tencent Test']);
        foreach ($keys as $key) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key]));
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    private function xlsx(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'kol-tencent-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="物流订单" sheetId="1" r:id="rId1"/><sheet name="网红-KOL" sheetId="2" r:id="rId2"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/ship.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/kol.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/ship.xml', $this->sheet([
            ['Full name', 'Phone'], ['Test Recipient', '555-PRIVATE-PHONE'],
        ]));
        $zip->addFromString('xl/worksheets/kol.xml', $this->sheet([
            ['Contact Email', 'Influencer`s Link/URL', 'Belongs to', 'Phone', 'Address Street', 'Tracking'],
            ['creator@example.test', 'https://instagram.com/creator/', 'Liang', '555-PRIVATE-PHONE', 'Sensitive Street 99', 'PRIVATE-TRACKING-001'],
        ]));
        $zip->close();

        return UploadedFile::createFromBase(new \Symfony\Component\HttpFoundation\File\UploadedFile(
            $path, 'tencent.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true,
        ), true);
    }

    private function sheet(array $rows): string
    {
        $xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $rowIndex => $values) {
            $n = $rowIndex + 1;
            $xml .= '<row r="'.$n.'">';
            foreach ($values as $index => $value) {
                $letter = chr(65 + $index);
                $xml .= '<c r="'.$letter.$n.'" t="inlineStr"><is><t>'
                    .htmlspecialchars($value, ENT_XML1).'</t></is></c>';
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }
}
