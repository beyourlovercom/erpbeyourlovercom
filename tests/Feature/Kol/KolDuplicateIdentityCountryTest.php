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

    public function test_nationality_and_shipping_country_save_as_independent_fields(): void
    {
        $actor = $this->staff();
        $kol = $this->existing($actor, 'Synthetic Nationality Edit');
        $kol->forceFill(['country_code' => 'US', 'shipping_country' => 'MX', 'shipping_customs_id' => 'SYNTHETIC-MX-ID'])->save();

        $this->actingAs($actor)->patch('/kol/library/'.$kol->id, [
            'display_name' => 'Synthetic Nationality Edit',
            'status' => 'active',
            'relationship_stage' => 'cooperating',
            'country_code' => 'New Zealand',
            'shipping_country' => 'MX',
            'shipping_customs_id' => 'SYNTHETIC-MX-ID',
            'social_accounts' => [],
        ])->assertRedirect(route('kol.library.show', $kol))->assertSessionDoesntHaveErrors();

        $kol->refresh();
        self::assertSame('New Zealand', $kol->country_code);
        self::assertSame('MX', $kol->shipping_country);
    }

    public function test_mexico_shipping_id_is_required_on_profile_save_and_encrypted_when_saved(): void
    {
        $actor = $this->staff();
        $kol = $this->existing($actor, 'Customs Mexican Recipient');
        $payload = [
            'display_name' => 'Customs Mexican Recipient', 'status' => 'active',
            'relationship_stage' => 'cooperating', 'country_code' => 'United States',
            'shipping_country' => 'Mexico', 'social_accounts' => [],
        ];
        $this->actingAs($actor)->patch('/kol/library/'.$kol->id, $payload)
            ->assertSessionHasErrors('shipping_customs_id');
        self::assertNull($kol->refresh()->shipping_customs_id);
        $this->actingAs($actor)->patch('/kol/library/'.$kol->id, [
            ...$payload,
            'shipping_customs_id' => 'SYNTHETIC-RFC-MX-ABCD',
        ])->assertRedirect(route('kol.library.show', $kol));
        $kol->refresh();
        self::assertSame('Mexico', $kol->shipping_country);
        self::assertSame('United States', $kol->country_code);
        self::assertSame('SYNTHETIC-RFC-MX-ABCD', $kol->shipping_customs_id);
        self::assertNotSame('SYNTHETIC-RFC-MX-ABCD', $kol->getRawOriginal('shipping_customs_id'));
    }

    public function test_norway_profile_does_not_require_a_personal_voec_number(): void
    {
        $actor = $this->staff();
        $kol = $this->existing($actor, 'Norway Customs Info');
        $this->actingAs($actor)->patch('/kol/library/'.$kol->id, [
            'display_name' => 'Norway Customs Info', 'status' => 'active',
            'relationship_stage' => 'cooperating', 'country_code' => 'Argentina',
            'shipping_country' => 'Norway', 'social_accounts' => [],
        ])->assertRedirect(route('kol.library.show', $kol));
        self::assertSame('Norway', $kol->fresh()->shipping_country);
        self::assertNull($kol->fresh()->shipping_customs_id);
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
