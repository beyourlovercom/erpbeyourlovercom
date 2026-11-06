<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Modules\Audit\Infrastructure\Models\AuditRecord;
use App\Modules\Logistics\Application\LogisticsTrackingImmediateDispatch;
use App\Modules\Logistics\Application\LogisticsTrackingListStatus;
use App\Modules\Logistics\Application\LogisticsTrackingPushAccess;
use App\Modules\Logistics\Application\ShipmentService;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\Contracts\SyncOrchestrationRepository;
use App\Modules\Sync\Application\Contracts\WooCommerceCustomerNoteGateway;
use App\Modules\Sync\Application\Contracts\WooCommerceTrackingBatchInspector;
use App\Modules\Sync\Application\Contracts\WooCommerceTrackingGateway;
use App\Modules\Sync\Application\ManualSyncService;
use App\Modules\Sync\Application\SyncOrchestrationService;
use App\Modules\Sync\Application\WooCommerceManualTrackingOverrideService;
use App\Modules\Sync\Application\WooCommerceTrackingConflictException;
use App\Modules\Sync\Application\WooCommerceTrackingOutboundService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LogisticsTrackingPushControllerTest extends TestCase
{
    use RefreshDatabase;

    private TrackingPushFakeGateway $gateway;

    private TrackingPushFakeCustomerNoteGateway $customerNotes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new TrackingPushFakeGateway;
        $this->app->instance(WooCommerceTrackingGateway::class, $this->gateway);
        Cache::flush();
        $this->customerNotes = new TrackingPushFakeCustomerNoteGateway;
        $this->app->instance(WooCommerceCustomerNoteGateway::class, $this->customerNotes);
        config(['tracking_sync_v2.manual_mode' => 'general']);
    }

    public function test_existing_exact_website_tracking_is_projected_as_synced_without_any_write(): void
    {
        $order = $this->order(1191);
        $this->gateway->batchSnapshots[1191] = [
            'order_status' => 'completed',
            'tracking_items' => [[
                'tracking_number' => 'TRACK-1191',
                'provider' => 'dhl-api',
                'ship_date' => '2026-11-06',
            ]],
            'has_tracking' => true,
            'checked_at' => now()->toIso8601String(),
        ];

        $states = app(LogisticsTrackingListStatus::class)->forShipments([$order->shipment->id]);
        $projected = app(LogisticsTrackingListStatus::class)->currentStatus(
            $states[$order->shipment->id] ?? null,
            $order->manualSync->website_sync_status,
        );

        self::assertSame('completed', $projected['status']);
        self::assertTrue($states[$order->shipment->id]['verified_remote_tracking']);
        self::assertSame(1, $this->gateway->batchCalls);
        self::assertSame(0, $this->gateway->calls);
        self::assertSame(0, $this->gateway->statusCalls);
        self::assertDatabaseHas('manual_syncs', [
            'order_id' => $order->id,
            'website_sync_status' => 'pending',
        ]);
    }

    public function test_existing_split_child_package_is_found_beyond_the_first_remote_tracking_item(): void
    {
        $root = $this->order(1192);
        $child = $this->order(1193);
        $child->forceFill(['fulfillment_parent_id' => $root->id, 'fulfillment_suffix' => 'B'])->save();
        $childC = $this->order(1196);
        $childC->forceFill(['fulfillment_parent_id' => $root->id, 'fulfillment_suffix' => 'C'])->save();
        $this->gateway->batchSnapshots[1192] = [
            'order_status' => 'completed',
            'tracking_items' => [
                ['tracking_number' => 'TRACK-1192', 'provider' => 'dhl-api', 'ship_date' => null],
                ['tracking_number' => 'TRACK-1193', 'provider' => 'dhl-api', 'ship_date' => null],
                ['tracking_number' => 'TRACK-1196', 'provider' => 'dhl-api', 'ship_date' => null],
            ],
            'has_tracking' => true,
            'checked_at' => now()->toIso8601String(),
        ];

        $shipmentIds = [$root->shipment->id, $child->shipment->id, $childC->shipment->id];
        $states = app(LogisticsTrackingListStatus::class)->forShipments($shipmentIds);
        foreach ([[$root, $root->shipment->id], [$child, $child->shipment->id], [$childC, $childC->shipment->id]] as [$packageOrder, $shipmentId]) {
            $projected = app(LogisticsTrackingListStatus::class)->currentStatus(
                $states[$shipmentId] ?? null,
                $packageOrder->manualSync->website_sync_status,
            );
            self::assertSame('completed', $projected['status']);
            self::assertTrue($states[$shipmentId]['verified_remote_tracking']);
        }
        self::assertSame(1, $this->gateway->batchCalls);
        self::assertSame(0, $this->gateway->calls);
        self::assertSame(0, $this->customerNotes->createdCount);
    }

    public function test_same_tracking_number_with_different_website_carrier_is_a_conflict_not_synced(): void
    {
        $order = $this->order(1194);
        $this->gateway->batchSnapshots[1194] = [
            'order_status' => 'completed',
            'tracking_items' => [[
                'tracking_number' => 'TRACK-1194',
                'provider' => 'usps',
                'ship_date' => null,
            ]],
            'has_tracking' => true,
            'checked_at' => now()->toIso8601String(),
        ];

        $states = app(LogisticsTrackingListStatus::class)->forShipments([$order->shipment->id]);
        $projected = app(LogisticsTrackingListStatus::class)->currentStatus(
            $states[$order->shipment->id] ?? null,
            $order->manualSync->website_sync_status,
        );

        self::assertSame('conflict', $projected['status']);
        self::assertStringContainsString('承运商不一致', $projected['reason']);
        self::assertSame(0, $this->gateway->calls);
        self::assertSame(0, $this->gateway->statusCalls);
    }

    public function test_manual_override_recognizes_existing_website_tracking_without_rewriting_or_notifying(): void
    {
        $user = $this->userWith('role_preset_operations', ['logistics.view', 'orders.view', 'sync.view']);
        $order = $this->order(1195);
        $this->gateway->remoteStatus = 'completed';
        $this->gateway->batchSnapshots[1195] = [
            'order_status' => 'completed',
            'tracking_items' => [[
                'tracking_number' => 'TRACK-1195',
                'provider' => 'dhl-api',
                'ship_date' => '2026-11-06',
            ]],
            'has_tracking' => true,
            'checked_at' => now()->toIso8601String(),
        ];

        $result = app(WooCommerceManualTrackingOverrideService::class)->pushOne($order, $user);

        self::assertSame('completed', $result['status']);
        self::assertFalse($result['written']);
        self::assertSame('existing_website_tracking', $result['notification_channel']);
        self::assertSame(1, $this->gateway->batchCalls);
        self::assertSame(0, $this->gateway->calls);
        self::assertSame(0, $this->gateway->statusCalls);
        self::assertSame(0, $this->customerNotes->createdCount);
        $this->assertDatabaseHas('manual_syncs', [
            'order_id' => $order->id,
            'website_sync_status' => 'completed',
        ]);
        $this->assertDatabaseHas('order_operational_markers', [
            'order_id' => $order->id,
            'marker' => 'woo_manual_tracking_verified',
        ]);
        self::assertSame($order->workflow_status, $order->fresh()->workflow_status);
    }

    public function test_manual_tracking_is_closed_to_vita_and_logistics_by_default(): void
    {
        config(['tracking_sync_v2.manual_mode' => 'off']);
        $operator = $this->userWith('role_preset_operations', ['logistics.view', 'orders.view', 'sync.view']);
        $order = $this->order(1017);
        self::assertFalse(app(LogisticsTrackingPushAccess::class)->allows($operator));
        $this->actingAs($operator)->post('/logistics/tracking-outbound/selected', ['order_ids' => [$order->id]])
            ->assertForbidden();
        self::assertSame(0, $this->gateway->calls);
    }

    public function test_general_manual_sync_rejects_historical_uat_roles_even_with_broad_permissions(): void
    {
        foreach (['uat_owner_admin', 'uat_logistics_operator'] as $roleKey) {
            $user = $this->userWith($roleKey, [
                'logistics.view', 'logistics.operate', 'sync.view', 'sync.operate',
            ]);
            self::assertFalse(app(LogisticsTrackingPushAccess::class)->allows($user));
            $this->actingAs($user)->post('/logistics/tracking-outbound/selected', [
                'order_ids' => [999],
            ])->assertForbidden();
        }
        self::assertSame(0, $this->gateway->calls);
    }

    public function test_general_manual_sync_only_allows_formal_logistics_operations_and_administrator_roles(): void
    {
        foreach (['role_preset_logistics', 'role_preset_operations', 'role_preset_administrator'] as $roleKey) {
            $user = $this->userWith($roleKey, ['logistics.view', 'sync.view']);
            self::assertTrue(app(LogisticsTrackingPushAccess::class)->allows($user));
        }

        $unrelated = $this->userWith('role_preset_kol_manager', [
            'logistics.view', 'logistics.operate', 'sync.view', 'sync.operate',
        ]);
        self::assertFalse(app(LogisticsTrackingPushAccess::class)->allows($unrelated));
    }

    public function test_manual_canary_requires_explicit_exact_erp_order_allowlist(): void
    {
        config(['tracking_sync_v2.manual_mode' => 'canary', 'tracking_sync_v2.manual_canary_order_ids' => '']);
        $user = $this->userWith('role_preset_operations', ['logistics.view', 'orders.view', 'sync.view']);
        $allowed = $this->order(1018);
        $other = $this->order(1019);
        $this->actingAs($user)->post('/logistics/tracking-outbound/selected',
            ['order_ids' => [$allowed->id]])->assertForbidden();
        config(['tracking_sync_v2.manual_canary_order_ids' => (string) $allowed->id]);
        $this->actingAs($user)->post('/logistics/tracking-outbound/selected',
            ['order_ids' => [$allowed->id, $other->id]])->assertForbidden();
        self::assertSame(0, $this->gateway->calls);
        $this->actingAs($user)->post('/logistics/tracking-outbound/selected',
            ['order_ids' => [$allowed->id]])->assertRedirect()->assertSessionHas('success');
        self::assertSame(1, $this->gateway->calls);
    }

    public function test_vita_role_can_manually_push_shipped_woo_order_without_global_sync_operate(): void
    {
        $user = $this->userWith('role_preset_operations', ['logistics.view', 'orders.view', 'sync.view']);
        self::assertFalse($user->can('sync.operate'));
        $order = $this->order();

        $this->actingAs($user)->post('/logistics/tracking-outbound/selected', ['order_ids' => [$order->id]])
            ->assertRedirect()->assertSessionHas('success');

        self::assertSame(1, $this->gateway->calls);
        $this->assertDatabaseHas('manual_syncs', ['order_id' => $order->id, 'website_sync_status' => 'completed']);
    }

    public function test_manual_override_allows_pending_shipment_with_only_tracking_and_no_line_identity(): void
    {
        $user = $this->userWith('role_preset_operations', ['logistics.view', 'orders.view', 'sync.view']);
        $order = $this->order(1091);
        $order->shipment->update(['shipment_status' => 'pending', 'shipped_at' => null, 'carrier' => null]);
        $order->items()->update(['woocommerce_line_item_id' => null]);
        // A single Woo Completed email should suffice; even a deliberately
        // failing Customer Note endpoint MUST NOT be called for the first.
        $this->customerNotes->fail = true;

        $this->actingAs($user)->post('/logistics/tracking-outbound/selected', [
            'order_ids' => [$order->id], 'manual_override' => true,
        ])->assertRedirect()->assertSessionHas('success');

        self::assertSame(1, $this->gateway->calls);
        self::assertSame([], $this->gateway->lastLineItems);
        self::assertSame('other', $this->gateway->lastCarrierSlug);
        self::assertSame(0, $this->customerNotes->createdCount);
        self::assertSame(1091, $this->gateway->lastProviderOrderId);
        self::assertSame('completed', $this->gateway->remoteStatus);
        self::assertSame('pending', $order->shipment->fresh()->shipment_status);
        self::assertSame('shared', $order->fresh()->workflow_status);
        $this->assertDatabaseHas('order_workflow_status_histories', ['order_id' => $order->id, 'to_status' => 'shared']);
        $this->assertDatabaseHas('manual_syncs', [
            'order_id' => $order->id, 'website_sync_status' => 'completed',
        ]);
        $this->assertDatabaseHas('order_operational_markers', [
            'order_id' => $order->id, 'marker' => 'woo_manual_force_completed',
        ]);
        $this->assertDatabaseHas('order_operational_markers', [
            'order_id' => $order->id, 'marker' => 'woo_manual_tracking_verified',
        ]);
        $this->assertDatabaseHas('sync_runs', ['run_type' => 'tracking_manual_force_completed']);
    }

    public function test_manual_override_of_a_child_writes_to_woo_mother_and_marks_mother_completed(): void
    {
        $user = $this->userWith('role_preset_logistics', ['logistics.view', 'sync.view']);
        $root = $this->order(1092);
        $child = $this->order(1093);
        $child->forceFill(['fulfillment_parent_id' => $root->id, 'fulfillment_suffix' => 'A'])->save();
        $child->shipment->update(['shipment_status' => 'pending', 'shipped_at' => null]);
        $child->items()->update(['woocommerce_line_item_id' => null]);
        $this->customerNotes->fail = true; // A must never call Customer Note.

        $this->actingAs($user)->post('/logistics/tracking-outbound/selected', [
            'order_ids' => [$child->id], 'manual_override' => true,
        ])->assertRedirect()->assertSessionHas('success');

        self::assertSame(1092, $this->gateway->lastProviderOrderId);
        self::assertSame([], $this->gateway->lastLineItems);
        self::assertSame('completed', $this->gateway->remoteStatus);
        $this->assertDatabaseHas('order_operational_markers', [
            'order_id' => $root->id, 'marker' => 'woo_manual_force_completed',
        ]);
        $this->assertDatabaseHas('order_operational_markers', [
            'order_id' => $child->id, 'marker' => 'woo_manual_tracking_verified',
        ]);
        $trackingState = app(LogisticsTrackingListStatus::class)->forShipments([$child->shipment->id]);
        self::assertSame(
            'completed',
            app(LogisticsTrackingListStatus::class)->currentStatus(
                $trackingState[$child->shipment->id] ?? null, $child->manualSync->fresh()->website_sync_status
            )['status'],
        );
        $this->assertDatabaseHas('manual_syncs', [
            'order_id' => $child->id, 'website_sync_status' => 'completed',
        ]);
        self::assertSame('shipped', $root->shipment->fresh()->shipment_status);
        self::assertSame('pending', $child->shipment->fresh()->shipment_status);
        self::assertSame('shared', $child->fresh()->workflow_status);
        self::assertNull($root->fresh()->workflow_status);
        self::assertSame(0, $this->customerNotes->createdCount);

        // Tracking correction makes the status pending again even though the
        // old verified-marker row remains for audit.
        app(ManualSyncService::class)->reopen($child, $user);
        $statusAfterReopen = app(LogisticsTrackingListStatus::class)->currentStatus(
            $trackingState[$child->shipment->id] ?? null,
            $child->manualSync->fresh()->website_sync_status,
        );
        self::assertSame('pending', $statusAfterReopen['status']);

        // Even if a draft is changed without reopening ManualSync, the
        // recorded verified fingerprint must not claim the new Tracking synced.
        $child->shipment->update(['tracking_number' => 'TRACK-1093-NEW']);
        $child->manualSync()->update(['website_sync_status' => 'completed']);
        $changedState = app(LogisticsTrackingListStatus::class)->forShipments([$child->shipment->id]);
        self::assertSame(
            'pending',
            app(LogisticsTrackingListStatus::class)->currentStatus(
                $changedState[$child->shipment->id] ?? null,
                $child->manualSync->fresh()->website_sync_status,
            )['status'],
        );
        $child->shipment->update(['tracking_number' => 'TRACK-1093']);

        $this->actingAs($user)->post('/logistics/tracking-outbound/selected', [
            'order_ids' => [$child->id], 'manual_override' => true,
        ])->assertSessionHas('success');
        self::assertSame(2, $this->gateway->calls);
        self::assertSame(1, $this->gateway->writes);
        self::assertSame(0, $this->customerNotes->createdCount);
    }

    public function test_b_first_processing_uses_completed_email_not_customer_note(): void
    {
        $user = $this->userWith('role_preset_operations', ['logistics.view', 'sync.view']);
        $root = $this->order(1132);
        $child = $this->order(1133);
        $child->forceFill(['fulfillment_parent_id' => $root->id, 'fulfillment_suffix' => 'B'])->save();

        $this->actingAs($user)->post('/logistics/tracking-outbound/selected', [
            'order_ids' => [$child->id], 'manual_override' => true,
        ])->assertSessionHas('success');

        self::assertSame('completed', $this->gateway->remoteStatus);
        self::assertSame('shared', $child->fresh()->workflow_status);
        self::assertSame(0, $this->customerNotes->createdCount);
        self::assertStringContainsString('Woo Completed 邮件', (string) $child->manualSync->fresh()->sync_note);
    }

    public function test_c_followup_sends_one_customer_note_with_woo_mother_completed(): void
    {
        $user = $this->userWith('role_preset_operations', ['logistics.view', 'sync.view']);
        $root = $this->order(1134);
        $root->operationalMarkers()->create(['marker' => 'woo_manual_force_completed', 'marked_at' => now()]);
        $child = $this->order(1135);
        $child->forceFill(['fulfillment_parent_id' => $root->id, 'fulfillment_suffix' => 'C'])->save();
        $this->gateway->remoteStatus = 'completed';

        $this->actingAs($user)->post('/logistics/tracking-outbound/selected', [
            'order_ids' => [$child->id], 'manual_override' => true,
        ])->assertSessionHas('success');
        self::assertSame(1, $this->customerNotes->createdCount);
        self::assertSame(1134, $this->customerNotes->lastWooOrder);
        self::assertSame('shared', $child->fresh()->workflow_status);

        $this->actingAs($user)->post('/logistics/tracking-outbound/selected', [
            'order_ids' => [$child->id], 'manual_override' => true,
        ])->assertSessionHas('success');
        self::assertSame(1, $this->customerNotes->createdCount);
    }

    public function test_force_override_is_single_order_only_and_does_not_allow_unsafe_bulk(): void
    {
        $user = $this->userWith('role_preset_operations', ['logistics.view', 'sync.view']);
        $a = $this->order(1096);
        $b = $this->order(1097);
        $this->actingAs($user)->post('/logistics/tracking-outbound/selected', [
            'order_ids' => [$a->id, $b->id], 'manual_override' => true,
        ])->assertStatus(422);
        self::assertSame(0, $this->gateway->calls);
    }

    public function test_manual_override_refuses_non_processing_woo_statuses_without_writes(): void
    {
        $user = $this->userWith('role_preset_operations', ['logistics.view', 'sync.view']);
        foreach (['refunded', 'cancelled', 'failed', 'on-hold', 'pending', 'completed'] as $status) {
            $order = $this->order(1094 + count($this->gateway->seenOrders));
            $this->gateway->remoteStatus = $status;
            $this->actingAs($user)->post('/logistics/tracking-outbound/selected', [
                'order_ids' => [$order->id], 'manual_override' => true,
            ])->assertSessionHas('warning');
            $this->assertDatabaseHas('sync_run_items', [
                'order_id' => $order->id, 'status' => 'skipped', 'error_code' => 'not_eligible',
            ]);
            self::assertSame(0, $this->gateway->calls, 'Non-processing Woo must not receive Tracking: '.$status);
            self::assertNull($order->fresh()->workflow_status);
            $this->assertDatabaseMissing('order_operational_markers', ['order_id' => $order->id, 'marker' => 'woo_manual_force_completed']);
            $this->gateway->seenOrders[] = $order->id;
        }
    }

    public function test_manual_override_tracking_conflict_never_marks_erp_shared(): void
    {
        $user = $this->userWith('role_preset_operations', ['logistics.view', 'sync.view']);
        $order = $this->order(1119);
        $order->shipment->update(['shipment_status' => 'pending', 'shipped_at' => null]);
        $this->gateway->conflict = true;
        $this->actingAs($user)->post('/logistics/tracking-outbound/selected', [
            'order_ids' => [$order->id], 'manual_override' => true,
        ])->assertSessionHas('warning');

        self::assertNull($order->fresh()->workflow_status);
        $this->assertDatabaseHas('manual_syncs', [
            'order_id' => $order->id, 'website_sync_status' => 'pending',
        ]);
        $this->assertDatabaseMissing('order_operational_markers', [
            'order_id' => $order->id, 'marker' => 'woo_manual_force_completed',
        ]);
    }

    public function test_manual_override_woo_status_failure_never_marks_erp_shared(): void
    {
        $user = $this->userWith('role_preset_operations', ['logistics.view', 'sync.view']);
        $order = $this->order(1120);
        $this->gateway->failStatus = true;
        $this->actingAs($user)->post('/logistics/tracking-outbound/selected', [
            'order_ids' => [$order->id], 'manual_override' => true,
        ])->assertSessionHas('warning');

        self::assertNull($order->fresh()->workflow_status);
        $this->assertDatabaseHas('manual_syncs', [
            'order_id' => $order->id, 'website_sync_status' => 'pending',
        ]);
        $this->assertDatabaseMissing('order_operational_markers', [
            'order_id' => $order->id, 'marker' => 'woo_manual_force_completed',
        ]);
    }

    public function test_customer_note_failure_leaves_erp_unshared_then_retry_recovers_without_duplicate(): void
    {
        $user = $this->userWith('role_preset_operations', ['logistics.view', 'sync.view']);
        $root = $this->order(1128);
        $root->operationalMarkers()->create(['marker' => 'woo_manual_force_completed', 'marked_at' => now()]);
        $order = $this->order(1129);
        $order->forceFill(['fulfillment_parent_id' => $root->id, 'fulfillment_suffix' => 'B'])->save();
        // B is a subsequent package; Woo mother has already been completed by A.
        $this->gateway->remoteStatus = 'completed';
        $this->customerNotes->fail = true;
        $this->actingAs($user)->post('/logistics/tracking-outbound/selected', [
            'order_ids' => [$order->id], 'manual_override' => true,
        ])->assertSessionHas('warning');

        self::assertSame('completed', $this->gateway->remoteStatus);
        self::assertNull($order->fresh()->workflow_status);
        $this->assertDatabaseHas('manual_syncs', [
            'order_id' => $order->id, 'website_sync_status' => 'pending',
        ]);
        $this->assertDatabaseHas('order_operational_markers', [
            'order_id' => $root->id, 'marker' => 'woo_manual_force_completed',
        ]);
        self::assertSame(0, $this->customerNotes->createdCount);

        $this->customerNotes->fail = false;
        $this->actingAs($user)->post('/logistics/tracking-outbound/selected', [
            'order_ids' => [$order->id], 'manual_override' => true,
        ])->assertSessionHas('success');
        self::assertSame('shared', $order->fresh()->workflow_status);
        self::assertSame(1, $this->customerNotes->createdCount);

        $this->actingAs($user)->post('/logistics/tracking-outbound/selected', [
            'order_ids' => [$order->id], 'manual_override' => true,
        ])->assertSessionHas('success');
        self::assertSame(1, $this->customerNotes->createdCount);
    }

    public function test_manual_override_reports_missing_parent_woo_identity_instead_of_false_success(): void
    {
        $user = $this->userWith('role_preset_logistics', ['logistics.view', 'sync.view']);
        $order = $this->order(1095);
        $order->forceFill(['source_type' => 'manual_other'])->save();
        $this->actingAs($user)->post('/logistics/tracking-outbound/selected', [
            'order_ids' => [$order->id], 'manual_override' => true,
        ])->assertSessionHas('warning', fn (string $value) => str_contains($value, '官网母订单'));
        self::assertSame(0, $this->gateway->calls);
        $this->assertDatabaseHas('sync_run_items', ['status' => 'skipped', 'error_code' => 'not_eligible']);
    }

    public function test_corrected_tracking_remains_pending_when_woo_line_conflicts(): void
    {
        $user = $this->userWith('role_preset_operations', ['logistics.view', 'sync.view']);
        $order = $this->order(1021);
        $this->actingAs($user)->post('/logistics/tracking-outbound/selected', [
            'order_ids' => [$order->id],
        ])->assertSessionHas('success');

        app(ShipmentService::class)
            ->updateTracking($order, 'TRACK-1021-CORRECTED', $user);
        $this->assertDatabaseHas('manual_syncs', [
            'order_id' => $order->id, 'website_sync_status' => 'pending',
        ]);

        $this->gateway->conflict = true;
        $this->actingAs($user)->post('/logistics/tracking-outbound/selected', [
            'order_ids' => [$order->id],
        ])->assertSessionHas('warning');
        $this->assertSame(1, $this->gateway->writes);
        $this->assertDatabaseHas('manual_syncs', [
            'order_id' => $order->id, 'website_sync_status' => 'pending',
        ]);
        $this->assertDatabaseHas('sync_outbox', [
            'aggregate_type' => 'shipment', 'aggregate_id' => (string) $order->shipment->id,
            'status' => 'failed', 'last_error_code' => 'remote_tracking_conflict',
        ]);
    }

    public function test_repeated_explicit_sync_is_idempotent_and_does_not_duplicate_woo_tracking(): void
    {
        $user = $this->userWith('role_preset_logistics', ['logistics.view', 'logistics.operate', 'sync.view', 'sync.operate']);
        $order = $this->order();

        $this->actingAs($user)->post('/logistics/tracking-outbound/selected', ['order_ids' => [$order->id]])
            ->assertSessionHas('success');
        $this->actingAs($user)->post('/logistics/tracking-outbound/selected', ['order_ids' => [$order->id]])
            ->assertSessionHas('success');

        self::assertSame(2, $this->gateway->calls);
        self::assertSame(1, $this->gateway->writes);
    }

    public function test_custom_permission_mode_operations_account_cannot_inherit_scoped_authority(): void
    {
        $user = $this->userWith('role_preset_operations', ['logistics.view', 'orders.view', 'sync.view']);
        $user->update(['permission_mode' => 'custom']);
        $order = $this->order();
        $this->actingAs($user)->post('/logistics/tracking-outbound/selected', ['order_ids' => [$order->id]])->assertForbidden();
        self::assertSame(0, $this->gateway->calls);
    }

    public function test_not_shipped_woo_order_is_rejected_without_mutating_outbound(): void
    {
        $user = $this->userWith('role_preset_operations', ['logistics.view', 'sync.view']);
        $order = $this->order();
        $order->shipment->update(['shipment_status' => 'pending']);
        $this->actingAs($user)->post('/logistics/tracking-outbound/selected', ['order_ids' => [$order->id]])
            ->assertStatus(422);
        self::assertSame(0, $this->gateway->calls);
        $this->assertDatabaseCount('sync_outbox', 0);
    }

    public function test_non_woo_order_cannot_be_pushed(): void
    {
        $user = $this->userWith('role_preset_operations', ['logistics.view', 'sync.view']);
        $order = $this->order();
        $order->forceFill(['source_type' => 'manual_other'])->save();
        $this->actingAs($user)->post('/logistics/tracking-outbound/selected', ['order_ids' => [$order->id]])
            ->assertStatus(422);
        self::assertSame(0, $this->gateway->calls);
    }

    public function test_duplicate_input_ids_are_rejected_prior_to_remote_write(): void
    {
        $user = $this->userWith('role_preset_operations', ['logistics.view', 'sync.view']);
        $order = $this->order();
        $this->actingAs($user)->post('/logistics/tracking-outbound/selected', ['order_ids' => [$order->id, $order->id]])
            ->assertSessionHasErrors('order_ids.1');
        self::assertSame(0, $this->gateway->calls);
    }

    public function test_immediate_outbound_is_off_by_default_and_never_calls_gateway(): void
    {
        $order = $this->order();
        self::assertFalse((bool) config('tracking_sync_v2.immediate_enabled'));
        $result = app(LogisticsTrackingImmediateDispatch::class)->afterShipmentSaved($order, null);
        self::assertSame('disabled', $result['status']);
        self::assertSame(0, $this->gateway->calls);
    }

    public function test_enabled_immediate_dispatch_uses_same_idempotent_outbox(): void
    {
        config(['tracking_sync_v2.immediate_enabled' => true, 'tracking_sync_v2.immediate_mode' => 'general']);
        $order = $this->order();
        $dispatcher = app(LogisticsTrackingImmediateDispatch::class);
        $first = $dispatcher->afterShipmentSaved($order, null);
        $second = $dispatcher->afterShipmentSaved($order, null);

        self::assertSame('completed', $first['status']);
        self::assertSame('completed', $second['status']);
        self::assertSame(1, $this->gateway->calls);
        $this->assertDatabaseHas('manual_syncs', ['order_id' => $order->id, 'website_sync_status' => 'completed']);
    }

    public function test_immediate_dispatch_never_sends_unshipped_or_manual_source(): void
    {
        config(['tracking_sync_v2.immediate_enabled' => true, 'tracking_sync_v2.immediate_mode' => 'general']);
        $order = $this->order();
        $order->shipment->update(['shipment_status' => 'pending']);
        $dispatcher = app(LogisticsTrackingImmediateDispatch::class);
        self::assertSame('not_eligible', $dispatcher->afterShipmentSaved($order, null)['status']);
        $order->shipment->update(['shipment_status' => 'shipped']);
        $order->forceFill(['source_type' => 'manual_other'])->save();
        self::assertSame('not_eligible', $dispatcher->afterShipmentSaved($order, null)['status']);
        self::assertSame(0, $this->gateway->calls);
    }

    public function test_immediate_cutover_requires_explicit_mode_and_exact_canary_order(): void
    {
        $allowed = $this->order(1031);
        $other = $this->order(1032);
        $dispatcher = app(LogisticsTrackingImmediateDispatch::class);

        config(['tracking_sync_v2.immediate_enabled' => true]);
        self::assertSame('disabled', $dispatcher->afterShipmentSaved($allowed, null)['status']);

        config(['tracking_sync_v2.immediate_mode' => 'canary']);
        self::assertSame('not_allowlisted', $dispatcher->afterShipmentSaved($allowed, null)['status']);

        config(['tracking_sync_v2.immediate_canary_order_ids' => 'all,1x,-1']);
        self::assertSame('not_allowlisted', $dispatcher->afterShipmentSaved($allowed, null)['status']);

        config(['tracking_sync_v2.immediate_canary_order_ids' => (string) $allowed->id]);
        self::assertSame('not_allowlisted', $dispatcher->afterShipmentSaved($other, null)['status']);
        self::assertSame(0, $this->gateway->calls);
        self::assertSame('completed', $dispatcher->afterShipmentSaved($allowed, null)['status']);
        self::assertSame('completed', $dispatcher->afterShipmentSaved($allowed, null)['status']);
        self::assertSame(1, $this->gateway->calls);
        $this->assertDatabaseHas('manual_syncs', [
            'order_id' => $other->id, 'website_sync_status' => 'pending',
        ]);
    }

    public function test_immediate_boolean_off_blocks_canary_even_when_allowlisted(): void
    {
        $order = $this->order(1033);
        config([
            'tracking_sync_v2.immediate_enabled' => false,
            'tracking_sync_v2.immediate_mode' => 'canary',
            'tracking_sync_v2.immediate_canary_order_ids' => (string) $order->id,
        ]);
        self::assertSame('disabled', app(LogisticsTrackingImmediateDispatch::class)
            ->afterShipmentSaved($order, null)['status']);
        self::assertSame(0, $this->gateway->calls);
    }

    public function test_automatic_retry_only_selects_outbox_after_activation_baseline(): void
    {
        config(['tracking_sync_v2.periodic_enabled' => true, 'tracking_sync_v2.min_outbox_id' => '1']);
        $gateway = $this->gateway;
        $gateway->fail = true;
        $old = $this->order(1009);
        $service = app(WooCommerceTrackingOutboundService::class);
        self::assertSame('retry_scheduled', $service->pushOne($old, null)['status']);
        $oldOutboxId = (int) DB::table('sync_outbox')
            ->where('operation', 'woocommerce.tracking_outbound')->value('id');

        $new = $this->order(1010);
        self::assertSame('retry_scheduled', $service->pushOne($new, null)['status']);
        DB::table('sync_outbox')
            ->where('operation', 'woocommerce.tracking_outbound')
            ->update(['available_at' => now()->subMinute()]);

        $gateway->fail = false;
        $run = app(SyncOrchestrationService::class)
            ->retryPending(null, 50, $oldOutboxId);

        self::assertSame(1, $run->requested_items);
        self::assertSame(1, $run->succeeded_items);
        $this->assertDatabaseHas('sync_outbox', ['id' => $oldOutboxId, 'status' => 'pending']);
        $this->assertDatabaseHas('manual_syncs', ['order_id' => $new->id, 'website_sync_status' => 'completed']);
        $this->assertDatabaseHas('manual_syncs', ['order_id' => $old->id, 'website_sync_status' => 'pending']);
    }

    public function test_tracking_retry_schedule_requires_activation_baseline_and_default_is_disabled(): void
    {
        self::assertFalse((bool) config('tracking_sync_v2.periodic_enabled'));
        config(['tracking_sync_v2.periodic_enabled' => true, 'tracking_sync_v2.min_outbox_id' => null]);
        require base_path('routes/console.php');
        $events = collect(app(Schedule::class)->events());
        self::assertCount(0, $events->where('description', 'tracking.woocommerce.outbound.retry.half-hourly'));

        config(['tracking_sync_v2.min_outbox_id' => '13']);
        require base_path('routes/console.php');
        self::assertCount(0, collect(app(Schedule::class)->events())
            ->where('description', 'tracking.woocommerce.outbound.retry.half-hourly'));

        config(['tracking_sync_v2.min_shipment_id' => '0']);
        require base_path('routes/console.php');
        $tracking = collect(app(Schedule::class)->events())
            ->where('description', 'tracking.woocommerce.outbound.retry.half-hourly');
        self::assertCount(1, $tracking);
        self::assertSame('*/30 * * * *', $tracking->first()->expression);
        self::assertTrue($tracking->first()->onOneServer);
        self::assertTrue($tracking->first()->withoutOverlapping);
        self::assertSame(25, $tracking->first()->expiresAt);
    }

    public function test_auto_discovery_scans_only_new_shipment_ids_and_never_sends_legacy_shipments(): void
    {
        $old = $this->order(1011);
        $oldShipmentId = (int) $old->shipment->id;
        $new = $this->order(1012);

        $run = app(SyncOrchestrationService::class)
            ->trackingAllPending(null, 50, $oldShipmentId);

        self::assertSame('tracking_v2_auto_discovery', $run->run_type);
        self::assertSame(1, $run->requested_items);
        self::assertSame(1, $run->succeeded_items);
        self::assertSame(1, $this->gateway->calls);
        $this->assertDatabaseHas('manual_syncs', [
            'order_id' => $old->id, 'website_sync_status' => 'pending',
        ]);
        $this->assertDatabaseHas('manual_syncs', [
            'order_id' => $new->id, 'website_sync_status' => 'completed',
        ]);
    }

    public function test_auto_discovery_requires_both_baselines_even_if_flag_is_enabled(): void
    {
        $order = $this->order(1013);
        config([
            'tracking_sync_v2.periodic_enabled' => true,
            'tracking_sync_v2.min_outbox_id' => '1',
            'tracking_sync_v2.min_shipment_id' => null,
        ]);
        $this->artisan('tracking:retry-woocommerce --apply')->assertSuccessful();
        self::assertSame(0, $this->gateway->calls);
        $this->assertDatabaseHas('manual_syncs', [
            'order_id' => $order->id, 'website_sync_status' => 'pending',
        ]);
    }

    public function test_editing_verified_tracking_reopens_woo_sync_but_repeated_same_number_does_not(): void
    {
        $order = $this->order(1014);
        app(ManualSyncService::class)->complete($order, null);
        $this->assertDatabaseHas('manual_syncs', [
            'order_id' => $order->id, 'website_sync_status' => 'completed',
        ]);

        $shipmentService = app(ShipmentService::class);
        $shipmentService->updateTracking($order, 'TRACK-1014', null);
        $this->assertDatabaseHas('manual_syncs', [
            'order_id' => $order->id, 'website_sync_status' => 'completed',
        ]);

        $shipmentService->updateTracking($order, 'TRACK-1014-CORRECTED', null);
        $this->assertDatabaseHas('manual_syncs', [
            'order_id' => $order->id, 'website_sync_status' => 'pending',
            'synced_at' => null,
        ]);
        self::assertSame(1, AuditRecord::query()
            ->where('action', 'manual_sync.reopened')->count());
    }

    public function test_auto_discovery_does_not_retry_same_shipment_when_outbox_is_pending(): void
    {
        $baseOrder = $this->order(1015);
        $baselineShipmentId = (int) $baseOrder->shipment->id;
        $order = $this->order(1016);
        $this->gateway->fail = true;
        $result = app(WooCommerceTrackingOutboundService::class)
            ->pushOne($order, null);
        self::assertSame('retry_scheduled', $result['status']);
        $due = app(SyncOrchestrationRepository::class)
            ->eligibleTrackingOrders(50, $baselineShipmentId);
        self::assertFalse($due->contains('id', $order->id));
        $this->assertDatabaseHas('manual_syncs', ['order_id' => $order->id, 'website_sync_status' => 'pending']);
    }

    public function test_list_projection_never_marks_stale_completed_outbox_as_current_tracking_success(): void
    {
        $service = app(LogisticsTrackingListStatus::class);
        $completed = ['status' => 'completed', 'reason' => null];
        self::assertSame('pending', $service->currentStatus($completed, 'pending')['status']);
        self::assertSame('pending', $service->currentStatus($completed, 'completed')['status']);
    }

    private function userWith(string $roleKey, array $permissions): User
    {
        $role = Role::create(['key' => $roleKey, 'label' => 'Tracking Operator']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user = User::factory()->create(['is_active' => true, 'permission_mode' => 'role']);
        $user->roles()->attach($role);

        return $user;
    }

    private function order(int $providerOrderId = 1009): Order
    {
        $order = Order::create([
            'internal_order_number' => 'TRACK-'.$providerOrderId,
            'source_type' => 'woocommerce',
            'order_kind' => 'customer',
            'external_order_number' => 'WC-'.$providerOrderId,
            'order_date' => '2026-10-28',
            'order_status' => 'shipped',
            'currency' => 'USD',
        ]);
        $order->externalReferences()->create([
            'provider' => 'woocommerce',
            'connection_key' => 'shop-a',
            'reference' => (string) $providerOrderId,
        ]);
        $order->items()->create([
            'woocommerce_line_item_id' => $providerOrderId + 1992,
            'product_summary' => 'Tracking fixture',
            'sku' => 'TRACK-PRODUCT',
            'quantity' => 1,
            'unit_price' => '12.00',
            'line_revenue' => '12.00',
        ]);
        $order->shipment()->create([
            'shipment_status' => 'shipped',
            'carrier' => 'DHL',
            'service' => 'Express',
            'tracking_number' => 'TRACK-'.$providerOrderId,
            'shipped_at' => '2026-10-28T12:00:00Z',
        ]);
        $order->manualSync()->create(['website_sync_status' => 'pending']);

        return $order->fresh(['shipment', 'items', 'externalReferences', 'manualSync']);
    }
}

