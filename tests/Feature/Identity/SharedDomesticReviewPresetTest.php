<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Application\RolePreset;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SharedDomesticReviewPresetTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_review_defaults_and_existing_role_migration(): void
    {
        $allowed = [RolePreset::Logistics, RolePreset::DomesticBusiness,
            RolePreset::Operations, RolePreset::Administrator];
        $other = [RolePreset::SalesRepresentative, RolePreset::KolSpecialist, RolePreset::KolManager, RolePreset::Finance, RolePreset::Writer];
        $before = [];
        foreach (RolePreset::cases() as $preset) {
            $role = Role::firstOrCreate(['key'=>$preset->roleKey()], ['label'=>$preset->label()]);
            $before[$preset->value] = $role->permissions()->pluck('key')->all();
        }
        foreach (['logistics.view','domestic.review'] as $key) {
            Permission::firstOrCreate(['key'=>$key], ['label'=>$key]);
        }
        $migration = require database_path('migrations/2026_11_24_150000_share_domestic_review_across_business_roles.php');
        $migration->up();
        $migration->up();
        foreach ($allowed as $preset) {
            self::assertContains('domestic.review', $preset->permissions());
            self::assertContains('logistics.view', $preset->permissions());
            $role = Role::where('key',$preset->roleKey())->firstOrFail();
            $actual = $role->permissions()->pluck('key')->all();
            $expected = array_values(array_unique([...$before[$preset->value], 'logistics.view','domestic.review']));
            sort($actual);sort($expected);
            self::assertSame($expected,$actual);
        }
        foreach ($other as $preset) {
            self::assertNotContains('domestic.review',$preset->permissions());
            $actual=Role::where('key',$preset->roleKey())->firstOrFail()->permissions()->pluck('key')->all();
            self::assertEqualsCanonicalizing($before[$preset->value],$actual);
        }
        self::assertNotContains('logistics.operate',RolePreset::SalesRepresentative->permissions());
        self::assertNotContains('logistics.operate',RolePreset::Operations->permissions());
    }
}
