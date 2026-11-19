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
        $this->actingAs($employee)->postJson('/orders/collaboration/partners', ['name' => 'Partner C'])
            ->assertForbidden();
        $response = $this->actingAs($admin)->postJson('/orders/collaboration/partners', ['name' => 'Partner C'])
            ->assertCreated()->assertJsonPath('partner.name', 'Partner C');
        $partner = OrderCollaborationPartner::query()->findOrFail($response->json('partner.id'));
        $this->assertStringStartsWith('CP_', $partner->code);
        $this->assertDatabaseHas('audit_records', ['action' => 'orders.collaboration_partner.created', 'entity_id' => (string) $partner->id]);
        $this->actingAs($admin)->getJson('/orders/collaboration/partners')
            ->assertJsonCount(3, 'partners')->assertJsonPath('can_manage', true);
        $this->actingAs($admin)->postJson('/orders/collaboration/partners', ['name' => 'Partner C'])
            ->assertUnprocessable();
        $this->actingAs($employee)->patchJson('/orders/collaboration/partners/'.$partner->id, ['name' => 'Renamed'])
            ->assertForbidden();
        $this->actingAs($admin)->patchJson('/orders/collaboration/partners/'.$partner->id, ['is_active' => false])
            ->assertOk()->assertJsonPath('partner.is_active', false);
        $this->actingAs($employee)->getJson('/orders/collaboration/partners')
            ->assertOk()->assertJsonCount(2, 'partners');
    }

    public function test_import_requires_active_partner_and_token_binds_same_partner_without_partial_writes(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create']);
        Product::query()->create(['sku' => 'MF414A', 'name' => 'Product']);
        $chunyi = OrderCollaborationPartner::query()->where('code', 'CHUNYI')->firstOrFail();
        $jenny = OrderCollaborationPartner::query()->where('code', 'AMAZON_JENNY')->firstOrFail();
        $file = $this->xlsx('TEST-CHUNYI-0001');
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
        $order = Order::query()->where('external_order_number', 'TEST-CHUNYI-0001')->firstOrFail();
        $this->assertSame('collaboration', $order->source_type->value);
        $this->assertSame($chunyi->id, $order->collaboration_partner_id);
        $this->assertSame('春意', $order->collaborationPartner?->name);
        $this->assertDatabaseCount('oms_order_import_claims', 1);
        $this->actingAs($user)->get('/orders/collaboration?partner_id='.$chunyi->id)
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Orders/Index')->where('orders.total', 1));
        $this->actingAs($user)->get('/orders/collaboration?partner_id='.$jenny->id)
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Orders/Index')->where('orders.total', 0));
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
