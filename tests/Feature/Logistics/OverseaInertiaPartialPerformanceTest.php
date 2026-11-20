<?php

namespace Tests\Feature\Logistics;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class OverseaInertiaPartialPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_load_retains_all_overseas_counts_but_rows_only_reload_skips_them(): void
    {
        $this->withoutVite();
        config()->set('inertia.testing.ensure_pages_exist', false);
        $permission = Permission::firstOrCreate(['key' => 'logistics.view'], ['label' => 'logistics.view']);
        $role = Role::create(['key' => 'oversea-partial-'.uniqid(), 'label' => 'Oversea Viewer']);
        $role->permissions()->attach($permission);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($user)->get('/logistics/oversea?view=review')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Logistics/Oversea/Show')
                ->has('viewCounts', 7));
        $fullCounts = $this->countQueries();
        DB::flushQueryLog();

        $response = $this->actingAs($user)->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? '',
            'X-Inertia-Partial-Component' => 'Logistics/Oversea/Show',
            'X-Inertia-Partial-Data' => 'rows',
        ])->get('/logistics/oversea?view=review');

        $partialCounts = $this->countQueries();
        DB::disableQueryLog();

        $response->assertOk()
            ->assertJsonPath('component', 'Logistics/Oversea/Show')
            ->assertJsonStructure(['props' => ['rows']])
            ->assertJsonMissingPath('props.viewCounts');

        $this->assertGreaterThanOrEqual(7, $fullCounts);
        $this->assertLessThanOrEqual(
            $fullCounts - 7, $partialCounts,
            'Overseas rows-only reload must skip seven unrequested count queries.',
        );
    }

    private function countQueries(): int
    {
        return count(array_filter(
            DB::getQueryLog(),
            fn (array $entry): bool => preg_match('/\\bcount\\s*\\(\\s*\\*\\s*\\)/i', (string) $entry['query']) === 1,
        ));
    }
}
