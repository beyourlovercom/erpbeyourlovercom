<?php

namespace Tests\Feature\Logistics;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Logistics\Infrastructure\Models\OverseaWarehouseReview;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class WaitStockRejectionAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('inertia.testing.ensure_pages_exist', false);
    }

    public function test_domestic_and_overseas_rejections_have_one_unified_server_filtered_queue(): void
    {
        $operator = $this->viewer();
        $domestic = $this->pendingOrder($operator, '007-DOM-REJECT');
        $domestic->forceFill([
            'domestic_reject_reason' => '收货地址需要核对',
            'domestic_rejected_by' => $operator->id,
            'domestic_rejected_at' => now(),
        ])->save();
        $overseas = $this->pendingOrder($operator, '007-OVER-REJECT');
        $this->overseasReject($overseas, $operator, '仓库库存不足，重新分仓');
        $normal = $this->pendingOrder($operator, '007-NORMAL');

        $this->actingAs($operator)->get('/logistics/wait-stock')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Logistics/FulfillmentAllocation')
                ->where('rejectionCount', 2)
                ->where('rejectionFilter', false)
                ->where('pendingTotal', 3)
                ->where('orders.total', 3)
                ->where('orders.data.0.id', $normal->id)
                ->where('orders.data.1.allocation_rejection.source', '海外仓')
                ->where('orders.data.1.allocation_rejection.reason', '仓库库存不足，重新分仓')
                ->where('orders.data.1.allocation_rejection.by', $operator->name)
                ->where('orders.data.2.allocation_rejection.source', '国内商务')
                ->where('orders.data.2.allocation_rejection.reason', '收货地址需要核对')
                ->where('orders.data.2.allocation_rejection.by', $operator->name)
                ->etc());

        $this->actingAs($operator)->get('/logistics/wait-stock?rejected=1')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('rejectionFilter', true)
                ->where('rejectionCount', 2)
                ->where('pendingTotal', 3)
                ->where('orders.total', 2)
                ->where('orders.data.0.id', $overseas->id)
                ->where('orders.data.1.id', $domestic->id)
                ->etc());
    }

    public function test_rejected_count_and_filter_cover_all_pages_but_exclude_archived_or_resolved(): void
    {
        $operator = $this->viewer();
        $domestic = $this->pendingOrder($operator, '007-OLD-DOM');
        $domestic->forceFill(['domestic_reject_reason' => '运输地址错误'])->save();
        $overseas = $this->pendingOrder($operator, '007-OLD-OVER');
        $this->overseasReject($overseas, $operator, '海外仓退回运营');
        for ($n = 0; $n < 21; $n++) {
            $this->pendingOrder($operator, '007-NORMAL-'.$n);
        }
        $archived = $this->pendingOrder($operator, '007-ARCHIVED');
        $archived->forceFill(['domestic_reject_reason' => '已归档的驳回', 'operational_archived_at' => now()])->save();
        $approved = $this->pendingOrder($operator, '007-APPROVED');
        $this->overseasReject($approved, $operator, '历史驳回说明', 'approved');

        $this->actingAs($operator)->get('/logistics/wait-stock?per_page=20')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('rejectionCount', 2)
                ->where('orders.total', 24)
                ->has('orders.data', 20)
                ->etc());

        $this->actingAs($operator)->get('/logistics/wait-stock?rejected=1&per_page=20')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('rejectionCount', 2)
                ->where('pendingTotal', 24)
                ->where('orders.total', 2)
                ->where('orders.data.0.id', $overseas->id)
                ->where('orders.data.1.id', $domestic->id)
                ->etc());

        $this->actingAs($operator)->get('/logistics/wait-stock?rejected=1&search=007-OLD-DOM')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('rejectionCount', 2)
                ->where('orders.total', 1)
                ->where('orders.data.0.id', $domestic->id)
                ->etc());

        $before = app(\App\Modules\Logistics\Application\AllocationRejectionReadModel::class)
            ->snapshot(app(\App\Modules\Logistics\Application\LogisticsStatusSummary::class)->pendingAllocationQuery());
        $domestic->forceFill(['domestic_reject_reason' => null, 'domestic_rejected_by' => null, 'domestic_rejected_at' => null])->save();
        $replacement = $this->pendingOrder($operator, '007-NEW-REJECTION');
        $replacement->forceFill([
            'domestic_reject_reason' => '刚刚被退回的新订单',
            'domestic_rejected_at' => now()->addMinute(),
        ])->save();
        $after = app(\App\Modules\Logistics\Application\AllocationRejectionReadModel::class)
            ->snapshot(app(\App\Modules\Logistics\Application\LogisticsStatusSummary::class)->pendingAllocationQuery());
        $this->assertSame(2, $before['count']);
        $this->assertSame(2, $after['count']);
        $this->assertNotSame($before['notice_version'], $after['notice_version']);

        $replacement->forceFill(['domestic_reject_reason' => null, 'domestic_rejected_at' => null])->save();
        OverseaWarehouseReview::query()->where('order_id', $overseas->id)->update(['status' => 'approved']);
        $this->actingAs($operator)->get('/logistics/wait-stock?rejected=1')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('rejectionCount', 0)
                ->where('orders.total', 0)
                ->etc());
    }

    private function viewer(): User
    {
        $role = Role::query()->create(['key' => 'rejection-queue-viewer', 'label' => 'Rejection Queue Viewer']);
        foreach (['logistics.view', 'orders.view'] as $key) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['key' => $key], ['label' => $key]));
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }

    private function pendingOrder(User $operator, string $number): Order
    {
        $order = Order::query()->create([
            'internal_order_number' => $number,
            'erp_order_id' => $number,
            'source_type' => 'manual',
            'order_kind' => 'customer',
            'external_order_number' => $number,
            'order_date' => '2026-11-12',
            'owner_id' => $operator->id,
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
        $product = Product::query()->firstOrCreate(['sku' => 'REJECTION-TEST-SKU'], [
            'name' => 'Rejection Test', 'product_type' => 'simple',
            'source_type' => 'manual', 'is_active' => true,
        ]);
        $order->items()->create([
            'product_id' => $product->id, 'sku' => $product->sku,
            'product_summary' => $product->name, 'quantity' => 1,
            'unit_price' => '19.99', 'line_revenue' => '19.99',
        ]);
        $order->address()->create([
            'recipient_name' => 'Test Recipient', 'country' => 'US',
            'province_state' => 'CA', 'city' => 'Los Angeles',
            'address_line_1' => '123 Test Ave', 'postal_code' => '90001',
        ]);
        return $order;
    }

    private function overseasReject(Order $order, User $actor, string $reason, string $status = 'rejected'): void
    {
        OverseaWarehouseReview::query()->create([
            'order_id' => $order->id,
            'status' => $status,
            'note' => $reason,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
        ]);
    }
}
