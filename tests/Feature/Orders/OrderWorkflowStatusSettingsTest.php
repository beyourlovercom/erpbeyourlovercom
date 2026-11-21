<?php

namespace Tests\Feature\Orders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Orders\Application\OrderWorkflowStatus;
use App\Modules\Orders\Application\OrderWorkflowStatusDisplayCatalog;
use App\Modules\Orders\Application\OrderWorkflowStatusPolicy;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class OrderWorkflowStatusSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_rename_resequence_and_restore_without_changing_order_facts(): void
    {
        $this->withoutVite();
        $admin = $this->user(['system.permissions.manage', 'orders.view', 'orders.edit']);
        $order = Order::query()->create([
            'erp_order_id' => 'SET-'.uniqid(), 'internal_order_number' => 'SET-I-'.uniqid(),
            'external_order_number' => 'SET-E-'.uniqid(), 'source_type' => 'manual',
            'order_kind' => 'customer', 'order_status' => 'pending_shipment',
            'order_date' => '2026-11-21', 'currency' => 'USD',
        ]);
        $catalog = app(OrderWorkflowStatusDisplayCatalog::class);
        $version = $catalog->version();
        $rows = $this->editableRows($catalog);
        foreach ($rows as &$row) {
            if ($row['code'] === 'out_of_stock') {
                $row['sequence'] = '1.2';
                $row['name_zh'] = '暂时缺货';
                $row['name_en'] = 'Stock Pending';
            } elseif ($row['code'] === 'sent') {
                $row['sequence'] = '1.10';
            } elseif ($row['code'] === 'sending') {
                $row['sequence'] = '1.11';
            }
        }
        unset($row);
        $this->actingAs($admin)->get('/settings/statuses')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Settings/Statuses')
                ->has('statuses', count(OrderWorkflowStatus::defaults())));
        $this->actingAs($admin)->put('/settings/statuses', [
            'expected_version' => $version, 'statuses' => $rows,
        ])->assertRedirect();
        $this->assertDatabaseHas('order_workflow_status_display_settings', [
            'code' => 'out_of_stock', 'sequence' => '1.2',
            'name_zh' => '暂时缺货', 'name_en' => 'Stock Pending',
        ]);
        $result = OrderWorkflowStatus::registry();
        $keys = array_keys($result);
        $this->assertLessThan(array_search('sent', $keys), array_search('out_of_stock', $keys));
        $this->assertLessThan(array_search('sending', $keys), array_search('sent', $keys));
        $this->assertSame('暂时缺货', $result['out_of_stock']['zh']);
        $policy = app(OrderWorkflowStatusPolicy::class)->for($order, $admin);
        $this->assertSame('暂时缺货', collect($policy['options'])->firstWhere('code', 'out_of_stock')['zh']);
        $this->assertSame('pending_shipment', $order->fresh()->order_status->value);
        $this->assertNull($order->fresh()->shipment);
        $this->assertDatabaseMissing('order_workflow_status_histories', ['order_id' => $order->id]);

        $this->actingAs($admin)->put('/settings/statuses', [
            'expected_version' => app(OrderWorkflowStatusDisplayCatalog::class)->version(),
            'statuses' => collect(OrderWorkflowStatus::defaults())->map(fn (array $definition, string $code): array => [
                'code' => $code, 'sequence' => $definition['sequence'],
                'name_zh' => $definition['zh'], 'name_en' => $definition['en'],
            ])->values()->all(),
        ])->assertRedirect();
        $this->assertSame(0, DB::table('order_workflow_status_display_settings')->count());
    }

    public function test_non_admin_and_invalid_changes_are_blocked_without_partial_writes(): void
    {
        $this->withoutVite();
        $viewer = $this->user(['orders.view']);
        $admin = $this->user(['system.permissions.manage']);
        $catalog = app(OrderWorkflowStatusDisplayCatalog::class);
        $rows = $this->editableRows($catalog);
        $this->actingAs($viewer)->get('/settings/statuses')->assertForbidden();
        $this->actingAs($viewer)->put('/settings/statuses', [
            'expected_version' => $catalog->version(), 'statuses' => $rows,
        ])->assertForbidden();

        $rows[1]['sequence'] = $rows[0]['sequence'];
        $this->actingAs($admin)->put('/settings/statuses', [
            'expected_version' => $catalog->version(), 'statuses' => $rows,
        ])->assertSessionHasErrors('statuses');
        $this->assertSame(0, DB::table('order_workflow_status_display_settings')->count());

        $rows = $this->editableRows($catalog);
        $rows[0]['code'] = 'invented_shipment';
        $this->actingAs($admin)->put('/settings/statuses', [
            'expected_version' => $catalog->version(), 'statuses' => $rows,
        ])->assertSessionHasErrors('statuses.0.code');
        $this->assertSame(0, DB::table('order_workflow_status_display_settings')->count());

        $rows = $this->editableRows($catalog);
        $this->actingAs($admin)->put('/settings/statuses', [
            'expected_version' => str_repeat('0', 64), 'statuses' => $rows,
        ])->assertSessionHasErrors('statuses');
        $this->assertSame(0, DB::table('order_workflow_status_display_settings')->count());
    }

    public function test_existing_settings_navigation_points_to_the_real_admin_page(): void
    {
        $approved = collect(json_decode(file_get_contents(base_path('menu/APPROVED_MENU.json')), true)['items'])
            ->firstWhere('code', 'settings-statuses');
        $runtime = collect(config('erp_shell_navigation'))->firstWhere('key', 'settings');
        $item = collect($runtime['children'])->firstWhere('key', 'settings-statuses');
        $this->assertSame('/settings/statuses', $approved['url']);
        $this->assertSame('system.permissions.manage', $approved['permission']);
        $this->assertSame($approved['permission'], $item['permission']);
        $this->assertSame($approved['status'], $item['status']);
        $this->assertSame($approved['progress'], $item['progress']);
        $this->assertSame('settings.statuses', $item['route']);
        $this->assertSame('settings.statuses.update', app('router')->getRoutes()->getByName('settings.statuses.update')->getName());
    }

    public function test_sequence_is_compared_by_numeric_segments(): void
    {
        $this->assertLessThan(0, OrderWorkflowStatusDisplayCatalog::compareSequence('1.2', '1.10'));
        $this->assertGreaterThan(0, OrderWorkflowStatusDisplayCatalog::compareSequence('3.4', '1.10'));
    }

    private function editableRows(OrderWorkflowStatusDisplayCatalog $catalog): array
    {
        return collect($catalog->rows())->map(fn (array $row): array => [
            'code' => $row['code'], 'sequence' => $row['sequence'],
            'name_zh' => $row['name_zh'], 'name_en' => $row['name_en'],
        ])->values()->all();
    }

    private function user(array $permissions): User
    {
        $role = Role::create(['key' => 'status-settings-'.uniqid(), 'label' => 'Status Settings']);
        foreach ($permissions as $key) {
            $role->permissions()->syncWithoutDetaching([
                Permission::firstOrCreate(['key' => $key], ['label' => $key])->id,
            ]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
