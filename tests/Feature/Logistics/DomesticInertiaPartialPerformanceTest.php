<?php

namespace Tests\Feature\Logistics;

use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DomesticInertiaPartialPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('inertia.testing.ensure_pages_exist', false);
    }

    public function test_full_domestic_page_keeps_all_nine_view_counts_and_carrier_options(): void
    {
        $user = $this->logisticsViewer();

        $this->actingAs($user)->get('/logistics/china?view=review')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Logistics/Domestic/Index')
                ->has('viewCounts', 9)
                ->has('carrierOptions'));
    }

    public function test_rows_only_inertia_reload_skips_nine_unrequested_count_queries(): void
    {
        $user = $this->logisticsViewer();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($user)->get('/logistics/china?view=review')->assertOk();
        $fullCountQueries = $this->countQueries();
        DB::flushQueryLog();

        $response = $this->actingAs($user)->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? '',
            'X-Inertia-Partial-Component' => 'Logistics/Domestic/Index',
            'X-Inertia-Partial-Data' => 'rows',
        ])->get('/logistics/china?view=review');

        $rowCountQueries = $this->countQueries();
        DB::disableQueryLog();

        $response->assertOk()->assertJsonPath('component', 'Logistics/Domestic/Index')
            ->assertJsonStructure(['props' => ['rows']])
            ->assertJsonMissingPath('props.viewCounts')
            ->assertJsonMissingPath('props.carrierOptions')
            ->assertJsonMissingPath('props.notificationCenter');

        $this->assertGreaterThanOrEqual(9, $fullCountQueries);
        $this->assertLessThanOrEqual(
            $fullCountQueries - 9,
            $rowCountQueries,
            'Rows-only partial requests should skip all nine domestic view-count queries.',
        );
    }

    private function countQueries(): int
    {
        return count(array_filter(
            DB::getQueryLog(),
            fn (array $entry): bool => preg_match('/\bcount\s*\(\s*\*\s*\)/i', (string) $entry['query']) === 1,
        ));
    }

    private function logisticsViewer(): User
    {
        $permission = Permission::firstOrCreate(['key' => 'logistics.view'], ['label' => 'logistics.view']);
        $role = Role::create(['key' => 'domestic-partial-'.uniqid(), 'label' => 'Domestic Partial Viewer']);
        $role->permissions()->attach($permission);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
