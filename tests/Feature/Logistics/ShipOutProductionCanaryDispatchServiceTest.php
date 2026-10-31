<?php

namespace Tests\Feature\Logistics;

use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\ShipOut\ShipOutProductionCanaryDispatchService;
use App\Modules\Logistics\Application\ShipOut\ShipOutProductionCanaryPreflight;
use App\Modules\Logistics\Application\ShipOut\ShipOutProductionCanaryReconcileService;
use App\Modules\Logistics\Infrastructure\Models\OverseaWarehouseReview;
use App\Modules\Logistics\Infrastructure\ShipOut\ShipOutProductionCanaryOutboundTransport;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Application\SkuMatchKey;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductSkuAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class ShipOutProductionCanaryDispatchServiceTest extends TestCase
{
    use RefreshDatabase;

    private mixed $oldEnvironment;

    private const WAREHOUSE = '1265864414750314497';

    private const ORG = '57893';

    private const REF = 'C99999-007';

    private const SKU = 'R10-VERIFIED-SKU';

    private int $postInvocations = 0;

    private ?string $providerVisibleOrderId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oldEnvironment = $this->app['env'];
        $this->app->instance('env', 'production');
        config()->set([
            'shipout.production_read_enabled' => true,
            'shipout.production_dispatch_enabled' => true,
            'shipout.production_canary_execute_enabled' => true,
            'shipout.production_canary_owner_approved' => true,
            'shipout.production_tracking_apply_enabled' => false,
            'shipout.production_warehouse_id' => self::WAREHOUSE,
            'shipout.production_client_org_id' => self::ORG,
            'shipout.production_canary_order_id' => '',
            'shipout.production_canary_order_no' => self::REF,
            'shipout.production_canary_expires_at_utc' => gmdate('Y-m-d\TH:i:s\Z', time() + 1200),
            'shipout.production_app_key' => 'synthetic-key',
            'shipout.production_app_secret' => 'synthetic-secret',
            'shipout.production_bearer' => 'synthetic-token',
            // Prove the Production first-canary mode does not inherit sandbox.
            'shipout.sandbox_enabled_modes' => '6',
        ]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->app->instance('env', $this->oldEnvironment);
        parent::tearDown();
    }

    private function order(string $warehouseCode = 'us-la', string $sku = self::SKU): Order
    {
        $wh = Warehouse::query()->updateOrCreate(['code' => 'US-LA'], [
            'slug' => 'us-la', 'name' => 'MorePlus',
            'type' => 'warehouse', 'is_active' => true, 'priority' => 10,
        ]);
        $wh->update(['code' => $warehouseCode]);
        $product = Product::query()->create([
            'sku' => $sku, 'name' => 'R10 Synthetic Product',
            'product_type' => 'simple', 'is_active' => true,
        ]);
        $o = Order::query()->create([
            'internal_order_number' => 'R10-SYNTHETIC',
            'erp_order_id' => '007-1031-c99999',
            'source_type' => 'manual_other',
            'order_kind' => 'customer',
            'external_order_number' => 'SYNTHETIC-SOURCE',
            'order_date' => '2026-10-31',
            'order_status' => 'pending_shipment',
        ]);
        $o->address()->create([
            'recipient_name' => 'SYNTHETIC TEST', 'phone' => '5550199',
            'email' => 'only@example.test', 'country' => 'US',
            'province_state' => 'CA', 'city' => 'Test City',
            'address_line_1' => '100 Example Way', 'postal_code' => '90210',
        ]);
        $o->items()->create([
            'product_id' => $product->id, 'sku' => $sku,
            'product_summary' => 'Synthetic Product', 'quantity' => 1,
            'unit_price' => '1.00', 'line_revenue' => '1.00',
        ]);
        $o->shipment()->create([
            'warehouse_id' => $wh->id, 'shipment_status' => 'pending',
            'warehouse_selection_source' => 'manual', 'carrier' => 'USPS',
        ]);
        $reviewer = User::factory()->create();
        OverseaWarehouseReview::query()->create([
            'order_id' => $o->id,
            'submitted_warehouse_id' => $wh->id,
            'reviewed_warehouse_id' => $wh->id,
            'status' => 'approved',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);
        config()->set('shipout.production_canary_order_id', (string) $o->id);

        return $o;
    }

    private function shipDate(): string
    {
        return now('America/Los_Angeles')->addDay()->format('Y-m-d');
    }

    private function fakeProvider(
        int $available = 3,
        string $sku = self::SKU,
        bool $duplicate = false,
        bool $unknown = false,
        string $postResult = 'ok',
        ?\Closure $onOrderGet = null,
        string $existingProviderId = 'ALREADY-EXISTS',
    ): void {
        Http::fake(function ($r) use ($available, $sku, $duplicate, $unknown, $postResult, $onOrderGet, $existingProviderId) {
            $url = $r->url();
            if (str_contains($url, '/warehouse/list')) {
                return Http::response([
                    'result' => 'OK', 'data' => [
                        ['warehouseId' => self::WAREHOUSE, 'warehouseName' => 'Test'],
                    ],
                ], 200);
            }
            if (str_contains($url, '/product/queryList')) {
                return Http::response([
                    'result' => 'OK',
                    'data' => ['total' => 1, 'records' => [['omsSku' => $sku, 'status' => 1]]],
                ], 200);
            }
            if (str_contains($url, '/stock/list')) {
                return Http::response([
                    'result' => 'OK', 'data' => [
                        'total' => 1, 'records' => [[
                            'omsSku' => $sku, 'warehouseId' => self::WAREHOUSE,
                            'availableQuantity' => $available,
                            'standardQuantity' => 999,
                        ]],
                    ],
                ], 200);
            }
            if (str_contains($url, '/order/queryListV2')) {
                if ($onOrderGet !== null) {
                    $onOrderGet();
                }

                return Http::response([
                    'result' => 'OK', 'data' => [
                        'total' => ($duplicate || $this->providerVisibleOrderId !== null) ? 1 : 0,
                        'records' => ($duplicate || $this->providerVisibleOrderId !== null) ? [[
                            'orderNO' => self::REF,
                            'orderId' => $this->providerVisibleOrderId ?? $existingProviderId,
                        ]] : [],
                    ],
                ], 200);
            }
            if ($r->method() === 'POST') {
                $this->postInvocations++;
                if ($unknown) {
                    throw new ConnectionException('SYNTHETIC_NETWORK_TIMEOUT');
                }
                if ($postResult === 'err') {
                    return Http::response(['result' => 'ERR', 'message' => 'SENSITIVE_PROVIDER_ERROR'], 200);
                }
                if ($postResult === 'malformed') {
                    return Http::response([['result' => 'OK', 'data' => ['sid' => 1, 'orderNO' => 'WRONG']]], 200);
                }

                return Http::response([[
                    'result' => 'OK',
                    'data' => [
                        'sid' => 1, 'orderNO' => self::REF,
                        'orderId' => 'SYNTHETIC-PROVIDER-ORDER',
                    ],
                ]], 200);
            }

            return Http::response(['result' => 'ERR'], 404);
        });
    }

    private function preflight(Order $order): array
    {
        return app(ShipOutProductionCanaryDispatchService::class)
            ->preview((int) $order->id, $this->shipDate());
    }

    private function dispatch(Order $order, string $hash): array
    {
        return app(ShipOutProductionCanaryDispatchService::class)->dispatchOne(
            (int) $order->id, $this->shipDate(), $hash,
            'SUBMIT_SHIPOUT_ORDER_'.$order->id
        );
    }

    public function test_r10_read_only_preflight_redacts_addresses_and_never_writes(): void
    {
        $order = $this->order();
        $this->fakeProvider();
        $result = $this->preflight($order);
        $this->assertTrue($result['ready']);
        $this->assertSame(self::REF, $result['order_no']);
        $this->assertSame('9', $result['shipping_mode']);
        $this->assertSame([['omsSku' => self::SKU, 'qty' => 1]], $result['items']);
        $this->assertSame(64, strlen($result['payload_hash']));
        $this->assertStringNotContainsString('SYNTHETIC TEST', json_encode($result));
        $this->assertStringNotContainsString('Example Way', json_encode($result));
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
        Http::assertSentCount(4);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_r10_single_signed_post_returns_async_ack_and_never_completes_erp(): void
    {
        $order = $this->order();
        $this->fakeProvider();
        $pre = $this->preflight($order);
        $ack = $this->dispatch($order, $pre['payload_hash']);
        $this->assertSame('acknowledged', $ack['status']);
        $this->assertSame('ASYNC_ACCEPTED_QUERY_REQUIRED', $ack['result_code']);
        $this->assertDatabaseHas('shipout_dispatch_attempts', [
            'order_id' => $order->id, 'status' => 'acknowledged',
            'provider_order_id' => 'SYNTHETIC-PROVIDER-ORDER',
        ]);
        $this->assertSame('pending_shipment', $order->fresh()->order_status->value);
        $this->assertSame('pending', $order->fresh()->shipment->shipment_status);
        $this->assertNull($order->fresh()->shipment->tracking_number);
        $this->assertFalse((bool) config('shipout.production_tracking_apply_enabled'));
        Http::assertSentCount(9);
        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && $r->url() === 'https://open.shipout.com/api/open-api/oms/order/batchSubmitAsync'
            && ($r->data()[0]['shipmentForms'][0]['outboundInfo']['sysServiceId'] ?? null) === '9'
            && ($r->data()[0]['shipmentForms'][0]['shippingInfo']['carrierId'] ?? null) === 9
            && count($r->data()) === 1);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'opendev.shipout.com'));
        try {
            $this->dispatch($order, $pre['payload_hash']);
            $this->fail('Duplicate production POST must never occur');
        } catch (RuntimeException $e) {
            $this->assertSame('SHIPOUT_R10_PRIOR_ATTEMPT_BLOCKED', $e->getMessage());
        }
        Http::assertSentCount(9);
    }

    public function test_r10_wrong_expected_payload_hash_never_inserts_attempt_or_posts(): void
    {
        $order = $this->order();
        $this->fakeProvider();
        try {
            $this->dispatch($order, str_repeat('a', 64));
            $this->fail('Stale preflight hash must reject');
        } catch (RuntimeException $e) {
            $this->assertSame('SHIPOUT_R10_PREFLIGHT_STALE', $e->getMessage());
        }
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_r10_missing_available_stock_blocks_without_post(): void
    {
        $order = $this->order();
        $this->fakeProvider(0);
        try {
            $this->preflight($order);
            $this->fail('No physical stock must block');
        } catch (RuntimeException $e) {
            $this->assertSame('SHIPOUT_R10_PHYSICAL_SKU_NOT_AVAILABLE', $e->getMessage());
        }
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
    }

    public function test_r10_wrong_physical_sku_blocks_without_post(): void
    {
        $order = $this->order();
        $this->fakeProvider(7, 'DIFFERENT-PHYSICAL-SKU');
        try {
            $this->preflight($order);
            $this->fail('Unverified physical SKU must block');
        } catch (RuntimeException $e) {
            $this->assertSame('SHIPOUT_R10_PHYSICAL_SKU_NOT_AVAILABLE', $e->getMessage());
        }
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
    }

    public function test_r10_verified_different_color_or_model_alias_is_never_substituted(): void
    {
        $order = $this->order('us-la', 'VA617A-PINK');
        $pid = (int) $order->items->first()->product_id;
        $alias = ProductSkuAlias::query()->create([
            'product_id' => $pid,
            'alias_sku' => 'VA617A',
            'match_key' => SkuMatchKey::compute('VA617A'),
            'source_type' => 'manual',
        ]);
        $alias->forceFill([
            'is_active' => true,
            'oms_verified_at' => now(),
            'oms_identity_evidence' => 'SYNTHETIC R10 TEST EVIDENCE',
        ])->save();
        $this->fakeProvider(98, 'VA617A');
        try {
            $this->preflight($order);
            $this->fail('Pink color must never be fulfilled from generic stock');
        } catch (RuntimeException $e) {
            $this->assertSame('SHIPOUT_R10_PHYSICAL_SKU_NOT_AVAILABLE', $e->getMessage());
        }
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
    }

    public function test_r10_exact_model_with_case_only_variation_is_accepted(): void
    {
        $order = $this->order('us-la', 'VA617A-PINK');
        $this->fakeProvider(2, 'VA617A-Pink');
        $pre = $this->preflight($order);
        $this->assertSame([['omsSku' => 'VA617A-Pink', 'qty' => 1]], $pre['items']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
    }

    public function test_r10_invalid_shipping_date_fails_before_all_network_calls(): void
    {
        $order = $this->order();
        $this->fakeProvider();
        foreach (['1900-01-01', '2999-01-01', 'INVALID', '2026-02-31'] as $date) {
            try {
                $this->preflightPreviewForDate($order, $date);
                $this->fail('Invalid requested shipping date must fail closed');
            } catch (RuntimeException $e) {
                $this->assertSame('SHIPOUT_R10_SHIP_DATE_INVALID', $e->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    private function preflightPreviewForDate(Order $order, string $date): array
    {
        return app(ShipOutProductionCanaryDispatchService::class)->preview((int) $order->id, $date);
    }

    public function test_r10_reconcile_after_async_ack_never_claims_shipped(): void
    {
        $order = $this->order();
        $this->fakeProvider();
        $pre = $this->preflight($order);
        $this->dispatch($order, $pre['payload_hash']);
        $reconciliation = app(ShipOutProductionCanaryReconcileService::class);
        $pending = $reconciliation->inspect((int) $order->id);
        $this->assertSame('NOT_YET_VISIBLE_MANUAL_REVIEW', $pending['reconciliation']);
        $this->assertFalse($pending['shipped']);
        $this->assertFalse($pending['tracking_apply']);

        $this->providerVisibleOrderId = 'SYNTHETIC-PROVIDER-ORDER';
        $visible = $reconciliation->inspect((int) $order->id);
        $this->assertSame('ORDER_VISIBLE_MANUAL_REVIEW_ONLY', $visible['reconciliation']);
        $this->assertSame('SYNTHETIC-PROVIDER-ORDER', $visible['provider_order_id']);
        $this->assertFalse($visible['shipped']);
        $this->assertFalse($visible['tracking_apply']);
        $this->assertNull($order->fresh()->shipment->tracking_number);
        $this->assertSame(1, $this->postInvocations);
    }

    public function test_r10_reconcile_provider_order_id_mismatch_fails_closed(): void
    {
        $order = $this->order();
        $this->fakeProvider();
        $pre = $this->preflight($order);
        $this->dispatch($order, $pre['payload_hash']);
        $this->providerVisibleOrderId = 'WRONG-PROVIDER-ID';
        try {
            app(ShipOutProductionCanaryReconcileService::class)->inspect((int) $order->id);
            $this->fail('Provider ID mismatch must not be considered verified');
        } catch (RuntimeException $e) {
            $this->assertSame('SHIPOUT_R10_PROVIDER_ORDER_IDENTITY_MISMATCH', $e->getMessage());
        }
        $this->assertNull($order->fresh()->shipment->tracking_number);
        $this->assertSame(1, $this->postInvocations);
    }

    public function test_r10_reconcile_without_ledger_cannot_query_provider(): void
    {
        $order = $this->order();
        $this->fakeProvider();
        try {
            app(ShipOutProductionCanaryReconcileService::class)->inspect((int) $order->id);
            $this->fail('Missing attempt must be rejected');
        } catch (RuntimeException $e) {
            $this->assertSame('SHIPOUT_R10_NO_VERIFIED_ATTEMPT', $e->getMessage());
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
    }

    public function test_r10_provider_duplicate_blocks_before_attempt_insert(): void
    {
        $order = $this->order();
        $this->fakeProvider(3, self::SKU, true);
        try {
            $this->preflight($order);
            $this->fail('Existing provider order must fail');
        } catch (RuntimeException $e) {
            $this->assertSame('SHIPOUT_R10_PROVIDER_ORDER_EXISTS', $e->getMessage());
        }
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_r10_owner_flag_off_blocks_post_even_if_live_read_is_enabled(): void
    {
        $order = $this->order();
        $this->fakeProvider();
        config()->set('shipout.production_canary_owner_approved', false);
        $pre = $this->preflight($order);
        try {
            $this->dispatch($order, $pre['payload_hash']);
            $this->fail('Owner gate OFF must block');
        } catch (RuntimeException $e) {
            $this->assertSame('SHIPOUT_R10_CANARY_DISABLED', $e->getMessage());
        }
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_r10_production_read_flag_off_blocks_all_api_calls(): void
    {
        $order = $this->order();
        config()->set('shipout.production_read_enabled', false);
        $this->fakeProvider();
        $this->expectExceptionMessage('SHIPOUT_R10_PRODUCTION_READ_DISABLED');
        try {
            $this->preflight($order);
        } finally {
            Http::assertNothingSent();
            $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
        }
    }

    public function test_r10_attempt_ledger_from_prior_failure_is_irretryable(): void
    {
        $order = $this->order();
        DB::table('shipout_dispatch_attempts')->insert([
            'order_id' => $order->id,
            'order_no' => self::REF,
            'provider_warehouse_id' => self::WAREHOUSE,
            'payload_hash' => str_repeat('a', 64),
            'status' => 'uncertain',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->fakeProvider();
        $this->expectExceptionMessage('SHIPOUT_R10_PRIOR_ATTEMPT_BLOCKED');
        try {
            $this->preflight($order);
        } finally {
            Http::assertNothingSent();
            $this->assertDatabaseCount('shipout_dispatch_attempts', 1);
        }
    }

    public function test_r10_network_timeout_retains_uncertain_ledger_and_blocks_retry(): void
    {
        $order = $this->order();
        $this->fakeProvider(3, self::SKU, false, true);
        $pre = $this->preflight($order);
        try {
            $this->dispatch($order, $pre['payload_hash']);
            $this->fail('Timeout must not mean successful outbound');
        } catch (RuntimeException $e) {
            $this->assertSame('SHIPOUT_R10_SUBMISSION_OUTCOME_UNKNOWN', $e->getMessage());
        }
        $this->assertDatabaseHas('shipout_dispatch_attempts', [
            'order_id' => $order->id,
            'status' => 'uncertain',
            'result_code' => 'MANUAL_PROVIDER_RECONCILIATION_REQUIRED',
        ]);
        $this->assertNull($order->fresh()->shipment->tracking_number);
        // A mock exception may not appear in Laravel's recorded responses.
        $this->assertSame(1, $this->postInvocations);
        try {
            $this->dispatch($order, $pre['payload_hash']);
            $this->fail('Timeout must not be retried');
        } catch (RuntimeException $e) {
            $this->assertSame('SHIPOUT_R10_PRIOR_ATTEMPT_BLOCKED', $e->getMessage());
        }
        // A mock exception may not appear in Laravel's recorded responses.
        $this->assertSame(1, $this->postInvocations);
    }

    public function test_r10_provider_explicit_validation_rejection_is_not_shipped_or_retried(): void
    {
        $order = $this->order();
        $this->fakeProvider(3, self::SKU, false, false, 'err');
        $pre = $this->preflight($order);
        $result = $this->dispatch($order, $pre['payload_hash']);
        $this->assertSame('rejected', $result['status']);
        $this->assertSame('PROVIDER_VALIDATION_REJECTED', $result['result_code']);
        $this->assertDatabaseHas('shipout_dispatch_attempts', [
            'order_id' => $order->id, 'status' => 'rejected',
        ]);
        $this->assertNull($order->fresh()->shipment->tracking_number);
        $this->assertSame(1, $this->postInvocations);
        try {
            $this->dispatch($order, $pre['payload_hash']);
            $this->fail('A rejected submit must not be automatically retried');
        } catch (RuntimeException $e) {
            $this->assertSame('SHIPOUT_R10_PRIOR_ATTEMPT_BLOCKED', $e->getMessage());
        }
        $this->assertSame(1, $this->postInvocations);
    }

    public function test_r10_malformed_async_ack_is_uncertain_and_never_retried(): void
    {
        $order = $this->order();
        $this->fakeProvider(3, self::SKU, false, false, 'malformed');
        $pre = $this->preflight($order);
        try {
            $this->dispatch($order, $pre['payload_hash']);
            $this->fail('Malformed provider response must not pass');
        } catch (RuntimeException $e) {
            $this->assertSame('SHIPOUT_R10_SUBMISSION_OUTCOME_UNKNOWN', $e->getMessage());
        }
        $this->assertDatabaseHas('shipout_dispatch_attempts', [
            'order_id' => $order->id,
            'status' => 'uncertain',
            'result_code' => 'MANUAL_PROVIDER_RECONCILIATION_REQUIRED',
        ]);
        $this->assertSame(1, $this->postInvocations);
    }

    public function test_r10_concurrent_ledger_reservation_wins_before_any_post(): void
    {
        $order = $this->order();
        $this->fakeProvider();
        $pre = $this->preflight($order);
        $inserted = false;
        $this->fakeProvider(3, self::SKU, false, false, 'ok', function () use ($order, &$inserted): void {
            if ($inserted) {
                return;
            }
            $inserted = true;
            DB::table('shipout_dispatch_attempts')->insert([
                'order_id' => $order->id,
                'order_no' => self::REF,
                'provider_warehouse_id' => self::WAREHOUSE,
                'payload_hash' => str_repeat('a', 64),
                'status' => 'uncertain',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        try {
            $this->dispatch($order, $pre['payload_hash']);
            $this->fail('Concurrent attempt must win and block this process');
        } catch (RuntimeException $e) {
            $this->assertSame('SHIPOUT_R10_DUPLICATE_ATTEMPT_BLOCKED', $e->getMessage());
        }
        $this->assertTrue($inserted);
        $this->assertSame(0, $this->postInvocations);
        $this->assertDatabaseCount('shipout_dispatch_attempts', 1);
    }

    public function test_r10_raw_transport_does_not_accept_mode_six_label_payload(): void
    {
        $order = $this->order();
        $this->fakeProvider();
        $pre = app(ShipOutProductionCanaryPreflight::class)->build((int) $order->id, $this->shipDate());
        $pre['payload'][0]['shipmentForms'][0]['outboundInfo']['sysServiceId'] = '6';
        try {
            app(ShipOutProductionCanaryOutboundTransport::class)->submitOne(
                (int) $order->id, $pre['payload'], $pre['summary']['payload_hash'],
                'SUBMIT_SHIPOUT_ORDER_'.$order->id
            );
            $this->fail('Non-mode-9 payload must be rejected');
        } catch (RuntimeException $e) {
            $this->assertSame('SHIPOUT_R10_TRANSPORT_PAYLOAD_BLOCKED', $e->getMessage());
        }
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
    }

    public function test_r10_cross_warehouse_blocks_before_live_stock_api(): void
    {
        $order = $this->order('us-ny');
        $this->fakeProvider();
        try {
            $this->preflight($order);
            $this->fail('Other warehouse cannot use MorePlus');
        } catch (RuntimeException $e) {
            $this->assertSame('SHIPOUT_ERP_APPROVAL_OR_WAREHOUSE_BLOCKED', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_r10_transport_cannot_post_without_pre_reserved_attempt(): void
    {
        $order = $this->order();
        $this->fakeProvider();
        $built = app(ShipOutProductionCanaryPreflight::class)
            ->build((int) $order->id, $this->shipDate());
        try {
            app(ShipOutProductionCanaryOutboundTransport::class)->submitOne(
                (int) $order->id,
                $built['payload'],
                $built['summary']['payload_hash'],
                'SUBMIT_SHIPOUT_ORDER_'.$order->id,
            );
            $this->fail('Transport requires atomic attempt reservation');
        } catch (RuntimeException $e) {
            $this->assertSame('SHIPOUT_R10_POST_ALREADY_CLAIMED', $e->getMessage());
        }
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
    }
}
