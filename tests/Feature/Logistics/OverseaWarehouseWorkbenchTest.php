<?php

namespace Tests\Feature\Logistics;

use App\Models\Role;
use App\Models\User;
use App\Modules\Audit\Infrastructure\Models\AuditRecord;
use App\Modules\Inventory\Infrastructure\Models\InventorySyncBatch;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Logistics\Application\OverseaFiles\ProviderXlsxWriter;
use App\Modules\Logistics\Application\ShipOut\ShipOutProductionDispatchState;
use App\Modules\Logistics\Application\ShipOut\ShipOutProductionOmsReadbackService;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsDispatchAttempt;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Sync\Application\Contracts\WooCommerceTrackingGateway;
use App\Modules\Sync\Application\ManualSyncStatus;
use App\Modules\Sync\Application\SyncOrchestrationService;
use App\Modules\Sync\Application\WooCommerceTrackingOutboundService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

final class OverseaWarehouseWorkbenchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // These tests assert Inertia props and authorization, not static Vite
        // build output. The Vue app is independently built/tested in CI.
        $this->withoutVite();
        config()->set('inertia.testing.ensure_pages_exist', false);
    }

    public function test_oversea_global_grid_is_default_with_explicit_legacy_fallback(): void
    {
        $user = $this->userWith(['logistics.view']);
        $this->warehouses();

        $this->actingAs($user)->get('/logistics/oversea?view=review')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('globalGridEnabled', true)
            ->where('gridSearch', '')
            ->where('gridSort.colId', 'line')
            ->where('gridSort.direction', 'desc'));
        $this->actingAs($user)->get('/logistics/oversea?view=review&global_grid=0')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('globalGridEnabled', false));
    }

    public function test_overseas_archive_permission_is_exposed_only_to_authorized_staff(): void
    {
        $viewer = $this->userWith(['logistics.view']);
        $incomplete = $this->userWith(['logistics.view', 'orders.archive']);
        $archiver = $this->userWith(['logistics.view', 'orders.view', 'orders.archive']);
        $this->warehouses();

        $this->actingAs($viewer)->get('/logistics/oversea?view=review')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('canArchive', false));
        $this->actingAs($incomplete)->get('/logistics/oversea?view=review')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('canArchive', false));
        $this->actingAs($archiver)->get('/logistics/oversea?view=review')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('canArchive', true));
    }

    public function test_overseas_batch_archive_reuses_audited_order_endpoint_and_removes_orders_from_active_lists(): void
    {
        $archiver = $this->userWith(['logistics.view', 'orders.view', 'orders.archive']);
        $viewer = $this->userWith(['logistics.view', 'orders.view']);
        $warehouses = $this->warehouses();
        $first = $this->orderAt($warehouses['US-NY'], '007-OVERSEA-ARCHIVE-NY');
        $second = $this->orderAt($warehouses['DE'], '007-OVERSEA-ARCHIVE-DE');
        $payload = ['order_ids' => [$first->id, $second->id]];

        $this->actingAs($viewer)->postJson('/orders/archive/batch', $payload)->assertForbidden();
        $this->assertDatabaseHas('orders', ['id' => $first->id, 'operational_archived_at' => null]);
        $this->actingAs($archiver)->get('/logistics/oversea?view=review')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('rows.total', 2));
        $this->actingAs($archiver)->postJson('/orders/archive/batch', $payload)
            ->assertOk()->assertJsonPath('success_count', 2)->assertJsonPath('failed_count', 0);
        self::assertNotNull($first->fresh()->operational_archived_at);
        self::assertNotNull($second->fresh()->operational_archived_at);
        $this->assertDatabaseHas('audit_records', ['action' => 'order.operational_archived', 'entity_id' => $first->id]);
        $this->assertDatabaseHas('shipments', ['order_id' => $first->id, 'shipment_status' => 'pending']);
        $this->assertDatabaseHas('shipments', ['order_id' => $second->id, 'shipment_status' => 'pending']);
        foreach (['review', 'pending', 'stockout', 'all'] as $view) {
            $this->actingAs($archiver)->get('/logistics/oversea?view='.$view)
                ->assertOk()->assertInertia(fn (Assert $page) => $page->where('rows.total', 0));
        }
        $this->actingAs($archiver)->postJson('/orders/archive/batch', $payload)
            ->assertOk()->assertJsonPath('success_count', 0)->assertJsonPath('failed_count', 2);
    }

    public function test_oversea_grid_search_filters_canonical_orders_and_ignores_unapproved_sort_fields(): void
    {
        $user = $this->userWith(['logistics.view']);
        $warehouses = $this->warehouses();
        $matching = $this->orderAt($warehouses['US-NY'], '007-GRID-FINDME');
        $this->orderAt($warehouses['US-NY'], '007-GRID-HIDDEN');

        $this->actingAs($user)->get('/logistics/oversea/us-ny?view=review&search=FINDME&sort_col=unsafe_column')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('globalGridEnabled', true)
            ->where('rows.total', 1)
            ->where('rows.data.0.order_id', $matching->id)
            ->where('gridSort.colId', 'line'));
        $this->actingAs($user)->get('/logistics/oversea/us-ny?view=review&filter_carrier=NONEXISTENT')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('rows.total', 0));
        // The explicit fallback does not acquire new filter semantics.
        $this->actingAs($user)->get('/logistics/oversea/us-ny?view=review&global_grid=0&search=FINDME')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('rows.total', 2));
    }

    public function test_overseas_review_stockout_holds_shipping_and_restock_reuses_approval_gate(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-NY'], '007-OVERSEA-GRID-STOCKOUT');

        $this->actingAs($user)->get('/logistics/oversea/us-ny?view=review')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rows.data.0.order_date', '2026-09-10'));
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/stockout", [])->assertRedirect();
        $this->assertDatabaseHas('oversea_warehouse_reviews', ['order_id' => $order->id, 'status' => 'stockout']);
        $this->assertDatabaseHas('audit_records', ['action' => 'oversea.review_stockout']);
        $this->actingAs($user)->get('/logistics/oversea/us-ny?view=review')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('rows.total', 0));
        $this->actingAs($user)->get('/logistics/oversea/us-ny?view=pending')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('rows.total', 0));
        $this->actingAs($user)->get('/logistics/oversea/us-ny?view=stockout')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rows.total', 1)
            ->where('rows.data.0.review_status', 'stockout')
            ->where('rows.data.0.workflow_status_projection.zh', '缺货'));
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve", [])->assertStatus(409);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'shipment_status' => 'pending', 'tracking_number' => null,
        ]);

        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/restock", [])->assertRedirect();
        $this->assertDatabaseHas('oversea_warehouse_reviews', [
            'order_id' => $order->id, 'status' => 'approved', 'reviewed_warehouse_id' => $warehouses['US-NY']->id,
        ]);
        $this->assertDatabaseHas('audit_records', ['action' => 'oversea.review_restock']);
        $this->actingAs($user)->get('/logistics/oversea/us-ny?view=stockout')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('rows.total', 0));
        $this->actingAs($user)->get('/logistics/oversea/us-ny?view=pending')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('rows.total', 1));
    }

    public function test_overseas_batch_review_applies_independently_and_never_bypasses_review_role(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $viewer = $this->userWith(['logistics.view']);
        $warehouses = $this->warehouses();
        $first = $this->orderAt($warehouses['US-SM'], '007-OVERSEA-BULK-A');
        $second = $this->orderAt($warehouses['US-SM'], '007-OVERSEA-BULK-B');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$second->id}/approve")->assertRedirect();

        $payload = ['order_ids' => [$first->id, $second->id], 'action' => 'stockout'];
        $this->actingAs($viewer)->postJson('/logistics/oversea/batch-review', $payload)->assertForbidden();
        $this->actingAs($user)->postJson('/logistics/oversea/batch-review', $payload)
            ->assertOk()->assertJsonPath('success_count', 1)->assertJsonPath('failed_count', 1)
            ->assertJsonPath('results.0.order_id', $first->id)
            ->assertJsonPath('results.0.ok', true)
            ->assertJsonPath('results.1.ok', false);
        $this->assertDatabaseHas('oversea_warehouse_reviews', ['order_id' => $first->id, 'status' => 'stockout']);
        $this->assertDatabaseHas('oversea_warehouse_reviews', ['order_id' => $second->id, 'status' => 'approved']);
        $this->actingAs($user)->postJson('/logistics/oversea/batch-review', [
            'order_ids' => [$first->id], 'action' => 'reject',
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_warehouse_page_is_isolated_and_uses_frozen_carrier_mapping(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $ny = $this->orderAt($warehouses['US-NY'], '007-OVERSEA-NY');
        $this->orderAt($warehouses['DE'], '007-OVERSEA-DE', 'DE');

        $this->actingAs($user)->get('/logistics/oversea/us-ny?view=review')->assertInertia(fn (Assert $page) => $page
            ->component('Logistics/Oversea/Show')
            ->where('warehouse.label', '美东纽约仓')
            ->where('rows.total', 1)
            ->where('rows.data.0.order_id', $ny->id)
            ->where('rows.data.0.line', 1)
            ->where('rows.data.0.carrier', 'USPS')
            ->where('rows.data.0.review_status', 'pending'));
    }

    public function test_overseas_review_list_renders_row_with_enum_manual_sync_status(): void
    {
        $viewer = $this->userWith(['logistics.view']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-NY'], '007-OVERSEA-ENUM-STATUS');
        $order->manualSync()->create(['website_sync_status' => ManualSyncStatus::Failed]);

        $this->actingAs($viewer)->get('/logistics/oversea/us-ny?view=review')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Logistics/Oversea/Show')
            ->where('rows.total', 1)
            ->where('rows.data.0.order_id', $order->id)
            ->where('rows.data.0.tracking_sync_status', 'pending'));
    }

    public function test_three_sm_uses_canonical_us_3sm_url_and_legacy_keys_still_resolve(): void
    {
        $user = $this->userWith(['logistics.view']);
        $warehouses = $this->warehouses();
        $this->orderAt($warehouses['US-SM'], '007-OVERSEA-SJ');

        $this->actingAs($user)->get('/logistics/oversea/us-3sm')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('warehouseKey', 'us-3sm')
            ->where('warehouse.code', 'US-SM')
            ->where('warehouse.label', '美国3世迈仓'));
        $this->actingAs($user)->get('/logistics/oversea/us-sm')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('warehouseKey', 'us-3sm')
            ->where('warehouse.code', 'US-SM'));
    }

    public function test_existing_us_sm_workbench_shows_verified_oms_row_without_network_or_production_send(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-SM'], '007-OMS-INLINE-TEST');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        XlwmsDispatchAttempt::query()->create([
            'order_id' => $order->id, 'warehouse_id' => $warehouses['US-SM']->id,
            'third_order_no' => 'BYL-ERP-'.$order->id, 'state' => 'submitted_oms',
            'request_fingerprint' => str_repeat('f', 64),
            'oms_order_no' => 'OBS-CANCELLED-1', 'oms_status_code' => '4',
            'oms_status_checked_at' => now(), 'oms_tracking_no' => null,
        ]);
        Http::preventStrayRequests();
        Http::fake();
        $this->actingAs($user)->get('/logistics/oversea/us-3sm?view=exceptions')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Logistics/Oversea/Show')
            ->where('xlwmsAuthorizedOrderId', 0)
            ->where('rows.data.0.order_id', $order->id)
            ->where('rows.data.0.xlwms.oms_order_no', 'OBS-CANCELLED-1')
            ->where('rows.data.0.xlwms.oms_status_code', '4'));
        Http::assertNothingSent();
    }

    public function test_r12_3sm_oms_draft_is_pending_processing_is_issued_cancelled_is_exception(): void
    {
        $actor = $this->userWith(['logistics.view', 'logistics.operate']);
        $w = $this->warehouses();
        foreach ([
            ['007-3SM-R12-DRAFT', '0', 'OBS-R12-DRAFT'],
            ['007-3SM-R12-PROCESSING', '2', 'OBS-R12-PROCESSING'],
            ['007-3SM-R12-CANCELLED', '4', 'OBS-R12-CANCELLED'],
        ] as [$no, $status, $omsNo]) {
            $order = $this->orderAt($w['US-SM'], $no);
            $this->actingAs($actor)->post("/logistics/oversea/orders/{$order->id}/approve")
                ->assertRedirect();
            XlwmsDispatchAttempt::query()->create([
                'order_id' => $order->id, 'warehouse_id' => $w['US-SM']->id,
                'third_order_no' => 'BYL-ERP-'.$order->id,
                'state' => $status === '0' ? 'oms_draft_created' : 'submitted_oms',
                'request_fingerprint' => str_repeat('f', 64),
                'oms_order_no' => $omsNo, 'oms_status_code' => $status,
                'oms_status_checked_at' => now(),
            ]);
        }
        $this->actingAs($actor)->get('/logistics/oversea/us-3sm?view=pending')
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.total', 1)
                ->where('rows.data.0.xlwms.oms_status_code', '0'));
        $this->get('/logistics/oversea/us-3sm?view=issued')
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.total', 1)
                ->where('rows.data.0.xlwms.oms_status_code', '2')
                ->where('rows.data.0.warehouse_dispatch.status', 'oms_processing'));
        $this->get('/logistics/oversea/us-3sm?view=exceptions')
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.total', 1)
                ->where('rows.data.0.xlwms.oms_status_code', '4'));
    }

    public function test_exact_allowlist_exposed_only_to_real_logistics_operator_and_other_warehouses_unchanged(): void
    {
        $operator = $this->userWith(['logistics.view', 'logistics.operate']);
        $viewer = $this->userWith(['logistics.view']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-SM'], '007-OMS-SEND-ALLOWLIST');
        config()->set('xlwms.live_dispatch_enabled', true);
        config()->set('xlwms.live_order_id', $order->id);
        $this->actingAs($operator)->get('/logistics/oversea/us-3sm?view=review')
            ->assertInertia(fn (Assert $page) => $page->where('xlwmsAuthorizedOrderId', $order->id));
        $this->actingAs($viewer)->get('/logistics/oversea/us-3sm?view=review')
            ->assertInertia(fn (Assert $page) => $page->where('xlwmsAuthorizedOrderId', 0));
        $this->actingAs($operator)->get('/logistics/oversea/us-moreplus?view=review')
            ->assertInertia(fn (Assert $page) => $page->where('xlwmsAuthorizedOrderId', 0));
    }

    public function test_historical_cancelled_oms_attempt_does_not_block_erp_manual_ship(): void
    {
        $operator = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-SM'], '007-OMS-CANCELLED-NO-MANUAL');
        $this->actingAs($operator)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        XlwmsDispatchAttempt::query()->create([
            'order_id' => $order->id, 'warehouse_id' => $warehouses['US-SM']->id,
            'third_order_no' => 'BYL-ERP-'.$order->id, 'state' => 'draft_cancelled_confirmed',
            'request_fingerprint' => str_repeat('e', 64),
            'oms_order_no' => 'OBS-CANCELLED-2', 'oms_status_code' => '4',
        ]);
        Http::preventStrayRequests();
        Http::fake();
        $this->actingAs($operator)->post(
            "/logistics/oversea/us-3sm/orders/{$order->id}/manual-ship",
            ['tracking_number' => '9400111899223856928501']
        )->assertRedirect('/logistics/oversea/us-3sm?view=shipped')->assertSessionHas('success');
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'shipment_status' => 'shipped', 'tracking_number' => '9400111899223856928501',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id, 'order_status' => 'shipped',
        ]);
        // No Woo reference: ERP still ships, AfterShip reports attention.
        // Neither historical OMS state nor the confirmation makes any OMS request.
        Http::assertNothingSent();
        $this->assertDatabaseMissing('sync_outbox', [
            'operation' => 'woocommerce.tracking_outbound',
        ]);
    }

    public function test_legacy_lowercase_us_la_code_is_normalized_across_page_approval_and_reopen(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $warehouses['US-LA']->forceFill(['code' => 'us-la'])->save();
        $order = $this->orderAt($warehouses['US-LA']->fresh(), '007-OVERSEA-USLA-LEGACY');

        $this->actingAs($user)->get('/logistics/oversea/us-moreplus?view=review')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('warehouse.code', 'US-LA')
            ->where('rows.total', 1)
            ->where('rows.data.0.order_id', $order->id)
            ->where('rows.data.0.carrier', 'USPS'));

        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'warehouse_id' => $warehouses['US-LA']->id,
            'carrier' => 'USPS',
            'shipment_status' => 'pending',
        ]);
        $this->assertDatabaseHas('oversea_warehouse_reviews', [
            'order_id' => $order->id,
            'status' => 'approved',
            'reviewed_warehouse_id' => $warehouses['US-LA']->id,
        ]);

        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/reopen-review")
            ->assertRedirect('/logistics/oversea/us-moreplus?view=review');
    }

    public function test_approval_moves_order_from_review_to_pending_and_persists_default_carrier(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['DE'], '007-OVERSEA-APPROVE', 'DE');

        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();

        $this->assertDatabaseHas('oversea_warehouse_reviews', [
            'order_id' => $order->id,
            'status' => 'approved',
            'reviewed_warehouse_id' => $warehouses['DE']->id,
        ]);
        $this->assertDatabaseHas('audit_records', [
            'action' => 'oversea.review_approve', 'actor_id' => $user->id,
        ]);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'warehouse_id' => $warehouses['DE']->id,
            'carrier' => 'DHL',
            'shipment_status' => 'pending',
        ]);

        $this->actingAs($user)->get('/logistics/oversea/de-tianyun?view=pending')->assertInertia(
            fn (Assert $page) => $page->where('rows.total', 1)->where('rows.data.0.order_id', $order->id)
                ->where('rows.data.0.reviewer_name', $user->name)
                ->where('rows.data.0.reviewed_at', fn ($date) => is_string($date) && $date !== '')
        );
        $this->actingAs($user)->get('/logistics/oversea/de-tianyun?view=review')->assertInertia(
            fn (Assert $page) => $page->where('rows.total', 0)
        );
    }

    public function test_approved_order_can_be_reopened_for_review_and_reassignment(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007-OVERSEA-REOPEN');

        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve", ['note' => '审核通过'])->assertRedirect();
        $response = $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/reopen-review");
        $response->assertRedirect('/logistics/oversea/us-moreplus?view=review');

        $this->assertDatabaseHas('oversea_warehouse_reviews', [
            'order_id' => $order->id, 'status' => 'pending', 'progress' => 'pending_review',
            'reviewed_warehouse_id' => null, 'reviewed_by' => null, 'reviewed_at' => null,
        ]);
        $this->assertDatabaseHas('audit_records', [
            'action' => 'oversea.review_reopen', 'actor_id' => $user->id,
        ]);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'warehouse_id' => $warehouses['US-LA']->id, 'carrier' => 'USPS',
        ]);
    }

    public function test_dede_can_change_warehouse_and_carrier_follows_the_new_warehouse(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007-OVERSEA-CHANGE');

        $this->actingAs($user)->patch("/logistics/oversea/orders/{$order->id}/draft", [
            'warehouse_id' => $warehouses['CA']->id,
            'note' => 'Vita 仓库选择错误',
        ])->assertRedirect();

        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'warehouse_id' => $warehouses['CA']->id,
            'carrier' => 'Canada Post',
        ]);
        $this->assertDatabaseHas('oversea_warehouse_reviews', [
            'order_id' => $order->id,
            'submitted_warehouse_id' => $warehouses['US-LA']->id,
            'status' => 'pending',
            'note' => 'Vita 仓库选择错误',
        ]);
    }

    public function test_manual_carrier_override_survives_approval(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007-OVERSEA-MANUAL-CARRIER');

        $this->actingAs($user)->patch("/logistics/oversea/orders/{$order->id}/draft", [
            'carrier' => 'FedEx',
        ])->assertRedirect();
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'carrier' => 'FedEx', 'carrier_selection_source' => 'manual',
        ]);

        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'carrier' => 'FedEx', 'carrier_selection_source' => 'manual',
        ]);
    }

    public function test_tracking_number_can_be_entered_before_shipping(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-NY'], '007-OVERSEA-TRACKING');

        $this->actingAs($user)->patch("/logistics/oversea/orders/{$order->id}/draft", [
            'tracking_number' => '9400111899223856928499',
        ])->assertRedirect();
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'tracking_number' => '9400111899223856928499', 'shipment_status' => 'pending',
        ]);
    }

    public function test_moreplus_usps_tracking_entered_as_remark_is_routed_to_draft_tracking_without_shipping(): void
    {
        $actor = $this->userWith(['logistics.view', 'logistics.operate']);
        $w = $this->warehouses();
        $order = $this->orderAt($w['US-LA'], '007-MOREPLUS-MISPLACED-TRACKING');
        $this->actingAs($actor)->post("/logistics/oversea/orders/{$order->id}/approve")
            ->assertRedirect();
        Http::preventStrayRequests();

        $number = '9434650105794040132223';
        $this->actingAs($actor)
            ->patch("/logistics/oversea/orders/{$order->id}/draft", ['note' => $number])
            ->assertRedirect()
            ->assertSessionHas('success', fn ($value) => str_contains($value, 'Tracking No.'));
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'tracking_number' => $number,
            'shipment_status' => 'pending',
        ]);
        $this->assertDatabaseHas('oversea_warehouse_reviews', [
            'order_id' => $order->id, 'note' => null,
        ]);
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_moreplus_tracking_in_remark_cannot_overwrite_existing_or_duplicate_other_order(): void
    {
        $actor = $this->userWith(['logistics.view', 'logistics.operate']);
        $w = $this->warehouses();
        $first = $this->orderAt($w['US-LA'], '007-TRACKING-SAFE-1');
        $second = $this->orderAt($w['US-LA'], '007-TRACKING-SAFE-2');
        $number = '9434650105794040132223';
        Http::preventStrayRequests();
        $this->actingAs($actor)
            ->patch("/logistics/oversea/orders/{$first->id}/draft", ['note' => $number])
            ->assertRedirect();
        $this->patch("/logistics/oversea/orders/{$second->id}/draft", ['note' => $number])
            ->assertStatus(409);
        $this->patch("/logistics/oversea/orders/{$first->id}/draft", [
            'note' => '9235990416420904220003',
        ])->assertStatus(409);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $first->id, 'tracking_number' => $number, 'shipment_status' => 'pending',
        ]);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $second->id, 'tracking_number' => null, 'shipment_status' => 'pending',
        ]);
        Http::assertNothingSent();
    }

    public function test_review_only_operator_cannot_smuggle_tracking_through_moreplus_note(): void
    {
        $viewer = $this->userWith(['logistics.view']);
        $w = $this->warehouses();
        $order = $this->orderAt($w['US-LA'], '007-MOREPLUS-TRACKING-PERMISSION');
        Http::preventStrayRequests();
        $this->actingAs($viewer)
            ->patch("/logistics/oversea/orders/{$order->id}/draft", [
                'note' => '9434650105794040132223',
            ])->assertForbidden();
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'tracking_number' => null,
            'shipment_status' => 'pending',
        ]);
        $this->assertDatabaseMissing('oversea_warehouse_reviews', [
            'order_id' => $order->id,
            'note' => '9434650105794040132223',
        ]);
        Http::assertNothingSent();
    }

    public function test_moreplus_human_note_and_other_warehouse_number_remain_notes(): void
    {
        $actor = $this->userWith(['logistics.view', 'logistics.operate']);
        $w = $this->warehouses();
        $moreplus = $this->orderAt($w['US-LA'], '007-MOREPLUS-NOTE');
        $other = $this->orderAt($w['US-NY'], '007-NY-NOTE');
        Http::preventStrayRequests();
        $this->actingAs($actor)
            ->patch("/logistics/oversea/orders/{$moreplus->id}/draft", [
                'note' => '等海运 / 缺货',
            ])->assertRedirect();
        $this->patch("/logistics/oversea/orders/{$other->id}/draft", [
            'note' => '9434650105794040132223',
        ])->assertRedirect();
        $this->assertDatabaseHas('oversea_warehouse_reviews', [
            'order_id' => $moreplus->id, 'note' => '等海运 / 缺货',
        ]);
        $this->assertDatabaseHas('oversea_warehouse_reviews', [
            'order_id' => $other->id, 'note' => '9434650105794040132223',
        ]);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $other->id, 'tracking_number' => null,
        ]);
        Http::assertNothingSent();
    }

    public function test_rejection_requires_reason_clears_assignment_and_notifies_all_operations_users(): void
    {
        $dede = $this->userWith(['logistics.view', 'logistics.operate']);
        $opsA = $this->operationsUser('vita-a@example.test');
        $opsB = $this->operationsUser('vita-b@example.test');
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-NY'], '007-OVERSEA-REJECT');

        $this->actingAs($dede)->post("/logistics/oversea/orders/{$order->id}/reject", [])->assertSessionHasErrors('note');
        $this->actingAs($dede)->post("/logistics/oversea/orders/{$order->id}/reject", ['note' => '太贵，退回重新分仓'])->assertRedirect();

        $this->assertDatabaseHas('oversea_warehouse_reviews', [
            'order_id' => $order->id,
            'status' => 'rejected',
            'note' => '太贵，退回重新分仓',
        ]);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'warehouse_id' => null,
            'warehouse_selection_source' => null,
            'carrier' => null,
            'carrier_selection_source' => null,
        ]);

        foreach ([$opsA, $opsB] as $ops) {
            $notification = $ops->notifications()->first();
            $this->assertNotNull($notification);
            $this->assertSame('海外仓分配被驳回', $notification->data['title']);
            $this->assertSame('/logistics/wait-stock', $notification->data['action_url']);
            $this->assertStringContainsString('太贵，退回重新分仓', $notification->data['message']);
        }
    }

    public function test_rejected_oversea_order_can_be_reallocated_and_returns_to_dede_review(): void
    {
        $operator = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-NY'], '007-OVERSEA-RETRY');
        $this->markAsPaidWooOrder($order, 'WC-OVERSEA-RETRY');
        // Reproduce actual Production: initial Vita allocation was already
        // submitted before Dede rejected the overseas warehouse assignment.
        $order->fulfillmentAllocation()->create([
            'status' => 'submitted', 'decision_source' => 'manual', 'submitted_by' => $operator->id,
        ]);

        $this->actingAs($operator)->post("/logistics/oversea/orders/{$order->id}/reject", [
            'note' => '收件资料有误，请重新核对',
        ])->assertRedirect();
        $this->assertDatabaseHas('audit_records', ['action' => 'oversea.review_reject']);
        $audit = AuditRecord::query()
            ->where('action', 'oversea.review_reject')->firstOrFail();
        $this->assertSame($operator->id, $audit->actor_id);
        $this->assertSame('收件资料有误，请重新核对', $audit->after['reason']);

        $this->actingAs($operator)->get('/logistics/wait-stock?rejected=1')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rejectionCount', 1)
            ->where('orders.total', 1)
            ->where('orders.data.0.allocation_rejection.reason', '收件资料有误，请重新核对')
            ->etc());

        $item = $order->items()->firstOrFail();
        $this->actingAs($operator)->postJson("/logistics/orders/{$order->id}/fulfillment-allocation/manual", [
            'assignments' => [[
                'order_item_id' => $item->id,
                'source_code' => 'US-NY',
                'carrier' => 'USPS',
            ]],
            'decision_note' => 'Vita 确认修改后重新分仓',
        ])->assertOk();

        $this->assertDatabaseHas('oversea_warehouse_reviews', [
            'order_id' => $order->id,
            'status' => 'pending',
            'note' => null,
            'reviewed_at' => null,
            'reviewed_by' => null,
        ]);
        $this->actingAs($operator)->get('/logistics/wait-stock?rejected=1')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rejectionCount', 0)
            ->where('orders.total', 0)
            ->etc());
        $this->actingAs($operator)->get('/logistics/oversea/us-ny?view=review')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rows.total', 1)
            ->where('rows.data.0.order_id', $order->id)
            ->where('rows.data.0.review_status', 'pending')
            ->etc());
    }

    public function test_export_fails_closed_with_422_for_noncanonical_order_reference(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], 'LEGACY-NONCANONICAL-ID');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();

        $this->actingAs($user)->get('/logistics/oversea/us-moreplus/export')->assertStatus(422);
    }

    public function test_moreplus_export_fails_closed_when_recipient_phone_is_missing(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007erp-0920-c40760');
        $order->address()->update(['phone' => '']);
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();

        $this->actingAs($user)->get('/logistics/oversea/us-moreplus/export')->assertStatus(422);
    }

    public function test_pending_moreplus_orders_download_real_xlsx_block_rows(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007erp-0920-c40761');
        $order->items()->create([
            'product_summary' => 'Second Product', 'sku' => 'SECOND-SKU', 'quantity' => 3,
            'unit_price' => '10.00', 'line_revenue' => '30.00',
        ]);
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();

        $response = $this->actingAs($user)->get('/logistics/oversea/us-moreplus/export');
        $response->assertOk();
        $this->assertStringContainsString('attachment; filename="007-us-moreplus-orders-', (string) $response->headers->get('content-disposition'));
        $this->assertStringEndsWith('.xlsx"', (string) $response->headers->get('content-disposition'));
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('content-type'));

        $rows = $this->xlsxRows($response->getContent());
        $this->assertSame('Order NO', $rows[0][0]);
        $this->assertSame('C40761-007', $rows[1][0]);
        $this->assertSame('REVIEW-SKU', $rows[1][1]);
        $this->assertSame('Review Company', $rows[1][6]);
        $this->assertSame('+1 555 0100', $rows[1][13]);
        $this->assertSame('review.recipient@example.test', $rows[1][14]);
        $this->assertSame('Selected by Warehouse', $rows[1][15]);
        $this->assertSame('', $rows[2][0]);
        $this->assertSame('SECOND-SKU', $rows[2][1]);
        $this->assertSame('3', $rows[2][2]);
    }

    public function test_wms_family_exports_real_usny_us3sm_and_de_profiles(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $cases = [
            ['US-NY', '007erp-0920-c40801', 'US', '/logistics/oversea/us-ny/export', ['US01', 'C40801-007', 'USPS-NJ', '']],
            ['US-SM', '007erp-0920-c40802', 'US', '/logistics/oversea/us-3sm/export', ['Yangfan', 'C40802-007', 'Upload_Shipping_Label', 'USPS']],
            ['DE', '007erp-0920-c40803', 'NL', '/logistics/oversea/de-tianyun/export', ['Hannover', 'C40803-007', 'DHL PAKET International N', '']],
        ];

        foreach ($cases as [$code, $erp, $country, $url, $expected]) {
            $order = $this->orderAt($warehouses[$code], $erp, $country);
            $this->markAsPaidWooOrder($order, 'WC-'.$code);
            $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
            $response = $this->actingAs($user)->get($url)->assertOk();
            $rows = $this->xlsxRows($response->getContent());
            $this->assertSame('Warehouse Code/仓库代码', $rows[0][0]);
            $this->assertSame($expected[0], $rows[1][0]);
            $this->assertSame('Woocommerce', $rows[1][1]);
            if ($code === 'US-SM') {
                $this->assertStringStartsWith($expected[1].'-R', $rows[1][3]);
                $this->assertSame($rows[1][3], $rows[1][4]);
            } else {
                $this->assertSame($expected[1], $rows[1][3]);
                $this->assertSame('WC-'.$code, $rows[1][4]);
            }
            $this->assertSame($expected[2], $rows[1][5]);
            $this->assertSame($expected[3], $rows[1][6] ?? '');
            $this->assertSame('+1 555 0100', $rows[1][11]);
            $this->assertSame('review.recipient@example.test', $rows[1][12]);
            $this->assertSame('Review Company', $rows[1][14]);
            $this->assertSame('REVIEW-SKU', $rows[1][24]);
            $this->assertSame('2', $rows[1][25]);
            $rawXml = $this->xlsxXml($response->getContent());
            if ($code === 'US-SM') {
                $this->assertStringContainsString(
                    '<c r="Z2" t="inlineStr"><is><t xml:space="preserve">2</t></is></c>',
                    $rawXml
                );
            } else {
                // Do not alter established quantity cell types for other warehouses.
                $this->assertStringContainsString('<c r="Z2"><v>2</v></c>', $rawXml);
            }
        }
    }

    public function test_canada_export_matches_real_reference_and_provider_profile(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['CA'], '007erp-0920-k26011301', 'CA');
        $this->markAsPaidWooOrder($order, 'WC-CA-26011301');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();

        $response = $this->actingAs($user)->get('/logistics/oversea/ca/export')->assertOk();
        $rows = $this->xlsxRows($response->getContent());
        $this->assertSame('客户参考号', $rows[0][0]);
        $this->assertSame('KOL26011301', $rows[1][0]);
        $this->assertSame('CA02_多伦多仓', $rows[1][1]);
        $this->assertSame('CAPOST[加拿大邮政多伦多]', $rows[1][2]);
        $this->assertSame('Woocommerce', $rows[1][13]);
        $this->assertSame('WC-CA-26011301', $rows[1][14]);
        $this->assertSame('CA', $rows[1][16]);
        $this->assertSame('+1 555 0100', $rows[1][23]);
        $this->assertSame('review.recipient@example.test', $rows[1][24]);
        $this->assertSame('Review Company', $rows[1][25]);
        $this->assertSame('REVIEW-SKU', $rows[1][39]);
        $this->assertSame('2', $rows[1][40]);
        $this->assertSame('G  良好', $rows[1][42]);
    }

    public function test_moreplus_export_fails_closed_when_address_line_1_exceeds_provider_limit(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007erp-0920-c40762');
        $order->address()->update(['address_line_1' => str_repeat('A', 36)]);
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();

        $this->actingAs($user)->get('/logistics/oversea/us-moreplus/export')->assertStatus(422);
    }

    public function test_wms_export_fails_closed_above_confirmed_ten_sku_limit(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-NY'], '007erp-0920-c40811');
        $productId = Product::query()->where('sku', 'REVIEW-SKU')->value('id');
        for ($i = 2; $i <= 11; $i++) {
            $order->items()->create([
                'product_id' => $productId, 'product_summary' => "Product {$i}", 'sku' => "SKU-{$i}", 'quantity' => 1,
                'unit_price' => '10.00', 'line_revenue' => '10.00',
            ]);
        }
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();

        $this->actingAs($user)->get('/logistics/oversea/us-ny/export')->assertStatus(422);
    }

    public function test_wms_and_canada_exports_fail_closed_when_recipient_phone_is_missing(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        foreach ([
            [$warehouses['US-NY'], '007erp-0920-c40812', 'US', '/logistics/oversea/us-ny/export'],
            [$warehouses['CA'], '007erp-0920-k26011302', 'CA', '/logistics/oversea/ca/export'],
        ] as [$warehouse, $erp, $country, $url]) {
            $order = $this->orderAt($warehouse, $erp, $country);
            $order->address()->update(['phone' => '']);
            $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
            $this->actingAs($user)->get($url)->assertStatus(422);
        }
    }

    public function test_moreplus_cancelled_batch_is_exception_while_other_ack_remains_pending_not_issued(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $bad = $this->orderAt($warehouses['US-LA'], '007erp-1110-c42149');
        $good = $this->orderAt($warehouses['US-LA'], '007erp-1110-c42150');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$bad->id}/approve")->assertRedirect();
        $this->actingAs($user)->post("/logistics/oversea/orders/{$good->id}/approve")->assertRedirect();

        $wh = ShipOutProductionDispatchState::MOREPLUS_PROVIDER_WAREHOUSE_ID;
        foreach ([$bad, $good] as $order) {
            DB::table('shipout_dispatch_attempts')->insert([
                'order_id' => $order->id,
                'order_no' => 'C'.$order->id.'-007',
                'provider_warehouse_id' => $wh,
                'payload_hash' => str_repeat('a', 64),
                'status' => 'acknowledged', 'provider_order_id' => 'OMS-ACK-'.$order->id,
                'result_code' => 'ASYNC_ACCEPTED_QUERY_REQUIRED',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $batchId = '00000000-0000-4000-8000-000000000124';
        DB::table('shipout_production_batches')->insert([
            'id' => $batchId, 'operator_user_id' => $user->id,
            'status' => 'completed_partial', 'dry_run' => false, 'total' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('shipout_production_batch_items')->insert([
            'batch_id' => $batchId, 'order_id' => $bad->id,
            'order_no' => $bad->erp_order_id, 'status' => 'provider_cancelled',
            'result_code' => 'PROVIDER_DUPLICATE_ORDER',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Http::preventStrayRequests();
        $this->actingAs($user)->get('/logistics/oversea/us-moreplus?view=exceptions')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rows.total', 1)
            ->where('rows.data.0.order_id', $bad->id)
            ->where('rows.data.0.shipout_production.status', 'oms_quarantined')
            ->where('rows.data.0.warehouse_dispatch.failure_code', 'PROVIDER_DUPLICATE_ORDER'));
        $this->actingAs($user)->get('/logistics/oversea/us-moreplus?view=issued')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('rows.total', 0));
        $this->actingAs($user)->get('/logistics/oversea/us-moreplus?view=pending')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rows.total', 1)
            ->where('rows.data.0.order_id', $good->id)
            ->where('rows.data.0.shipout_production.status', 'oms_ack_pending'));
        $this->assertDatabaseCount('shipout_dispatch_attempts', 2);
        $this->assertDatabaseHas('shipments', ['order_id' => $bad->id, 'shipment_status' => 'pending', 'tracking_number' => null]);
        $this->assertDatabaseHas('shipments', ['order_id' => $good->id, 'shipment_status' => 'pending', 'tracking_number' => null]);
        Http::assertNothingSent();
    }

    public function test_moreplus_preflight_block_without_post_is_only_in_exception_list(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007erp-1110-c42151');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $batchId = '00000000-0000-4000-8000-000000000125';
        DB::table('shipout_production_batches')->insert([
            'id' => $batchId, 'operator_user_id' => $user->id,
            'status' => 'completed_partial', 'dry_run' => false, 'total' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('shipout_production_batch_items')->insert([
            'batch_id' => $batchId, 'order_id' => $order->id,
            'order_no' => $order->erp_order_id, 'status' => 'skipped',
            'result_code' => 'SHIPOUT_SKU_NOT_VERIFIED',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Http::preventStrayRequests();
        $this->actingAs($user)->get('/logistics/oversea/us-moreplus?view=exceptions')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rows.total', 1)
            ->where('rows.data.0.order_id', $order->id)
            ->where('rows.data.0.shipout_production.status', 'oms_quarantined'));
        $this->actingAs($user)->get('/logistics/oversea/us-moreplus?view=pending')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('rows.total', 0));
        $this->actingAs($user)->get('/logistics/oversea/us-moreplus?view=pending_dispatch')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('rows.total', 0));
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'shipment_status' => 'pending', 'tracking_number' => null,
        ]);
        Http::assertNothingSent();
    }

    public function test_moreplus_outbound_roundtrip_return_previews_real_tracking_without_apply(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007erp-0920-c40762');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $file = $this->providerXlsx('moreplus-roundtrip-return.xlsx', 'Template',
            ['Order NO', 'SKU', 'SKU Qty', 'Tag', 'Request Ship Date', 'Recipient Name', 'Recipient Company', 'Recipient Address Line 1', 'Recipient Address Line 2', 'Recipient State', 'Recipient City', 'Recipient ZipCode', 'Recipient Country', 'Recipient Phone', 'Recipient Email', 'Request Shipping Service', 'Warehouse Customized Service Name', 'Signature Option', 'Tracking Number', 'Shipping Carrier', 'Insurance', 'Cod', 'Notes'],
            [['C40762-007', 'REVIEW-SKU', 1, '', '', 'Review Recipient', '', '1 Main St', '', 'CA', 'City', '90001', 'US', '+1 555 0100', 'review@example.test', 'Selected by Warehouse', '', '', '9434650105794034942869', '', '', '', '']]);

        $this->actingAs($user)->post('/logistics/oversea/us-moreplus/tracking-import/preview', ['file' => $file])
            ->assertRedirect('/logistics/oversea/us-moreplus?view=pending');
        $stored = session('logistics.oversea_tracking_return.us-moreplus');
        $this->assertSame(1, $stored['apply_count']);
        $this->assertSame(0, $stored['blocked_count']);
        $this->assertSame('9434650105794034942869', $stored['rows'][0]['tracking_number']);
        $this->assertSame('Selected by Warehouse', $stored['rows'][0]['provider_service']);
        $this->assertSame('9434650105794034942869', $stored['rows'][0]['provider_evidence']['Tracking Number']);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'shipment_status' => 'pending',
            'tracking_number' => null,
        ]);
    }

    public function test_moreplus_real_xlsx_return_matches_provider_reference_and_is_idempotent_without_shipped_time(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007erp-0920-c40761');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $file = $this->providerXlsx('moreplus-return.xlsx', '0',
            ['Outbound Order#', 'Order#', 'Shipped Time', 'Master Tracking#', 'Tracking#', 'Requested via', 'Ship Via', 'Carrier'],
            [['MP-1', 'C40761-007', '', '92144904245461009999', '92144904245461009999', 'Selected by warehouse', 'USPS Ground Advantage', 'usps']]);

        $this->actingAs($user)->post('/logistics/oversea/us-moreplus/tracking-import/preview', ['file' => $file])
            ->assertRedirect('/logistics/oversea/us-moreplus?view=pending');
        $stored = session('logistics.oversea_tracking_return.us-moreplus');
        $this->assertSame(1, $stored['apply_count']);
        $this->assertSame(0, $stored['blocked_count']);
        $this->assertSame('USPS Ground Advantage', $stored['rows'][0]['provider_service']);
        $this->assertSame('USPS', $stored['rows'][0]['carrier']);

        $this->actingAs($user)->post('/logistics/oversea/us-moreplus/tracking-import/apply', ['token' => $stored['token']])
            ->assertRedirect('/logistics/oversea/us-moreplus?view=shipped');
        $shipment = $order->fresh('shipment')->shipment;
        $this->assertSame('shipped', $shipment->shipment_status);
        $this->assertSame('92144904245461009999', $shipment->tracking_number);
        $this->assertNotNull($shipment->shipped_at);

        $fileAgain = $this->providerXlsx('moreplus-return.xlsx', '0',
            ['Outbound Order#', 'Order#', 'Shipped Time', 'Master Tracking#', 'Tracking#', 'Requested via', 'Ship Via', 'Carrier'],
            [['MP-1', 'C40761-007', '', '92144904245461009999', '92144904245461009999', 'Selected by warehouse', 'USPS Ground Advantage', 'usps']]);
        $this->actingAs($user)->post('/logistics/oversea/us-moreplus/tracking-import/preview', ['file' => $fileAgain])->assertRedirect();
        $again = session('logistics.oversea_tracking_return.us-moreplus');
        $this->assertSame(0, $again['apply_count']);
        $this->assertSame(1, $again['noop_count']);
        $this->assertSame(0, $again['blocked_count']);
    }

    public function test_moreplus_real_xlsx_return_blocks_master_tracking_conflict(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007erp-0920-c40755');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $file = $this->providerXlsx('moreplus-return.xlsx', '0',
            ['Outbound Order#', 'Order#', 'Shipped Time', 'Master Tracking#', 'Tracking#', 'Ship Via', 'Carrier'],
            [['MP-2', 'C40755-007', '', '92144904245461009344', '92144904245461009343', 'USPS Ground Advantage', 'usps']]);

        $this->actingAs($user)->post('/logistics/oversea/us-moreplus/tracking-import/preview', ['file' => $file])->assertRedirect();
        $stored = session('logistics.oversea_tracking_return.us-moreplus');
        $this->assertSame(0, $stored['apply_count']);
        $this->assertSame(1, $stored['blocked_count']);
        $this->assertStringContainsString('Master Tracking# 与 Tracking# 不一致', $stored['rows'][0]['message']);
    }

    public function test_usny_real_xlsx_return_uses_reference_tracking_and_ignores_provider_pending_status(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-NY'], '007erp-0920-c40801');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $file = $this->providerXlsx('usny-return.xlsx', 'Sheet1',
            ['Outbound Order No./出库单号', 'Reference order No./参考单号', 'Status/状态', 'Shipping service/物流渠道', 'Tracking No./物流跟踪号', 'Tracking Status/物流状态', 'OutboundTime/出库时间', 'Carrier/承运商', 'Email/邮箱'],
            [['OB-40801', 'C40801-007', '仓库处理中', 'USPS-NJ(USPS-NJ)', '9214490416421805721875', 'Pending', '', 'usps', 'provider.echo@example.test']]);

        $this->actingAs($user)->post('/logistics/oversea/us-ny/tracking-import/preview', ['file' => $file])->assertRedirect();
        $stored = session('logistics.oversea_tracking_return.us-ny');
        $this->assertSame(1, $stored['apply_count']);
        $this->assertSame(0, $stored['blocked_count']);
        $this->assertSame('仓库处理中', $stored['rows'][0]['provider_status']);
        $this->assertSame('Pending', $stored['rows'][0]['tracking_status']);
        $this->assertSame('USPS-NJ(USPS-NJ)', $stored['rows'][0]['provider_service']);
        $this->assertSame('provider.echo@example.test', $stored['rows'][0]['provider_evidence']['Email/邮箱']);
    }

    public function test_wms_real_full_headers_keep_primary_tracking_and_outbound_reference(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-NY'], '007erp-0920-c40801');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $file = $this->providerXlsx('usny-real-full-header-return.xlsx', '出库单',
            [
                'Outbound Order No./出库单号',
                'Reference order No./参考单号',
                'Tracking No./物流跟踪号',
                'Return Tracking No./回邮单号',
                'Related Outbound Order No./关联出库单号',
                "Package 1\nTracking No./子物流跟踪号",
                'Status/状态',
                'Shipping service/物流渠道',
                'OutboundTime/出库时间',
                'Carrier/承运商',
            ],
            [[
                'OB-PRIMARY',
                'C40801-007',
                '9214490416421805721875',
                'RETURN-TRACKING-DECOY',
                'RELATED-OUTBOUND-DECOY',
                'PACKAGE-TRACKING-DECOY',
                '仓库处理中',
                'USPS-NJ(USPS-NJ)',
                '',
                'usps',
            ]]);

        $this->actingAs($user)->post('/logistics/oversea/us-ny/tracking-import/preview', ['file' => $file])->assertRedirect();
        $stored = session('logistics.oversea_tracking_return.us-ny');
        $this->assertSame(0, $stored['apply_count']);
        $this->assertSame(1, $stored['blocked_count']);
        $this->assertSame('9214490416421805721875', $stored['rows'][0]['tracking_number']);
        $this->assertSame('OB-PRIMARY', $stored['rows'][0]['provider_order_reference']);
        $this->assertStringContainsString('主 Tracking No. 与 Package Tracking No. 不一致', $stored['rows'][0]['message']);
    }

    public function test_wms_return_blocks_explicit_cancellation_even_with_valid_tracking(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-NY'], '007erp-0920-c40821');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $file = $this->providerXlsx('usny-cancelled-return.xlsx', 'Sheet1',
            ['Reference order No./参考单号', 'Status/状态', 'Tracking No./物流跟踪号', 'Cancel time/取消时间', 'Carrier/承运商'],
            [['C40821-007', 'Cancelled', '9214490416421805721801', '2026-09-23 08:00:00', 'usps']]);

        $this->actingAs($user)->post('/logistics/oversea/us-ny/tracking-import/preview', ['file' => $file])->assertRedirect();
        $stored = session('logistics.oversea_tracking_return.us-ny');
        $this->assertSame(0, $stored['apply_count']);
        $this->assertSame(1, $stored['blocked_count']);
        $this->assertStringContainsString('Provider 回传显示订单已取消', $stored['rows'][0]['message']);
    }

    public function test_wms_return_blocks_clear_known_provider_warehouse_mismatch(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-NY'], '007erp-0920-c40822');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $file = $this->providerXlsx('usny-wrong-warehouse-return.xlsx', 'Sheet1',
            ['Reference order No./参考单号', 'Warehouse/仓库', 'Tracking No./物流跟踪号', 'Carrier/承运商'],
            [['C40822-007', '德国仓(Hannover)', '9214490416421805721802', 'usps']]);

        $this->actingAs($user)->post('/logistics/oversea/us-ny/tracking-import/preview', ['file' => $file])->assertRedirect();
        $stored = session('logistics.oversea_tracking_return.us-ny');
        $this->assertSame(0, $stored['apply_count']);
        $this->assertSame(1, $stored['blocked_count']);
        $this->assertStringContainsString('Provider 回传仓库与当前 ERP 仓库明确不一致', $stored['rows'][0]['message']);
    }

    public function test_moreplus_return_blocks_explicit_cancelled_status(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007erp-0920-c40771');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $file = $this->providerXlsx('moreplus-cancelled-return.xlsx', '0',
            ['Order#', 'Master Tracking#', 'Tracking#', 'Status', 'Reason for Cancellation', 'Carrier'],
            [['C40771-007', '92144904245461009771', '92144904245461009771', 'Cancelled', 'Customer requested cancellation', 'usps']]);

        $this->actingAs($user)->post('/logistics/oversea/us-moreplus/tracking-import/preview', ['file' => $file])->assertRedirect();
        $stored = session('logistics.oversea_tracking_return.us-moreplus');
        $this->assertSame(0, $stored['apply_count']);
        $this->assertSame(1, $stored['blocked_count']);
        $this->assertSame('Customer requested cancellation', $stored['rows'][0]['cancellation_reason']);
        $this->assertStringContainsString('Provider 回传显示订单已取消', $stored['rows'][0]['message']);
    }

    public function test_canada_real_seven_column_return_previews_tracking_by_customer_reference(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['CA'], '007erp-0920-k26011301', 'CA');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $file = $this->providerXlsx('ca-real-return.xlsx', 'SHEET',
            ['订单号', '包裹序号', 'com_lab_3015', '长(cm)', '宽(cm)', '高(cm)', '重量(kg)'],
            [['KOL26011301', '1', '1030896460840281', '0', '0', '0', '1.2']]);

        $this->actingAs($user)->post('/logistics/oversea/ca/tracking-import/preview', ['file' => $file])
            ->assertRedirect('/logistics/oversea/ca?view=pending');
        $stored = session('logistics.oversea_tracking_return.ca');
        $this->assertSame(1, $stored['apply_count']);
        $this->assertSame(0, $stored['noop_count']);
        $this->assertSame(0, $stored['blocked_count']);
        $this->assertSame('KOL26011301', $stored['rows'][0]['order_number']);
        $this->assertSame('1030896460840281', $stored['rows'][0]['tracking_number']);
        $this->assertSame('1', $stored['rows'][0]['provider_evidence']['包裹序号']);
        $this->assertCount(7, $stored['rows'][0]['provider_evidence']);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'shipment_status' => 'pending',
            'tracking_number' => null,
        ]);
    }

    public function test_canada_real_provider_sample_shape_blocks_when_customer_reference_is_unknown(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $file = $this->providerXlsx('ca-real-unknown-reference.xlsx', 'SHEET',
            ['订单号', '包裹序号', 'com_lab_3015', '长(cm)', '宽(cm)', '高(cm)', '重量(kg)'],
            [['99632-260915-0002', '1', '1030896460840281', '0', '0', '0', '1.2']]);

        $this->actingAs($user)->post('/logistics/oversea/ca/tracking-import/preview', ['file' => $file])
            ->assertRedirect('/logistics/oversea/ca?view=pending');
        $stored = session('logistics.oversea_tracking_return.ca');
        $this->assertSame(0, $stored['apply_count']);
        $this->assertSame(1, $stored['blocked_count']);
        $this->assertSame('99632-260915-0002', $stored['rows'][0]['order_number']);
        $this->assertSame('1030896460840281', $stored['rows'][0]['tracking_number']);
        $this->assertStringContainsString('ERP 找不到该订单号', $stored['rows'][0]['message']);
    }

    public function test_canada_order_only_export_file_is_fail_closed_as_tracking_return(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['CA'], '007erp-0920-k26011301', 'CA');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $file = $this->providerXlsx('ca-order-only-export.xlsx', 'SHEET',
            ['订单号'],
            [['KOL26011301']]);

        $this->actingAs($user)->post('/logistics/oversea/ca/tracking-import/preview', ['file' => $file])
            ->assertStatus(422);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'shipment_status' => 'pending',
            'tracking_number' => null,
        ]);
    }

    public function test_three_sm_xlsx_return_is_fail_closed_until_real_template_exists(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-SM'], '007erp-0920-c40802');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $file = $this->providerXlsx('3sm-return.xlsx', 'Sheet1', ['Order', 'Tracking'], [['C40802-007', '9400111']]);

        $this->actingAs($user)->post('/logistics/oversea/us-3sm/tracking-import/preview', ['file' => $file])
            ->assertStatus(422);
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'shipment_status' => 'pending', 'tracking_number' => null]);
    }

    public function test_tracking_return_preview_is_read_only_and_does_not_require_provider_shipped_time(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007-OVERSEA-RETURN-PREVIEW');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $csv = "订单号,Carrier,Tracking No.,发货时间(ISO8601含时区)\n007-OVERSEA-RETURN-PREVIEW,USPS,9400111899223856928499,2026-09-15 10:30:00\n";

        $this->actingAs($user)->post('/logistics/oversea/us-moreplus/tracking-import/preview', [
            'file' => UploadedFile::fake()->createWithContent('return.csv', $csv),
        ])->assertRedirect('/logistics/oversea/us-moreplus?view=pending');

        $this->actingAs($user)->get('/logistics/oversea/us-moreplus?view=pending')->assertInertia(fn (Assert $page) => $page
            ->where('trackingImportPreview.apply_count', 1)
            ->where('trackingImportPreview.blocked_count', 0)
            ->where('trackingImportPreview.rows.0.status', 'apply'));
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'shipment_status' => 'pending', 'tracking_number' => null]);
    }

    public function test_tracking_return_apply_marks_shipped_with_exact_evidence_and_updates_review_progress(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007-OVERSEA-RETURN-APPLY');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $csv = "订单号,Carrier,Tracking No.,发货时间(ISO8601含时区)\n007-OVERSEA-RETURN-APPLY,USPS,9400111899223856928500,2026-09-15T10:30:00-07:00\n";

        $this->actingAs($user)->post('/logistics/oversea/us-moreplus/tracking-import/preview', [
            'file' => UploadedFile::fake()->createWithContent('return.csv', $csv),
        ])->assertRedirect('/logistics/oversea/us-moreplus?view=pending');
        $stored = session('logistics.oversea_tracking_return.us-moreplus');
        $this->assertIsArray($stored);
        $this->assertSame(1, $stored['apply_count']);
        $this->assertSame(0, $stored['blocked_count']);

        $this->actingAs($user)->post('/logistics/oversea/us-moreplus/tracking-import/apply', [
            'token' => $stored['token'],
        ])->assertRedirect('/logistics/oversea/us-moreplus?view=shipped');

        $shipment = $order->fresh('shipment')->shipment;
        $this->assertSame('shipped', $shipment->shipment_status);
        $this->assertSame('USPS', $shipment->carrier);
        $this->assertSame('9400111899223856928500', $shipment->tracking_number);
        $this->assertSame('2026-09-15T17:30:00+00:00', $shipment->shipped_at->utc()->toIso8601String());
        $this->assertDatabaseHas('oversea_warehouse_reviews', ['order_id' => $order->id, 'status' => 'approved', 'progress' => 'shipped']);
        $this->assertDatabaseHas('manual_syncs', ['order_id' => $order->id, 'website_sync_status' => 'pending']);
    }

    public function test_tracking_return_apply_stages_woo_tracking_for_sync_module(): void
    {
        config([
            'services.woocommerce.url' => 'https://shop.test',
            'services.woocommerce.consumer_key' => 'ck_test',
            'services.woocommerce.consumer_secret' => 'cs_test',
            'services.woocommerce.connection_key' => 'shop-a',
        ]);
        $gateway = new TrackingReturnFakeWooGateway;
        $this->app->instance(WooCommerceTrackingGateway::class, $gateway);
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();

        $root = Order::query()->create([
            'internal_order_number' => 'ROOT-RETURN-WOO',
            'erp_order_id' => '007-OVERSEA-RETURN-WOO',
            'source_type' => 'woocommerce',
            'order_kind' => 'customer',
            'external_order_number' => 'WC-99001',
            'order_date' => '2026-09-15',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
        $root->externalReferences()->create([
            'provider' => 'woocommerce', 'connection_key' => 'shop-a', 'reference' => '99001',
        ]);
        $child = Order::query()->create([
            'fulfillment_parent_id' => $root->id,
            'fulfillment_suffix' => 'B',
            'internal_order_number' => 'CHILD-RETURN-WOO-B',
            'erp_order_id' => '007-OVERSEA-RETURN-WOOB',
            'source_type' => 'manual_other',
            'order_kind' => 'fulfillment_derived',
            'order_date' => '2026-09-15',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
        $child->items()->create([
            'woocommerce_line_item_id' => 990011,
            'product_summary' => 'Woo package B',
            'sku' => 'REVIEW-SKU',
            'quantity' => 1,
            'unit_price' => '10.00',
            'line_revenue' => '10.00',
        ]);
        $child->shipment()->create([
            'shipment_status' => 'pending',
            'warehouse_id' => $warehouses['US-LA']->id,
            'warehouse_selection_source' => 'manual',
        ]);
        $this->actingAs($user)->post("/logistics/oversea/orders/{$child->id}/approve")->assertRedirect();
        $csv = "订单号,Carrier,Tracking No.,发货时间(ISO8601含时区)\n007-OVERSEA-RETURN-WOOB,USPS,9400111899223856928511,2026-09-15T10:30:00-07:00\n";

        $this->actingAs($user)->post('/logistics/oversea/us-moreplus/tracking-import/preview', [
            'file' => UploadedFile::fake()->createWithContent('woo-return.csv', $csv),
        ])->assertRedirect('/logistics/oversea/us-moreplus?view=pending');
        $stored = session('logistics.oversea_tracking_return.us-moreplus');
        $this->actingAs($user)->post('/logistics/oversea/us-moreplus/tracking-import/apply', [
            'token' => $stored['token'],
        ])->assertRedirect('/logistics/oversea/us-moreplus?view=shipped');

        // The operator HTTP request must never write WooCommerce.
        $this->assertSame(0, $gateway->trackingCalls);
        $this->assertSame(0, $gateway->orderStatusCalls);
        $this->assertDatabaseHas('manual_syncs', ['order_id' => $child->id, 'website_sync_status' => 'pending']);
        $this->assertDatabaseHas('sync_outbox', [
            'operation' => 'woocommerce.tracking_outbound',
            'aggregate_id' => (string) $child->fresh()->shipment->id,
            'status' => 'pending',
            'attempts' => 0,
        ]);
        // The independent Sync OS runner performs the remote write later.
        $run = app(SyncOrchestrationService::class)->retryPending(null, 50, 0);
        $this->assertSame(1, $run->succeeded_items);
        $this->assertSame(1, $gateway->trackingCalls);
        $this->assertSame(99001, $gateway->providerOrderId);
        $this->assertSame('9400111899223856928511', $gateway->trackingNumber);
        $this->assertSame([['id' => 990011, 'quantity' => 1]], $gateway->lineItems);
        $this->assertDatabaseHas('manual_syncs', ['order_id' => $child->id, 'website_sync_status' => 'completed']);
        $this->assertDatabaseHas('sync_outbox', ['operation' => 'woocommerce.tracking_outbound', 'status' => 'completed']);
    }

    public function test_us_sm_manual_confirm_records_tracking_for_sync_module_without_network(): void
    {
        config([
            'services.woocommerce.url' => 'https://shop.test',
            'services.woocommerce.consumer_key' => 'ck_test',
            'services.woocommerce.consumer_secret' => 'cs_test',
            'services.woocommerce.connection_key' => 'shop-a',
        ]);
        $gateway = new TrackingReturnFakeWooGateway;
        $this->app->instance(WooCommerceTrackingGateway::class, $gateway);
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();

        $root = Order::query()->create([
            'internal_order_number' => 'ROOT-USSM-MANUAL',
            'erp_order_id' => '007-OVERSEA-USSM-MANUAL',
            'source_type' => 'woocommerce',
            'order_kind' => 'customer',
            'external_order_number' => 'WC-99101',
            'order_date' => '2026-09-24',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
        $root->externalReferences()->create([
            'provider' => 'woocommerce', 'connection_key' => 'shop-a', 'reference' => '99101',
        ]);

        $child = Order::query()->create([
            'fulfillment_parent_id' => $root->id,
            'fulfillment_suffix' => 'B',
            'internal_order_number' => 'CHILD-USSM-MANUAL-B',
            'erp_order_id' => '007-OVERSEA-USSM-MANUALB',
            'source_type' => 'manual_other',
            'order_kind' => 'fulfillment_derived',
            'order_date' => '2026-09-24',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
        $child->items()->create([
            'woocommerce_line_item_id' => 991011,
            'product_summary' => 'US-SM package B',
            'sku' => 'REVIEW-SKU',
            'quantity' => 1,
            'unit_price' => '10.00',
            'line_revenue' => '10.00',
        ]);
        $child->shipment()->create([
            'shipment_status' => 'pending',
            'warehouse_id' => $warehouses['US-SM']->id,
            'warehouse_selection_source' => 'manual',
        ]);

        $this->actingAs($user)->post("/logistics/oversea/orders/{$child->id}/approve")->assertRedirect();
        // An older OMS attempt is historical evidence, not a gate for ERP shipment.
        XlwmsDispatchAttempt::query()->create([
            'order_id' => $child->id, 'warehouse_id' => $warehouses['US-SM']->id,
            'third_order_no' => 'BYL-ERP-'.$child->id, 'state' => 'draft_cancelled_confirmed',
            'request_fingerprint' => str_repeat('c', 64),
            'oms_order_no' => 'OBS-HISTORICAL-CANCELLED', 'oms_status_code' => '4',
        ]);
        $this->actingAs($user)->post("/logistics/oversea/us-3sm/orders/{$child->id}/manual-ship", [
            'tracking_number' => '9400111899223856928599',
        ])->assertRedirect('/logistics/oversea/us-3sm?view=shipped');

        $shipment = $child->fresh('shipment')->shipment;
        $this->assertSame('shipped', $shipment->shipment_status);
        $this->assertSame('USPS', $shipment->carrier);
        $this->assertSame('9400111899223856928599', $shipment->tracking_number);
        $this->assertNotNull($shipment->shipped_at);
        $this->assertSame($user->id, $shipment->handled_by);
        $this->assertDatabaseHas('oversea_warehouse_reviews', [
            'order_id' => $child->id,
            'status' => 'approved',
            'progress' => 'shipped',
        ]);

        $this->assertSame(0, $gateway->trackingCalls);
        $this->assertSame(0, $gateway->orderStatusCalls);
        $this->assertDatabaseHas('manual_syncs', ['order_id' => $child->id, 'website_sync_status' => 'pending']);
        $this->assertDatabaseHas('sync_outbox', [
            'operation' => 'woocommerce.tracking_outbound',
            'aggregate_id' => (string) $shipment->id,
            'status' => 'pending', 'attempts' => 0,
        ]);
        // Only Sync OS's 30-minute runner is allowed to contact Woo.
        $run = app(SyncOrchestrationService::class)->retryPending(null, 50, 0);
        $this->assertSame(1, $run->succeeded_items);
        $this->assertSame(1, $gateway->trackingCalls);
        $this->assertSame(99101, $gateway->providerOrderId);
        $this->assertSame('9400111899223856928599', $gateway->trackingNumber);
        $this->assertSame([['id' => 991011, 'quantity' => 1]], $gateway->lineItems);
        $this->assertDatabaseHas('manual_syncs', ['order_id' => $child->id, 'website_sync_status' => 'completed']);
        $this->assertDatabaseHas('sync_outbox', ['operation' => 'woocommerce.tracking_outbound', 'status' => 'completed']);

        // Replaying the verified Tracking intent must never add a duplicate package.
        $again = app(WooCommerceTrackingOutboundService::class)
            ->pushOne($child->fresh(), $user, syncOrderStatus: false);
        $this->assertSame('completed', $again['status']);
        $this->assertSame(1, $gateway->trackingCalls);
        $this->actingAs($user)->post("/logistics/oversea/us-3sm/orders/{$child->id}/manual-ship", [
            'tracking_number' => '9400111899223856928599',
        ])->assertStatus(409);
    }

    public function test_us_sm_manual_confirm_is_fail_closed_for_other_warehouses(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007-OVERSEA-NOT-USSM');

        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $this->actingAs($user)->post("/logistics/oversea/us-3sm/orders/{$order->id}/manual-ship", [
            'tracking_number' => '9400111899223856928501',
        ])->assertStatus(422);

        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'shipment_status' => 'pending',
            'tracking_number' => null,
        ]);
    }

    public function test_super_admin_can_review_without_frontline_fulfillment_edit_access(): void
    {
        $role = Role::query()->create(['key' => 'super_admin', 'label' => 'Super Admin']);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        $this->assertTrue($user->hasPermission('logistics.operate'));
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007-OVERSEA-OWNER-REVIEW');

        $this->actingAs($user)->get('/logistics/oversea?view=review')
            ->assertInertia(fn (Assert $page) => $page
                ->where('warehouseKey', 'all')
                ->where('canReview', true)
                ->where('canOperate', false)
            );

        $this->actingAs($user)
            ->patch("/logistics/oversea/orders/{$order->id}/draft", ['tracking_number' => 'OWNER-MUST-NOT-EDIT'])
            ->assertForbidden();

        $this->actingAs($user)
            ->patch("/logistics/oversea/orders/{$order->id}/draft", ['note' => 'Owner review note'])
            ->assertRedirect();

        $this->actingAs($user)
            ->post("/logistics/oversea/orders/{$order->id}/approve", ['note' => 'Owner review note'])
            ->assertRedirect();

        $this->assertDatabaseHas('oversea_warehouse_reviews', [
            'order_id' => $order->id,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'tracking_number' => null,
        ]);
    }

    public function test_all_warehouse_review_allows_operations_and_uses_canonical_pagination(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $this->orderAt($warehouses['US-LA'], '007-OVERSEA-ALL-REVIEW');

        $this->actingAs($user)->get('/logistics/oversea?view=review')
            ->assertInertia(fn (Assert $page) => $page
                ->where('warehouseKey', 'all')
                ->where('canReview', true)
                ->where('canOperate', false)
                ->where('rows.per_page', 50)
                ->where('rows.current_page', 1)
            );

        $this->actingAs($user)->get('/logistics/oversea?view=review&per_page=20')
            ->assertInertia(fn (Assert $page) => $page->where('rows.per_page', 20));

        $this->actingAs($user)->get('/logistics/oversea?view=review&per_page=999')
            ->assertInertia(fn (Assert $page) => $page->where('rows.per_page', 50));
    }

    public function test_view_only_user_sees_page_but_not_operate_flag(): void
    {
        $user = $this->userWith(['logistics.view']);
        $warehouses = $this->warehouses();
        $this->orderAt($warehouses['US-NY'], '007-OVERSEA-VIEW');
        $this->actingAs($user)->get('/logistics/oversea/us-ny')->assertInertia(fn (Assert $page) => $page->where('canOperate', false));
    }

    /** @param list<string> $headers @param list<list<mixed>> $rows */
    private function providerXlsx(string $filename, string $sheet, array $headers, array $rows): UploadedFile
    {
        $bytes = app(ProviderXlsxWriter::class)->write($sheet, $headers, $rows);

        return UploadedFile::fake()->createWithContent($filename, $bytes);
    }

    private function xlsxXml(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'oversea-raw-xml-');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);
        $this->assertIsString($xml);

        return $xml;
    }

    /** @return list<list<string>> */
    private function xlsxRows(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'oversea-export-test-');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);
        $this->assertIsString($xml);

        $doc = new DOMDocument;
        $this->assertTrue($doc->loadXML($xml));
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rows = [];
        foreach ($xp->query('//m:sheetData/m:row') as $rowNode) {
            $row = [];
            foreach ($xp->query('./m:c', $rowNode) as $cell) {
                preg_match('/^([A-Z]+)/', $cell->getAttribute('r'), $match);
                $index = $this->columnIndex($match[1] ?? 'A');
                $value = $cell->getAttribute('t') === 'inlineStr'
                    ? ($xp->query('./m:is/m:t', $cell)->item(0)?->textContent ?? '')
                    : ($xp->query('./m:v', $cell)->item(0)?->textContent ?? '');
                $row[$index] = $value;
            }
            if ($row !== []) {
                ksort($row);
                $max = max(array_keys($row));
                $rows[] = array_map(fn ($i) => (string) ($row[$i] ?? ''), range(0, $max));
            }
        }

        return $rows;
    }

    private function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index - 1;
    }

    private function storeSignedShipOutAttempt(Order $order, string $status = 'acknowledged', ?string $providerId = 'SYNTHETIC-OMS-ACK'): void
    {
        DB::table('shipout_dispatch_attempts')->insert([
            'order_id' => $order->id,
            'order_no' => 'C-SYNTH-'.$order->id,
            'provider_warehouse_id' => '1265864414750314497',
            'payload_hash' => str_repeat('a', 64),
            'status' => $status,
            'provider_order_id' => $providerId,
            'result_code' => 'ASYNC_ACCEPTED_QUERY_REQUIRED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_shipout_oms_ack_remains_pending_until_two_signed_gets_without_marking_shipped(): void
    {
        $user = $this->userWith(['logistics.view']);
        $w = $this->warehouses();
        $order = $this->orderAt($w['US-LA'], '007-1030-c41941');
        $other = $this->orderAt($w['US-LA'], '007-1030-c41948');
        $this->actingAs($this->userWith(['logistics.view', 'logistics.operate']))
            ->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $this->post("/logistics/oversea/orders/{$other->id}/approve")->assertRedirect();
        $this->storeSignedShipOutAttempt($order);
        Http::preventStrayRequests();
        Http::fake();

        $this->actingAs($user)->get('/logistics/oversea/us-moreplus?view=pending')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('viewCounts.pending', 2)
            ->where('viewCounts.issued', 0)
            ->where('rows.total', 2));

        $this->get('/logistics/oversea/us-moreplus?view=issued')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('rows.total', 0));
        $this->get('/logistics/oversea/us-moreplus?view=pending')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('rows.data', 2)
            ->where('viewCounts.issued', 0));

        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'shipment_status' => 'pending',
            'tracking_number' => null, 'shipped_at' => null,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id, 'order_status' => 'pending_shipment',
        ]);
        $this->assertDatabaseHas('shipout_dispatch_attempts', [
            'order_id' => $order->id, 'status' => 'acknowledged',
            'provider_order_id' => 'SYNTHETIC-OMS-ACK',
        ]);
        Http::assertNothingSent();
    }

    public function test_r13_official_status_four_is_issued_tracking_pending_not_red_exception_or_erp_shipped(): void
    {
        $actor = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007-R13-OMS-STATUS4');
        $this->actingAs($actor)->post("/logistics/oversea/orders/{$order->id}/approve")
            ->assertRedirect();
        $this->storeSignedShipOutAttempt($order);
        $observer = app(ShipOutProductionOmsReadbackService::class);
        $observer->recordResult((int) $order->id, [
            'order_id' => $order->id,
            'attempt_status' => 'acknowledged',
            'provider_order_id' => 'SYNTHETIC-OMS-ACK',
            'provider_status' => 4,
            'reconciliation' => 'ORDER_VISIBLE_MANUAL_REVIEW_ONLY',
            'shipped' => false,
            'tracking_apply' => false,
        ]);
        Http::preventStrayRequests();
        $this->actingAs($actor)->get('/logistics/oversea/us-moreplus?view=exceptions')
            ->assertInertia(fn (Assert $page) => $page->where('rows.total', 0));
        $this->get('/logistics/oversea/us-moreplus?view=issued')
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.total', 1)
                ->where('rows.data.0.order_id', $order->id)
                ->where('rows.data.0.shipout_production.status', 'oms_shipped_pending_tracking')
                ->where('rows.data.0.shipment_status', 'pending')
                ->where('rows.data.0.tracking_number', null));
        $this->get('/logistics/oversea/us-moreplus?view=shipped')
            ->assertInertia(fn (Assert $page) => $page->where('rows.total', 0));
        $this->assertDatabaseCount('shipout_dispatch_attempts', 1);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'shipment_status' => 'pending', 'tracking_number' => null,
        ]);
        Http::assertNothingSent();
    }

    public function test_r12_two_oms_readbacks_enter_issued_and_later_cancel_moves_to_exception_without_shipment_write(): void
    {
        $actor = $this->userWith(['logistics.view', 'logistics.operate']);
        $w = $this->warehouses();
        $order = $this->orderAt($w['US-LA'], '007-R12-OMS-TRANSITIONS');
        $this->actingAs($actor)->post("/logistics/oversea/orders/{$order->id}/approve")
            ->assertRedirect();
        $this->storeSignedShipOutAttempt($order);
        $observer = app(ShipOutProductionOmsReadbackService::class);
        $provider = [
            'order_id' => $order->id, 'attempt_status' => 'acknowledged',
            'provider_order_id' => 'SYNTHETIC-OMS-ACK',
            'provider_status' => 3,
            'reconciliation' => 'ORDER_VISIBLE_MANUAL_REVIEW_ONLY',
            'shipped' => false, 'tracking_apply' => false,
        ];
        Http::preventStrayRequests();
        $observer->recordResult($order->id, $provider);
        $this->get('/logistics/oversea/us-moreplus?view=issued')
            ->assertInertia(fn (Assert $page) => $page->where('rows.total', 0));
        DB::table('shipout_oms_readbacks')->where('order_id', $order->id)
            ->update(['checked_at' => now()->subMinutes(3)]);
        $observer->recordResult($order->id, $provider);
        $this->get('/logistics/oversea/us-moreplus?view=issued')
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.total', 1)
                ->where('rows.data.0.order_id', $order->id)
                ->where('rows.data.0.shipout_production.status', 'oms_confirmed')
                ->where('rows.data.0.tracking_number', null));
        $this->get('/logistics/oversea/us-moreplus?view=pending')
            ->assertInertia(fn (Assert $page) => $page->where('rows.total', 0));
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'shipment_status' => 'pending',
            'tracking_number' => null,
        ]);

        $cancelled = $provider;
        $cancelled['provider_status'] = 6;
        $cancelled['reconciliation'] = 'PROVIDER_CANCELLED_OR_ABORTED_MANUAL_REVIEW';
        $cancelled['provider_failure_code'] = 'PROVIDER_EZEESHIP_SHIPPING_ERROR';
        $observer->recordResult($order->id, $cancelled);
        $this->get('/logistics/oversea/us-moreplus?view=issued')
            ->assertInertia(fn (Assert $page) => $page->where('rows.total', 0));
        $this->get('/logistics/oversea/us-moreplus?view=exceptions')
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.total', 1)
                ->where('rows.data.0.shipout_production.status', 'oms_quarantined')
                ->where('rows.data.0.warehouse_dispatch.failure_code', 'PROVIDER_EZEESHIP_SHIPPING_ERROR'));
        $this->assertDatabaseCount('shipout_dispatch_attempts', 1);
        $this->assertDatabaseCount('shipout_oms_readbacks', 3);
        Http::assertNothingSent();
    }

    public function test_shipout_unknown_or_unconfirmed_attempt_appears_only_in_exception_workbench(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $w = $this->warehouses();
        $order = $this->orderAt($w['US-LA'], '007-OMS-UNKNOWN');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $this->storeSignedShipOutAttempt($order, 'uncertain', null);
        Http::preventStrayRequests();
        Http::fake();

        $this->get('/logistics/oversea/us-moreplus?view=pending')
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.total', 0)
                ->where('viewCounts.issued', 0)
                ->where('viewCounts.exceptions', 1));
        $this->get('/logistics/oversea/us-moreplus?view=exceptions')
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.total', 1)
                ->where('rows.data.0.shipout_production.attempted', true)
                ->where('rows.data.0.shipout_production.is_oms_created', false)
                ->where('rows.data.0.warehouse_dispatch.label', '提交结果待核对 · 禁止重发'));
        Http::assertNothingSent();
    }

    public function test_shipout_ack_without_provider_order_id_cannot_enter_oms_created(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $w = $this->warehouses();
        $order = $this->orderAt($w['US-LA'], '007-OMS-MISSING-ID');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $this->storeSignedShipOutAttempt($order, 'acknowledged', null);
        $this->get('/logistics/oversea/us-moreplus?view=dispatched')
            ->assertInertia(fn (Assert $page) => $page->where('rows.total', 0));
        $this->get('/logistics/oversea/us-moreplus?view=exceptions')
            ->assertInertia(fn (Assert $page) => $page->where('rows.total', 1));
    }

    public function test_shipout_oms_ack_does_not_affect_other_warehouses_or_shipout_fulfillment_state(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $w = $this->warehouses();
        $morePlus = $this->orderAt($w['US-LA'], '007-OMS-LA-ACK');
        $usNy = $this->orderAt($w['US-NY'], '007-OMS-NY-PENDING');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$morePlus->id}/approve")->assertRedirect();
        $this->post("/logistics/oversea/orders/{$usNy->id}/approve")->assertRedirect();
        $this->storeSignedShipOutAttempt($morePlus);

        $this->get('/logistics/oversea/us-ny?view=pending')
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.total', 1)
                ->where('rows.data.0.order_id', $usNy->id));
        $this->get('/logistics/oversea/us-ny?view=dispatched')
            ->assertInertia(fn (Assert $page) => $page->where('rows.total', 0));
        $this->get('/logistics/oversea?view=issued')
            ->assertInertia(fn (Assert $page) => $page->where('rows.total', 0));
        $this->get('/logistics/oversea?view=pending')
            ->assertInertia(fn (Assert $page) => $page->where('rows.total', 2));
        $this->assertDatabaseHas('shipments', [
            'order_id' => $morePlus->id, 'shipment_status' => 'pending',
            'tracking_number' => null,
        ]);
        $this->assertDatabaseMissing('sync_outbox', [
            'operation' => 'woocommerce.tracking_outbound',
        ]);
    }

    /** @return array<string,Warehouse> */
    private function warehouses(): array
    {
        $rows = [
            'US-NY' => ['us-ny', '美东纽约仓'],
            'US-LA' => ['us-la', '美西 MorePlus 仓'],
            'US-SM' => ['us-3sm', '美国3世迈仓'],
            'DE' => ['de', '德国天运仓'],
            'CA' => ['ca', '加拿大仓'],
        ];
        $result = [];
        foreach ($rows as $code => [$slug, $name]) {
            $result[$code] = Warehouse::query()->updateOrCreate(['code' => $code], [
                'slug' => $slug,
                'name' => $name,
                'type' => 'warehouse',
                'is_active' => true,
                'priority' => 10,
            ]);
        }
        Warehouse::query()->updateOrCreate(['code' => '007'], ['slug' => '007', 'name' => '007 本地仓库', 'type' => 'warehouse', 'is_active' => true, 'priority' => 99]);
        Warehouse::query()->updateOrCreate(['code' => 'FACTORY'], ['slug' => 'factory', 'name' => '中国工厂', 'type' => 'warehouse', 'is_active' => true, 'priority' => 98]);
        $product = Product::query()->firstOrCreate(['sku' => 'REVIEW-SKU'], ['name' => 'Review Product', 'product_type' => 'simple', 'is_active' => true]);
        foreach ($result as $warehouse) {
            InventorySyncBatch::query()->create(['warehouse_id' => $warehouse->id, 'source_type' => 'api', 'sync_mode' => 'full', 'status' => 'confirmed', 'confirmed_at' => now()]);
            WarehouseInventory::query()->updateOrCreate(['warehouse_id' => $warehouse->id, 'product_id' => $product->id], ['quantity_on_hand' => 100, 'quantity_reserved' => 0]);
        }

        return $result;
    }

    private function markAsPaidWooOrder(Order $order, string $externalOrderNumber): void
    {
        $order->update([
            'source_type' => 'woocommerce',
            'external_order_number' => $externalOrderNumber,
        ]);
        $order->financials()->create([
            'currency' => 'USD',
            'provider' => 'woocommerce',
            'provider_reference' => $externalOrderNumber,
            'provider_order_status' => 'processing',
            'provider_modified_at' => now(),
            'payment_method' => 'woocommerce_payments',
            'payment_transaction_id' => 'TEST-'.strtoupper(str_replace(['-', ' '], '', $externalOrderNumber)).'-'.uniqid(),
            'paid_at' => now(),
            'provider_order_total' => '20.00',
            'revenue_provenance' => 'provider_import',
            'completeness_status' => 'INCOMPLETE',
        ]);
    }

    private function orderAt(Warehouse $warehouse, string $erpOrderId, string $country = 'US'): Order
    {
        $order = Order::query()->create([
            'internal_order_number' => 'INT-'.uniqid(),
            'erp_order_id' => $erpOrderId,
            'source_type' => 'manual_other',
            'order_kind' => 'customer',
            'external_order_number' => 'SOURCE-'.uniqid(),
            'order_date' => '2026-09-10',
            'order_status' => 'pending_shipment',
        ]);
        $order->address()->create([
            'recipient_name' => 'Review Recipient', 'phone' => '+1 555 0100',
            'email' => 'review.recipient@example.test', 'company' => 'Review Company', 'country' => $country,
            'province_state' => 'CA', 'city' => 'City', 'address_line_1' => '1 Main St', 'postal_code' => '90001',
        ]);
        $order->items()->create([
            'product_id' => Product::query()->where('sku', 'REVIEW-SKU')->value('id'),
            'product_summary' => 'Review Product', 'sku' => 'REVIEW-SKU', 'quantity' => 2,
            'unit_price' => '10.00', 'line_revenue' => '20.00',
        ]);
        $order->shipment()->create([
            'shipment_status' => 'pending', 'warehouse_id' => $warehouse->id,
            'warehouse_selection_source' => 'manual',
        ]);

        return $order->fresh(['address', 'items', 'shipment.warehouse']);
    }

    private function userWith(array $permissions): User
    {
        $role = Role::query()->create([
            'key' => 'oversea-test-'.uniqid(),
            'label' => 'Oversea Test',
        ]);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(
                ['key' => $permission],
                ['label' => $permission],
            );
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    private function operationsUser(string $email): User
    {
        $role = Role::query()->firstOrCreate([
            'key' => 'role_preset_operations',
        ], ['label' => '运营']);
        $user = User::factory()->create(['email' => $email, 'is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    public function test_us_sm_single_manual_download_is_repeatable_with_unique_refs_and_no_oms_write(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $w = $this->warehouses();
        $order = $this->orderAt($w['US-SM'], '007erp-1031-c41863');
        $this->markAsPaidWooOrder($order, 'WC-US-SM');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        Http::preventStrayRequests();
        Http::fake();
        $url = "/logistics/oversea/us-3sm/orders/{$order->id}/manual-export";
        $first = $this->actingAs($user)->get($url)->assertOk();
        $second = $this->actingAs($user)->get($url)->assertOk();
        $rows1 = $this->xlsxRows($first->getContent());
        $rows2 = $this->xlsxRows($second->getContent());
        $this->assertCount(2, $rows1);
        $this->assertCount(2, $rows2);
        $this->assertSame('Yangfan', $rows1[1][0]);
        $this->assertSame('Upload_Shipping_Label', $rows1[1][5]);
        $this->assertSame('USPS', $rows1[1][6]);
        $this->assertSame('REVIEW-SKU', $rows1[1][24]);
        $this->assertSame('2', $rows1[1][25]);
        // OMS import requires clean integer digits, not a numerical Excel cell
        // which its returned error report reserializes as text '2.0'.
        $this->assertStringContainsString(
            '<c r="Z2" t="inlineStr"><is><t xml:space="preserve">2</t></is></c>',
            $this->xlsxXml($first->getContent())
        );
        $this->assertSame($rows1[1][3], $rows1[1][4]);
        $this->assertSame($rows2[1][3], $rows2[1][4]);
        $this->assertNotSame($rows1[1][3], $rows2[1][3]);
        $this->assertStringContainsString('DO NOT SHIP', $rows1[1][23]);
        $this->assertStringContainsString('current drafts', $rows1[1][23]);
        $this->assertStringContainsString('private', $first->headers->get('Cache-Control'));
        $this->assertSame('pending', $order->fresh()->shipment->shipment_status);
        Http::assertNothingSent();
    }

    public function test_us_sm_invalid_outbound_quantities_fail_before_excel_export(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $w = $this->warehouses();
        $order = $this->orderAt($w['US-SM'], '007erp-1031-c41867');
        $this->markAsPaidWooOrder($order, 'WC-US-SM-GUARD');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $url = "/logistics/oversea/us-3sm/orders/{$order->id}/manual-export";

        foreach ([0, 100000] as $badQuantity) {
            $order->items()->firstOrFail()->update(['quantity' => $badQuantity]);
            $this->actingAs($user)->get($url)->assertStatus(422);
        }
    }

    public function test_us_sm_manual_download_needs_review_and_operator_role_and_is_warehouse_isolated(): void
    {
        $w = $this->warehouses();
        $order = $this->orderAt($w['US-SM'], '007erp-1031-c41864');
        $ny = $this->orderAt($w['US-NY'], '007erp-1031-c41865');
        $operator = $this->userWith(['logistics.view', 'logistics.operate']);
        $viewer = $this->userWith(['logistics.view']);
        $this->actingAs($operator)->get("/logistics/oversea/us-3sm/orders/{$order->id}/manual-export")
            ->assertStatus(422);
        $this->actingAs($operator)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $this->actingAs($viewer)->get("/logistics/oversea/us-3sm/orders/{$order->id}/manual-export")
            ->assertForbidden();
        $this->actingAs($operator)->get("/logistics/oversea/us-3sm/orders/{$ny->id}/manual-export")
            ->assertNotFound();
    }

    public function test_us_sm_previous_automatic_create_http_routes_permanently_disabled(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $operator = $this->userWith(['logistics.view', 'logistics.operate']);
        $w = $this->warehouses();
        $order = $this->orderAt($w['US-SM'], '007erp-1031-c41866');
        Http::preventStrayRequests();
        Http::fake();
        $paths = [
            '/logistics/oversea/us-3sm/xlwms/sync',
            "/logistics/oversea/us-3sm/orders/{$order->id}/xlwms/send",
            "/logistics/oversea/us-3sm/orders/{$order->id}/xlwms/web-draft",
            "/logistics/oversea/us-3sm/orders/{$order->id}/xlwms/reship-create",
        ];
        foreach ($paths as $path) {
            $response = $this->actingAs($operator)->postJson($path, []);
            $response->assertStatus(410)->assertJsonPath('code', 'XLWMS_MANUAL_ORDER_ONLY');
        }
        $viewer = $this->userWith(['logistics.view']);
        $this->actingAs($viewer)->postJson(
            "/logistics/oversea/us-3sm/orders/{$order->id}/xlwms/web-draft", []
        )->assertForbidden();
        Http::assertNothingSent();
    }
}

final class TrackingReturnFakeWooGateway implements WooCommerceTrackingGateway
{
    public int $trackingCalls = 0;

    public int $orderStatusCalls = 0;

    public int $providerOrderId = 0;

    public string $trackingNumber = '';

    public array $lineItems = [];

    public function descriptor(): array
    {
        return ['configured' => true, 'url' => 'https://shop.test', 'connection_key' => 'shop-a'];
    }

    public function inspectOrderStatus(int $providerOrderId): string
    {
        return 'processing';
    }

    public function inspectTracking(int $providerOrderId): array
    {
        return ['tracking_number' => $this->trackingNumber, 'provider' => 'usps', 'ship_date' => '2026-09-15', 'has_tracking' => $this->trackingNumber !== ''];
    }

    public function syncTracking(int $providerOrderId, string $providerSlug, string $trackingNumber, string $shipDate, array $lineItems = []): array
    {
        $this->trackingCalls++;
        $this->providerOrderId = $providerOrderId;
        $this->trackingNumber = $trackingNumber;
        $this->lineItems = $lineItems;

        return ['written' => true, 'verified' => true];
    }

    public function syncOrderStatus(int $providerOrderId, string $status): array
    {
        $this->orderStatusCalls++;

        return ['written' => true, 'verified' => true, 'status' => $status];
    }
}
