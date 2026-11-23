<?php

namespace Tests\Feature\Kol;

use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class KolDuplicateIdentityCountryTest extends TestCase
{
    use RefreshDatabase;

    private function staff(array $permissions = ['kol.view', 'kol.operate']): User
    {
        $actor = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'dedup-'.uniqid(), 'label' => 'KOL Test']);
        foreach ($permissions as $permission) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $permission], ['label' => $permission]));
        }
        $actor->roles()->attach($role);
        return $actor;
    }

    private function existing(User $owner, string $display = '_dolordemuelas_'): Kol
    {
        return Kol::query()->create([
            'display_name' => $display, 'owner_id' => $owner->id,
            'status' => 'active', 'relationship_stage' => 'cooperating',
            'contact_email' => 'existing@example.test',
        ]);
    }

    private function payload(string $display, string $username): array
    {
        return [
            'display_name' => $display, 'wordpress_username' => $username,
            'country_code' => 'Mexico', 'contact_email' => 'different-email@example.test',
            'wordpress_password' => 'Abcdefg', 'wordpress_password_confirmation' => 'Abcdefg',
            'affiliate_coupon_code' => 'NEWCODE', 'kol_profile_url' => 'https://example.test/profile',
            'status' => 'active', 'relationship_stage' => 'prospect',
        ];
    }

    public function test_duplicate_display_name_blocks_before_any_new_kol_account_or_coupon(): void
    {
        $actor = $this->staff();
        $this->existing($actor);
        $this->actingAs($actor)->post('/kol/library', $this->payload('_dolordemuelas_', '_dolordemuelas_'))
            ->assertSessionHasErrors('wordpress_username');
        $this->assertDatabaseCount('kols', 1);
        $this->assertDatabaseCount('kol_affiliate_accounts', 0);
        $this->assertDatabaseCount('kol_affiliate_coupons', 0);
    }

    public function test_duplicate_existing_wordpress_login_and_social_handle_block_even_with_different_email(): void
    {
        $actor = $this->staff();
        $existing = $this->existing($actor, 'First name');
        KolAffiliateAccount::query()->create([
            'kol_id' => $existing->id, 'connection_key' => 'beyourlover.com',
            'wordpress_user_login' => '_DoLorDeMuElAs_',
        ]);
        $this->actingAs($actor)->post('/kol/library', $this->payload('Different name', '_dolordemuelas_'))
            ->assertSessionHasErrors('wordpress_username');
        $this->assertDatabaseCount('kols', 1);

        $existing->socialAccounts()->create(['platform' => 'instagram', 'handle' => '@other_creator_', 'is_active' => true]);
        $this->actingAs($actor)->post('/kol/library', $this->payload('Yet another name', 'other_creator_'))
            ->assertSessionHasErrors('wordpress_username');
        $this->assertDatabaseCount('kols', 1);
    }

    public function test_read_only_duplicate_check_is_exact_and_respects_owner_visibility(): void
    {
        $owner = $this->staff();
        $outsider = $this->staff();
        $existing = $this->existing($owner);
        $this->actingAs($owner)->getJson('/kol/library/duplicate-check?wordpress_username=_dolordemuelas_')
            ->assertOk()->assertJsonPath('exists', true)
            ->assertJsonPath('existing_url', route('kol.library.show', $existing));
        $this->actingAs($outsider)->getJson('/kol/library/duplicate-check?wordpress_username=_dolordemuelas_')
            ->assertOk()->assertJsonPath('exists', true)->assertJsonPath('existing_url', null);
        $this->actingAs($owner)->getJson('/kol/library/duplicate-check?wordpress_username=_dolordemuelas_MORE')
            ->assertOk()->assertJsonPath('exists', false);
        $this->assertDatabaseCount('kols', 1);
    }

    public function test_custom_country_is_not_rejected_by_fixed_country_list(): void
    {
        $actor = $this->staff();
        $this->existing($actor);
        $this->actingAs($actor)->post('/kol/library', $this->payload('_dolordemuelas_', '_dolordemuelas_'))
            ->assertSessionHasErrors('wordpress_username')
            ->assertSessionDoesntHaveErrors('country_code');
        $kol = Kol::query()->create([
            'display_name' => 'Country Sample', 'country_code' => 'New Zealand',
            'owner_id' => $actor->id, 'status' => 'active', 'relationship_stage' => 'prospect',
        ]);
        $this->assertSame('New Zealand', $kol->refresh()->country_code);
    }
}
