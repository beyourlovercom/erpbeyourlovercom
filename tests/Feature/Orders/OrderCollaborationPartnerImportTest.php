<?php

namespace Tests\Feature\Orders;

use App\Models\Role;
use App\Models\User;
use App\Modules\Logistics\Application\OverseaFiles\ProviderXlsxWriter;
use App\Modules\Orders\Application\OrderOmsBatchImport;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderCollaborationPartner;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class OrderCollaborationPartnerImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite()->withHeader('Accept', 'application/json');
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'collab-'.uniqid(), 'label' => 'Collaboration Test']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    private function xlsx(string $number): UploadedFile
    {
        $row = array_fill_keys(OrderOmsBatchImport::FIELDS, '');
        $row['external_order_number'] = $number;
        $row['order_date'] = '2026-11-19';
        $row['recipient_name'] = 'Synthetic Buyer';
        $row['country'] = 'US';
        $row['province_state'] = 'NY';
        $row['city'] = 'New York';
        $row['address_line_1'] = '1 Test St';
        $row['currency'] = 'USD';
        $row['sku'] = 'MF414A';
        $row['quantity'] = '1';
        $row['unit_price'] = '60.00';

        return UploadedFile::fake()->createWithContent('synthetic.xlsx',
            app(ProviderXlsxWriter::class)->write('订单', OrderOmsBatchImport::FIELDS, [array_values($row)]));
    }

    public function test_admin_can_add_partner_dynamically_but_employee_cannot(): void
    {
        $employee = $this->userWith(['orders.view', 'orders.create']);
        $admin = $this->userWith(['orders.view', 'system.permissions.manage']);
        $this->actingAs($employee)->getJson('/orders/collaboration/partners')->assertOk()
            ->assertJsonPath('can_manage', false)->assertJsonCount(2, 'partners');
        $this->actingAs($employee)->postJson('/orders/collaboration/partners', ['name' => 'Partner C', 'identity_prefix' => 'pc'])
            ->assertForbidden();
        $response = $this->actingAs($admin)->postJson('/orders/collaboration/partners', ['name' => 'Partner C', 'identity_prefix' => 'pc'])
            ->assertCreated()->assertJsonPath('partner.name', 'Partner C')->assertJsonPath('partner.identity_prefix', 'PC');
        $partner = OrderCollaborationPartner::query()->findOrFail($response->json('partner.id'));
        $this->assertStringStartsWith('CP_', $partner->code);
        $this->assertDatabaseHas('audit_records', ['action' => 'orders.collaboration_partner.created', 'entity_id' => (string) $partner->id]);
        $this->actingAs($admin)->getJson('/orders/collaboration/partners')
            ->assertJsonCount(3, 'partners')->assertJsonPath('can_manage', true);
        $this->actingAs($admin)->postJson('/orders/collaboration/partners', ['name' => 'Partner C', 'identity_prefix' => 'pc'])
            ->assertUnprocessable();
        $this->actingAs($employee)->patchJson('/orders/collaboration/partners/'.$partner->id, ['name' => 'Renamed'])
            ->assertForbidden();
        $this->actingAs($admin)->patchJson('/orders/collaboration/partners/'.$partner->id, ['is_active' => false])
            ->assertOk()->assertJsonPath('partner.is_active', false);
        $this->actingAs($employee)->getJson('/orders/collaboration/partners')
            ->assertOk()->assertJsonCount(2, 'partners');
    }

    public function test_prefix_management_preserves_history_and_invalidates_stale_import_preview(): void
    {
        $admin = $this->userWith(['orders.view', 'orders.create', 'system.permissions.manage']);
        $employee = $this->userWith(['orders.view', 'orders.create']);
        Product::query()->create(['sku' => 'MF414A', 'name' => 'Product']);
        $partner = OrderCollaborationPartner::query()->where('code', 'CHUNYI')->firstOrFail();
        $this->assertSame('CY', $partner->identity_prefix);
        $this->actingAs($admin)->postJson('/orders/collaboration/partners', ['name' => 'No Prefix'])->assertUnprocessable();
        $this->postJson('/orders/collaboration/partners', ['name' => 'Duplicate', 'identity_prefix' => 'cy'])->assertUnprocessable();
        $this->postJson('/orders/collaboration/partners', ['name' => 'Invalid', 'identity_prefix' => 'C1'])->assertUnprocessable();
        $this->actingAs($employee)->patchJson('/orders/collaboration/partners/'.$partner->id, ['identity_prefix' => 'NEW'])->assertForbidden();
        $file = $this->xlsx('103769360358527552');
        $data = ['file' => $file, 'import_type' => 'collaboration', 'partner_id' => $partner->id];
        $preview = $this->actingAs($admin)->postJson('/orders/oms/import/preview', $data)->assertOk()->json();
        $this->postJson('/orders/oms/import/confirm', [...$data, 'token' => $preview['token'], 'confirm' => '1'])->assertOk();
        $old = Order::query()->firstOrFail();
        $this->assertSame('CY27552-007', $old->erp_order_id);
        $next = [...$data, 'file' => $this->xlsx('103769142557701265')];
        $stale = $this->postJson('/orders/oms/import/preview', $next)->assertOk()->json();
        $this->patchJson('/orders/collaboration/partners/'.$partner->id, ['identity_prefix' => 'cx'])->assertOk()
            ->assertJsonPath('partner.identity_prefix', 'CX');
        $this->assertSame('CX', $partner->fresh()->identity_prefix);
        $this->assertSame('CY27552-007', $old->fresh()->erp_order_id);
        $this->postJson('/orders/collaboration/partners', ['name' => 'Historical', 'identity_prefix' => 'CY'])->assertUnprocessable();
        $this->postJson('/orders/oms/import/confirm', [...$next, 'token' => $stale['token'], 'confirm' => '1'])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 1);
        $fresh = $this->postJson('/orders/oms/import/preview', $next)->assertOk()->json();
        $this->postJson('/orders/oms/import/confirm', [...$next, 'token' => $fresh['token'], 'confirm' => '1'])->assertOk()
            ->assertJsonPath('orders.0.erp_order_id', 'CX01265-007');
        $this->assertDatabaseHas('audit_records', ['action' => 'orders.collaboration_partner.updated', 'entity_id' => (string) $partner->id]);
    }

    public function test_import_requires_active_partner_and_token_binds_same_partner_without_partial_writes(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create']);
        Product::query()->create(['sku' => 'MF414A', 'name' => 'Product']);
        $chunyi = OrderCollaborationPartner::query()->where('code', 'CHUNYI')->firstOrFail();
        $jenny = OrderCollaborationPartner::query()->where('code', 'AMAZON_JENNY')->firstOrFail();
        $file = $this->xlsx('103769360358527552');
        $inactive = OrderCollaborationPartner::query()->create(['code' => 'INACTIVE', 'name' => 'Disabled', 'is_active' => false]);
        $this->actingAs($user)->postJson('/orders/oms/import/preview',
            ['file' => $file, 'import_type' => 'collaboration', 'partner_id' => $inactive->id])->assertUnprocessable();
        $this->actingAs($user)->postJson('/orders/oms/import/preview',
            ['file' => $file, 'import_type' => 'collaboration'])->assertUnprocessable();
        $this->actingAs($user)->postJson('/orders/oms/import/preview',
            ['file' => $file, 'import_type' => 'collaboration', 'partner_id' => 999999])->assertUnprocessable();
        $preview = $this->actingAs($user)->postJson('/orders/oms/import/preview',
            ['file' => $file, 'import_type' => 'collaboration', 'partner_id' => $chunyi->id])
            ->assertOk()->json();
        $this->assertSame([], $preview['errors']);
        $this->assertDatabaseCount('orders', 0);
        $this->actingAs($user)->postJson('/orders/oms/import/confirm',
            ['file' => $file, 'import_type' => 'collaboration', 'partner_id' => $jenny->id,
                'token' => $preview['token'], 'confirm' => '1'])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->actingAs($user)->postJson('/orders/oms/import/confirm',
            ['file' => $file, 'import_type' => 'collaboration', 'partner_id' => $chunyi->id,
                'token' => $preview['token'], 'confirm' => '1'])->assertOk()->assertJsonPath('created', 1);
        $order = Order::query()->where('external_order_number', '103769360358527552')->firstOrFail();
        $this->assertSame('collaboration', $order->source_type->value);
        $this->assertSame('CY27552-007', $order->erp_order_id);
        $this->assertSame($chunyi->id, $order->collaboration_partner_id);
        $this->assertSame('春意', $order->collaborationPartner?->name);
        $this->assertDatabaseCount('oms_order_import_claims', 1);
        $this->actingAs($user)->get('/orders/collaboration?partner_id='.$chunyi->id)
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Orders/Index')->where('orders.total', 1));
        $this->actingAs($user)->get('/orders/collaboration?partner_id='.$jenny->id)
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Orders/Index')->where('orders.total', 0));
    }

    public function test_collaboration_identity_preserves_zero_and_rejects_collision_before_creation(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create']);
        Product::query()->create(['sku' => 'MF414A', 'name' => 'Product']);
        $partner = OrderCollaborationPartner::query()->where('code', 'CHUNYI')->firstOrFail();
        $identities = app(\App\Modules\Orders\Application\OrderIdentityService::class);
        $before = $identities->setting()->next_customer_number;
        $file = $this->xlsx('103769142557701265');
        $data = ['file' => $file, 'import_type' => 'collaboration', 'partner_id' => $partner->id];
        $preview = $this->actingAs($user)->postJson('/orders/oms/import/preview', $data)->assertOk()->json();
        $this->assertSame([], $preview['errors']);
        $this->postJson('/orders/oms/import/confirm', [...$data, 'token' => $preview['token'], 'confirm' => '1'])
            ->assertOk()->assertJsonPath('orders.0.erp_order_id', 'CY01265-007');
        $this->assertSame($before, $identities->setting()->next_customer_number);
        $this->postJson('/orders/oms/import/preview', [...$data, 'file' => $this->xlsx('9999901265')])
            ->assertOk()->assertJsonPath('valid_count', 0)->assertJsonPath('token', null);
        $this->assertDatabaseCount('orders', 1);
        $jenny = OrderCollaborationPartner::query()->where('code', 'AMAZON_JENNY')->firstOrFail();
        $this->assertSame('AJ01265-007', $identities->previewCollaboration($jenny->id, '9999901265'));
    }

    public function test_repair_requires_explicit_ids_and_count_and_preserves_source_and_audit(): void
    {
        $partner = OrderCollaborationPartner::query()->where('code', 'CHUNYI')->firstOrFail();
        $order = Order::query()->create([
            'source_type' => 'collaboration', 'order_kind' => 'customer',
            'collaboration_partner_id' => $partner->id, 'internal_order_number' => 'LEGACY-CY',
            'external_order_number' => '103769360358527552', 'erp_order_id' => '007-1125-c6175',
            'order_date' => '2026-11-25', 'order_status' => 'pending_shipment', 'currency' => 'USD',
        ]);
        $args = ['--ids' => (string) $order->id];
        $this->artisan('orders:repair-collaboration-identities', $args)->assertExitCode(0);
        $this->assertSame('007-1125-c6175', $order->fresh()->erp_order_id);
        $this->artisan('orders:repair-collaboration-identities', [...$args, '--apply' => true, '--expect-change' => '2'])->assertExitCode(1);
        $this->assertSame('007-1125-c6175', $order->fresh()->erp_order_id);
        $this->artisan('orders:repair-collaboration-identities', [...$args, '--apply' => true, '--expect-change' => '1'])->assertExitCode(0);
        $this->assertSame('CY27552-007', $order->fresh()->erp_order_id);
        $this->assertSame('103769360358527552', $order->fresh()->external_order_number);
        $this->assertDatabaseHas('audit_records', ['action' => 'order.erp_identity_repaired', 'entity_id' => (string) $order->id]);
    }

    public function test_collaboration_csv_exports_exact_tracking_numbers_and_preserves_partner_scope(): void
    {
        $user = $this->userWith(['orders.view']);
        $make = function (string $number, int $partnerId) use ($user): Order {
            return Order::query()->create([
                'source_type' => 'collaboration', 'order_kind' => 'customer',
                'collaboration_partner_id' => $partnerId, 'internal_order_number' => $number,
                'external_order_number' => 'SOURCE-'.$number, 'erp_order_id' => $number,
                'order_date' => '2026-11-25', 'order_status' => 'pending_shipment',
                'currency' => 'USD', 'owner_id' => $user->id,
            ]);
        };
        $west = $make('CY27552-007', 1);
        $east = $make('CY90004-007', 1);
        $pending = $make('CY02301-007', 1);
        $other = $make('AJ27552-007', 2);
        $west->shipment()->create(['shipment_status' => 'pending', 'carrier' => 'USPS', 'tracking_number' => '9235990424546100441192']);
        $east->shipment()->create(['shipment_status' => 'pending', 'carrier' => 'USPS', 'tracking_number' => '0035990416422203119422']);
        $pending->shipment()->create(['shipment_status' => 'pending', 'carrier' => 'USPS', 'tracking_number' => null]);
        $other->shipment()->create(['shipment_status' => 'pending', 'carrier' => 'DHL', 'tracking_number' => 'OTHER-TRACK']);

        $csv = $this->actingAs($user)->get('/orders/collaboration/export?partner_id=1')->assertOk()->streamedContent();
        $rows = array_map('str_getcsv', preg_split('/\r?\n/', trim($csv)));
        $headers = array_shift($rows);
        $this->assertSame(['ERP订单号', '来源订单号', '来源', '合作商', '订单日期', '收件人', '国家', '状态', '负责人', '物流商', '发货单号'], $headers);
        $this->assertCount(3, $rows);
        $byNumber = [];
        foreach ($rows as $row) {
            $this->assertCount(count($headers), $row);
            $byNumber[$row[0]] = array_combine($headers, $row);
        }
        $this->assertSame('USPS', $byNumber['CY27552-007']['物流商']);
        $this->assertSame('USPS', $byNumber['CY02301-007']['物流商']);
        $this->assertSame('9235990424546100441192', $byNumber['CY27552-007']['发货单号']);
        $this->assertSame('0035990416422203119422', $byNumber['CY90004-007']['发货单号']);
        $this->assertSame('', $byNumber['CY02301-007']['发货单号']);
        $this->assertArrayNotHasKey('AJ27552-007', $byNumber);

        $all = $this->get('/orders/collaboration/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('DHL,OTHER-TRACK', $all);
        $admin = $this->userWith(['orders.view', 'customers.export', 'system.permissions.manage']);
        $customer = $this->actingAs($admin)->get('/customers/orders/export')->assertOk()->streamedContent();
        $this->assertNotContains('发货单号', str_getcsv(explode("\n", $customer)[0]));
        $this->assertNotContains('物流商', str_getcsv(explode("\n", $customer)[0]));
    }

    public function test_old_tingon_orders_remain_unassigned_in_collaboration_page(): void
    {
        $user = $this->userWith(['orders.view']);
        $legacy = Order::query()->create([
            'source_type' => 'manual', 'order_kind' => 'customer',
            'internal_order_number' => 'LEGACY-TINGON-001',
            'external_order_number' => 'LEGACY-TINGON-001',
            'order_date' => '2026-11-19', 'order_status' => 'pending_shipment',
            'currency' => 'USD', 'owner_id' => $user->id, 'routing_warehouse_code' => 'TINGON',
        ]);
        $this->actingAs($user)->get('/orders/collaboration?partner_id=unassigned')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Orders/Index')->where('orders.total', 1));
        $this->assertNull($legacy->fresh()->collaboration_partner_id);
        $this->actingAs($user)->get('/orders/collaboration?partner_id=1')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Orders/Index')->where('orders.total', 0));
    }
}