final class TrackingPushFakeGateway implements WooCommerceTrackingBatchInspector, WooCommerceTrackingGateway
{
    public int $calls = 0;

    public int $statusCalls = 0;

    public int $writes = 0;

    private array $seen = [];

    public bool $fail = false;

    public bool $conflict = false;

    public bool $failStatus = false;

    public array $lastLineItems = [];

    public string $lastCarrierSlug = '';

    public int $lastProviderOrderId = 0;

    public string $remoteStatus = 'processing';

    public array $seenOrders = [];

    public array $batchSnapshots = [];

    public int $batchCalls = 0;

    public function descriptor(): array
    {
        return ['configured' => true, 'url' => 'https://shop.test', 'connection_key' => 'shop-a'];
    }

    public function inspectOrderStatus(int $providerOrderId): string
    {
        $this->statusCalls++;

        return $this->remoteStatus;
    }

    public function inspectTracking(int $providerOrderId): array
    {
        return ['tracking_number' => '', 'provider' => '', 'has_tracking' => false];
    }

    public function inspectTrackingBatch(array $providerOrderIds): array
    {
        $this->batchCalls++;
        $result = [];
        foreach ($providerOrderIds as $providerOrderId) {
            $result[(int) $providerOrderId] = $this->batchSnapshots[(int) $providerOrderId] ?? [
                'order_status' => $this->remoteStatus,
                'tracking_items' => [],
                'has_tracking' => false,
                'checked_at' => now()->toIso8601String(),
            ];
        }

        return $result;
    }

