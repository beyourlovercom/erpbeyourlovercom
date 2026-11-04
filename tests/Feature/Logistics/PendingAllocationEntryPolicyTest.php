<?php

namespace Tests\Feature\Logistics;

use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Logistics\Application\FulfillmentAllocationSubmissionService;
use App\Modules\Logistics\Application\LogisticsStatusSummary;
use App\Modules\Logistics\Application\PendingAllocationEntryPolicy;
use App\Modules\Logistics\Application\ShipmentService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class PendingAllocationEntryPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_status_stays_in_erp_but_only_new_processing_customer_orders_enter_wait_stock(): void
    {
        $processing = $this->order('woocommerce', 'customer', '2026-11-04');
        $this->financials($processing, 'processing', true);
        $pending = $this->order('woocommerce', 'customer', '2026-11-04');
        $this->financials($pending, 'pending', false);
        $onHold = $this->order('woocommerce', 'customer', '2026-11-04');
        $this->financials($onHold, 'on-hold', true);
        $completed = $this->order('woocommerce', 'customer', '2026-11-04');
        $this->financials($completed, 'completed', true);
        $oldProcessing = $this->order('woocommerce', 'customer', '2026-11-03');
        $this->financials($oldProcessing, 'processing', true);
        $newManual = $this->order('manual', 'customer', '2026-11-04');

        $this->assertSame(6, Order::query()->count(), 'Order archive / finance must retain every inbound status');
        $eligibleIds = app(LogisticsStatusSummary::class)->pendingAllocationQuery()->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$processing->id, $newManual->id], $eligibleIds);
        $this->assertFalse(app(PendingAllocationEntryPolicy::class)->allows($pending));
        $this->assertFalse(app(PendingAllocationEntryPolicy::class)->allows($completed));
    }

    public function test_kol_fulfillment_requires_real_order_approval_and_date_boundary(): void
    {
        $newKol = $this->order('kol', 'kol_fulfillment', '2026-11-04');
        $oldKol = $this->order('kol', 'kol_fulfillment', '2026-11-03');
        $this->assertSame([], app(LogisticsStatusSummary::class)->pendingAllocationQuery()->pluck('id')->all());

        $admin = $this->admin();
        $this->actingAs($admin)
            ->post('/kol/orders/'.$newKol->id.'/fulfillment-approval')
            ->assertRedirect();

        $this->assertDatabaseHas('order_operational_markers', [
            'order_id' => $newKol->id,
            'marker' => PendingAllocationEntryPolicy::KOL_APPROVAL_MARKER,
            'marked_by' => $admin->id,
        ]);
        $this->assertSame([$newKol->id], app(LogisticsStatusSummary::class)->pendingAllocationQuery()->pluck('id')->all());
        $this->actingAs($admin)->post('/kol/orders/'.$oldKol->id.'/fulfillment-approval')->assertForbidden();
    }

    public function test_specialist_cannot_approve_kol_fulfillment_and_cannot_force_allocate(): void
    {
        $order = $this->order('manual', 'kol_fulfillment', '2026-11-04');
        $specialist = User::factory()->create(['is_active' => true]);
        $this->actingAs($specialist)->post('/kol/orders/'.$order->id.'/fulfillment-approval')->assertForbidden();
        $this->assertDatabaseMissing('order_operational_markers', [
            'order_id' => $order->id,
            'marker' => PendingAllocationEntryPolicy::KOL_APPROVAL_MARKER,
        ]);

        try {
            app(FulfillmentAllocationSubmissionService::class)->submitManual($order, [], null, $specialist);
            self::fail('Unapproved KOL order bypassed the allocation gate');
        } catch (HttpException $e) {
            self::assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseCount('shipments', 0);
        $this->assertDatabaseCount('fulfillment_allocations', 0);
    }

    public function test_unapproved_new_kol_order_cannot_bypass_wait_stock_by_direct_shipment_action(): void
    {
        $order = $this->order('manual', 'kol_fulfillment', '2026-11-04');
        $shipments = app(ShipmentService::class);
        foreach (['draft', 'ship'] as $action) {
            try {
                if ($action === 'draft') {
                    $shipments->saveDraft($order, [], null);
                } else {
                    $shipments->markShipped($order, ['shipped_at' => now()->toIso8601String()], null);
                }
                self::fail('Unapproved KOL shipping gate bypassed: '.$action);
            } catch (HttpException $e) {
                self::assertSame(409, $e->getStatusCode());
            }
        }
        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_kol_manager_can_only_approve_orders_for_assigned_kols(): void
    {
        $manager = $this->manager();
        $unrelatedManager = $this->manager();
        $kol = Kol::query()->create([
            'display_name' => 'Approval Gate Creator',
            'status' => 'active',
            'relationship_stage' => 'cooperating',
            'owner_id' => $manager->id,
            'approval_manager_id' => $manager->id,
        ]);
        $order = $this->order('manual', 'kol_fulfillment', '2026-11-04');
        $order->forceFill(['kol_id' => $kol->id])->save();

        $this->actingAs($unrelatedManager)
            ->post('/kol/orders/'.$order->id.'/fulfillment-approval')->assertForbidden();
        $this->actingAs($manager)
            ->post('/kol/orders/'.$order->id.'/fulfillment-approval')->assertRedirect();
        $this->assertDatabaseHas('order_operational_markers', [
            'order_id' => $order->id,
            'marked_by' => $manager->id,
        ]);
        $this->assertTrue(app(PendingAllocationEntryPolicy::class)->allows($order));
    }

    private function order(string $source, string $kind, string $date): Order
    {
        return Order::query()->create([
            'internal_order_number' => 'ENTRY-'.uniqid(),
            'erp_order_id' => 'ENTRY-'.uniqid(),
            'source_type' => $source,
            'order_kind' => $kind,
            'external_order_number' => 'ENTRY-'.uniqid(),
            'order_date' => $date,
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
    }

    private function financials(Order $order, string $status, bool $paid): void
    {
        $order->financials()->create([
            'currency' => 'USD',
            'provider' => 'woocommerce',
            'provider_reference' => (string) $order->external_order_number,
            'provider_order_status' => $status,
            'provider_modified_at' => now(),
            'payment_method' => 'woocommerce_payments',
            'payment_transaction_id' => $paid ? 'ENTRY-'.uniqid() : null,
            'paid_at' => $paid ? now() : null,
            'provider_order_total' => '49.00',
            'revenue_provenance' => 'provider_import',
            'completeness_status' => 'INCOMPLETE',
        ]);
    }

    private function manager(): User
    {
        $manager = User::factory()->create(['is_active' => true]);
        $role = Role::query()->create(['key' => 'kol-gate-'.uniqid(), 'label' => 'KOL Manager']);
        $role->permissions()->firstOrCreate(['key' => 'kol.approvals.manage'], ['label' => 'KOL Approvals']);
        $manager->roles()->attach($role);

        return $manager;
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $role = Role::query()->firstOrCreate(['key' => 'super_admin'], ['label' => 'Administrator']);
        $admin->roles()->attach($role);

        return $admin;
    }
}
