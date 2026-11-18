<?php

namespace Tests\Feature\Orders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class KolOrderIdentitySearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_authorized_search_matches_name_social_handle_affiliate_login_and_exact_id(): void
    {
        $staff = $this->userWith(['orders.create', 'kol.view']);
        $kol = $this->kol($staff, 'Mary Creator');
        $kol->socialAccounts()->create(['platform' => 'instagram', 'handle' => 'mary_social', 'is_active' => true]);
        KolAffiliateAccount::create([
            'kol_id' => $kol->id,
            'connection_key' => 'beyourlover.com',
            'affiliate_id' => 81201,
            'wordpress_user_login' => 'marywp',
            'provider_status' => 'active',
        ]);

        foreach (['Mary', '@mary_social', 'marywp', (string) $kol->id] as $search) {
            $this->actingAs($staff)->getJson('/kol/orders/kol-search?q='.urlencode($search))
                ->assertOk()
                ->assertJsonCount(1)
                ->assertJsonPath('0.value', $kol->id)
                ->assertJsonPath('0.hasAffiliate', true)
                ->assertJsonPath('0.account', 'mary_social');
        }
    }

    public function test_exact_numeric_id_ranks_before_twenty_partial_text_matches(): void
    {
        $staff = $this->userWith(['orders.create', 'kol.view']);
        $target = $this->kol($staff, 'Z Exact ID Creator');
        $id = (string) $target->id;
        for ($index = 0; $index < 25; $index++) {
            $this->kol($staff, 'A Creator '.$id.' Match '.str_pad((string) $index, 3, '0', STR_PAD_LEFT));
        }

        $this->actingAs($staff)->getJson('/kol/orders/kol-search?q='.$id)
            ->assertOk()->assertJsonCount(20)
            ->assertJsonPath('0.value', $target->id);
    }

    public function test_search_is_owner_scoped_excludes_archived_and_requires_both_permissions(): void
    {
        $emma = $this->userWith(['orders.create', 'kol.view']);
        $manager = $this->userWith(['orders.create', 'kol.view', 'kol.views.manage']);
        $noKolView = $this->userWith(['orders.create']);
        $noOrderCreate = $this->userWith(['kol.view']);
        $own = $this->kol($emma, 'Target Own');
        $other = $this->kol($manager, 'Target Other');
        $archived = $this->kol($emma, 'Target Archived');
        $archived->forceFill(['archived_at' => now()])->save();

        $this->actingAs($emma)->getJson('/kol/orders/kol-search?q=Target')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.value', $own->id);
        $this->actingAs($emma)->getJson('/kol/orders/kol-search?q='.$other->id)
            ->assertOk()->assertJsonCount(0);
        $this->actingAs($manager)->getJson('/kol/orders/kol-search?q=Target')
            ->assertOk()->assertJsonCount(2);
        $this->actingAs($noKolView)->getJson('/kol/orders/kol-search?q=Target')->assertForbidden();
        $this->actingAs($noOrderCreate)->getJson('/kol/orders/kol-search?q=Target')->assertForbidden();
    }

    public function test_unbound_kol_is_visible_but_not_selectable_and_query_is_bounded(): void
    {
        $staff = $this->userWith(['orders.create', 'kol.view']);
        $unbound = $this->kol($staff, 'Unbound Influencer');
        for ($index = 0; $index < 28; $index++) {
            $this->kol($staff, 'Bulk Creator '.str_pad((string) $index, 3, '0', STR_PAD_LEFT));
        }

        $this->actingAs($staff)->getJson('/kol/orders/kol-search?q=Unbound')
            ->assertOk()->assertJsonPath('0.value', $unbound->id)
            ->assertJsonPath('0.hasAffiliate', false);
        $this->actingAs($staff)->getJson('/kol/orders/kol-search')
            ->assertOk()->assertJsonCount(20);
        $this->actingAs($staff)->getJson('/kol/orders/kol-search?q='.str_repeat('a', 101))
            ->assertUnprocessable();
    }

    public function test_profile_beyond_former_1500_option_cap_can_be_found_and_preselected(): void
    {
        $staff = $this->userWith(['orders.create', 'kol.view']);
        $rows = [];
        for ($index = 0; $index < 1505; $index++) {
            $rows[] = [
                'display_name' => 'A Bulk '.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'status' => 'active',
                'relationship_stage' => 'cooperating',
                'owner_id' => $staff->id,
            ];
            if (count($rows) === 100) {
                DB::table('kols')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('kols')->insert($rows);
        }

        $late = $this->kol($staff, 'Z The 1506th Influencer');
        KolAffiliateAccount::create([
            'kol_id' => $late->id, 'connection_key' => 'beyourlover.com',
            'affiliate_id' => 81202, 'provider_status' => 'active',
        ]);

        $this->actingAs($staff)->getJson('/kol/orders/kol-search?q=1506th')
            ->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.value', $late->id);
        $this->actingAs($staff)->get('/kol/orders/create?kol_id='.$late->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('selectedKolId', $late->id)
                ->where('kolOptions.0.value', $late->id)
                ->where('kolOptions.0.hasAffiliate', true));
    }

    private function kol(User $owner, string $name): Kol
    {
        return Kol::query()->create([
            'display_name' => $name,
            'status' => 'active',
            'relationship_stage' => 'cooperating',
            'owner_id' => $owner->id,
        ]);
    }

    private function userWith(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'kol-search-'.uniqid(), 'label' => 'KOL Search']);
        foreach ($keys as $key) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key]));
        }
        $user->roles()->attach($role);

        return $user;
    }
}