    public function syncTracking(int $providerOrderId, string $providerSlug, string $trackingNumber, string $shipDate, array $lineItems = []): array
    {
        $this->lastProviderOrderId = $providerOrderId;
        $this->lastCarrierSlug = $providerSlug;
        $this->lastLineItems = $lineItems;
        $this->calls++;
        if ($this->fail) {
            throw new \RuntimeException('Test-only transient Woo tracking gateway failure');
        }
        if ($this->conflict) {
            throw new WooCommerceTrackingConflictException('Existing Woo line belongs to another package');
        }
        $key = $providerOrderId.'|'.$providerSlug.'|'.$trackingNumber;
        $written = ! isset($this->seen[$key]);
        $this->seen[$key] = true;
        if ($written) {
            $this->writes++;
        }

        return ['written' => $written, 'verified' => true];
    }

    public function syncOrderStatus(int $providerOrderId, string $status): array
    {
        $this->statusCalls++;
        if ($this->failStatus) {
            throw new \RuntimeException('Local simulated Woo status write failure');
        }
        $this->remoteStatus = $status;

        return ['written' => true, 'verified' => true, 'status' => $status];
    }
}

final class TrackingPushFakeCustomerNoteGateway implements WooCommerceCustomerNoteGateway
{
    public bool $fail = false;

    public int $createdCount = 0;

    public int $lastWooOrder = 0;

    /** @var array<string,int> */
    private array $sent = [];

    public function notifyPackage(int $wooOrderId, string $carrier, string $tracking): array
    {
        if ($this->fail) {
            throw new \RuntimeException('Simulated Woo Customer Note unavailable');
        }
        $this->lastWooOrder = $wooOrderId;
        $key = $wooOrderId.'|'.$carrier.'|'.$tracking;
        if (isset($this->sent[$key])) {
            return ['created' => false, 'verified' => true, 'note_id' => $this->sent[$key]];
        }
        $this->sent[$key] = ++$this->createdCount;

        return ['created' => true, 'verified' => true, 'note_id' => $this->sent[$key]];
    }
}
