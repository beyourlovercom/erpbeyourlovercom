<?php

namespace Tests\Feature\Logistics;

use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\ShipOut\ShipOutProductionCanaryDispatchService;
use App\Modules\Logistics\Application\ShipOut\ShipOutProductionCanaryPreflight;
use App\Modules\Logistics\Application\ShipOut\ShipOutProductionCanaryReconcileService;
use App\Modules\Logistics\Application\ShipOut\ShipOutProductionV1OperatorAccess;
use App\Modules\Logistics\Experience\Jobs\ProcessShipOutProductionBatch;
use App\Modules\Logistics\Experience\Jobs\VerifyShipOutProductionBatchItem;
use App\Modules\Logistics\Infrastructure\Models\OverseaWarehouseReview;
use App\Modules\Logistics\Infrastructure\ShipOut\ShipOutProductionCanaryOutboundTransport;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Application\SkuMatchKey;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductSkuAlias;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
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
        string|array $sku = self::SKU,
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
                $skus = (array) $sku;

                return Http::response([
                    'result' => 'OK',
                    'data' => ['total' => count($skus), 'records' => array_map(
                        fn (string $one) => ['omsSku' => $one, 'status' => 1], $skus
                    )],
                ], 200);
            }
            if (str_contains($url, '/stock/list')) {
                $skus = (array) $sku;

                return Http::response([
                    'result' => 'OK', 'data' => [
                        'total' => count($skus), 'records' => array_map(fn (string $one) => [
                            'omsSku' => $one, 'warehouseId' => self::WAREHOUSE,
                            'availableQuantity' => $available, 'standardQuantity' => 999,
                        ], $skus),
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

    public function test_owner_approved_verified_moreplus_alias_maps_to_provider_sku_read_only(): void
    {
        $order = $this->order('us-la', 'BY489A-Lubes');
        $pid = (int) $order->items->first()->product_id;
        $alias = ProductSkuAlias::query()->create([
            'product_id' => $pid,
            'alias_sku' => 'BY489A',
            'match_key' => SkuMatchKey::compute('BY489A'),
            'source_type' => 'manual',
        ]);
        $alias->forceFill([
            'is_active' => true,
            'oms_verified_at' => now(),
            'oms_identity_evidence' => 'Synthetic verified identical physical lubricant SKU',
        ])->save();
        $this->fakeProvider(8, 'BY489A');

        // A verified alias alone must not change an outbound payload.
        try {
            $this->preflight($order);
            $this->fail('Without explicit pair approval alias substitution must block');
        } catch (RuntimeException $e) {
            $this->assertSame('SHIPOUT_R10_PHYSICAL_SKU_NOT_AVAILABLE', $e->getMessage());
        }

        config()->set('shipout.production_verified_alias_pairs', 'BY489A-Lubes=>BY489A');
        $pre = $this->preflight($order);
        $this->assertSame([['omsSku' => 'BY489A', 'qty' => 1]], $pre['items']);
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_future_verified_product_alias_maps_without_a_new_env_allowlist(): void
    {
        $order = $this->order('us-la', 'NEW-PRODUCT-ERP');
        $pid = (int) $order->items->first()->product_id;
        $alias = ProductSkuAlias::query()->create([
            'product_id' => $pid,
            'alias_sku' => 'NEW-PRODUCT-OMS',
            'match_key' => SkuMatchKey::compute('NEW-PRODUCT-OMS'),
            'source_type' => 'manual',
        ]);
        $alias->forceFill([
            'is_active' => true,
            'oms_verified_at' => now(),
            'oms_identity_evidence' => 'Manually checked identical model, color, size and packaging',
            'oms_dispatch_approved_at' => now(),
            'oms_dispatch_canonical_sku' => 'NEW-PRODUCT-ERP',
        ])->save();
        $this->fakeProvider(8, 'NEW-PRODUCT-OMS');
        config()->set('shipout.production_verified_alias_pairs', '');
        $pre = $this->preflight($order);
        $this->assertSame([['omsSku' => 'NEW-PRODUCT-OMS', 'qty' => 1]], $pre['items']);
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_future_multi_sku_order_maps_each_verified_product_independently(): void
    {
        $order = $this->order('us-la', 'FIRST-ERP');
        $firstId = (int) $order->items->first()->product_id;
        $second = Product::query()->create([
            'sku' => 'SECOND-ERP', 'name' => 'Second synthetic product',
            'product_type' => 'simple', 'is_active' => true,
        ]);
        $order->items()->create([
            'product_id' => $second->id, 'sku' => 'SECOND-ERP',
            'product_summary' => 'Second synthetic product', 'quantity' => 2,
            'unit_price' => '1.00', 'line_revenue' => '2.00',
        ]);
        foreach ([
            [$firstId, 'FIRST-ERP', 'FIRST-OMS'],
            [$second->id, 'SECOND-ERP', 'SECOND-OMS'],
        ] as [$pid, $erp, $oms]) {
            $alias = ProductSkuAlias::query()->create([
                'product_id' => $pid, 'alias_sku' => $oms,
                'match_key' => SkuMatchKey::compute($oms), 'source_type' => 'manual',
            ]);
            $alias->forceFill([
                'is_active' => true, 'oms_verified_at' => now(),
                'oms_identity_evidence' => 'Warehouse verified identical model, color and packaging',
                'oms_dispatch_approved_at' => now(),
                'oms_dispatch_canonical_sku' => $erp,
            ])->save();
        }
        config()->set('shipout.production_verified_alias_pairs', '');
        $this->fakeProvider(8, ['FIRST-OMS', 'SECOND-OMS']);
        $pre = $this->preflight($order->fresh());
        $this->assertSame([
            ['omsSku' => 'FIRST-OMS', 'qty' => 1],
            ['omsSku' => 'SECOND-OMS', 'qty' => 2],
        ], $pre['items']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
    }

    public function test_future_product_sku_change_revokes_original_alias_dispatch(): void
    {
        $order = $this->order('us-la', 'NEW-ERP-MODEL');
        $pid = (int) $order->items->first()->product_id;
        $alias = ProductSkuAlias::query()->create([
            'product_id' => $pid, 'alias_sku' => 'NEW-OMS-MODEL',
            'match_key' => SkuMatchKey::compute('NEW-OMS-MODEL'),
            'source_type' => 'manual',
        ]);
        $alias->forceFill([
            'is_active' => true, 'oms_verified_at' => now(),
            'oms_identity_evidence' => 'Same physical SKU confirmed before canonical rename',
            'oms_dispatch_approved_at' => now(),
            'oms_dispatch_canonical_sku' => 'OLD-ERP-MODEL',
        ])->save();
        $this->fakeProvider(8, 'NEW-OMS-MODEL');
        config()->set('shipout.production_verified_alias_pairs', '');
        try {
            $this->preflight($order);
            $this->fail('Stale canonical SKU approval must never dispatch');
        } catch (RuntimeException $e) {
            $this->assertSame('SHIPOUT_R10_PHYSICAL_SKU_NOT_AVAILABLE', $e->getMessage());
        }
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
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

    public function test_r10_reconcile_without_ledger_may_read_provider_without_post(): void
    {
        $order = $this->order();
        $this->fakeProvider();
        $result = app(ShipOutProductionCanaryReconcileService::class)->inspect((int) $order->id);
        $this->assertSame('never_submitted', $result['attempt_status']);
        $this->assertSame('NO_ERP_ATTEMPT_AND_NO_OMS_ORDER', $result['reconciliation']);
        $this->assertFalse($result['tracking_apply']);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
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

    public function test_operator_cli_apply_stays_disabled_without_its_own_release_gate(): void
    {
        $order = $this->order();
        $this->fakeProvider();

        $this->artisan('shipout:production-canary', [
            'order-id' => (string) $order->id,
            '--ship-date' => $this->shipDate(),
            '--apply' => true,
            '--payload-hash' => str_repeat('a', 64),
            '--confirm' => 'SUBMIT_SHIPOUT_ORDER_'.$order->id,
        ])->assertExitCode(1);

        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_operator_cli_preview_is_get_only_and_does_not_reserve_an_attempt(): void
    {
        $order = $this->order();
        $this->fakeProvider();

        $this->artisan('shipout:production-canary', [
            'order-id' => (string) $order->id,
            '--ship-date' => $this->shipDate(),
        ])->assertExitCode(0);

        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
        Http::assertSentCount(4);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_operator_cli_apply_requires_exact_confirm_and_hash_before_network(): void
    {
        $order = $this->order();
        $this->fakeProvider();
        config()->set('shipout.production_operator_cli_enabled', true);

        $this->artisan('shipout:production-canary', [
            'order-id' => (string) $order->id,
            '--ship-date' => $this->shipDate(),
            '--apply' => true,
            '--payload-hash' => str_repeat('a', 64),
            '--confirm' => 'WRONG_CONFIRMATION',
        ])->assertExitCode(1);

        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_operator_cli_simulates_one_shot_acceptance_but_never_sets_shipped(): void
    {
        $order = $this->order();
        $this->fakeProvider();
        $preview = $this->preflight($order);
        config()->set('shipout.production_operator_cli_enabled', true);

        $args = [
            'order-id' => (string) $order->id,
            '--ship-date' => $this->shipDate(),
            '--apply' => true,
            '--payload-hash' => $preview['payload_hash'],
            '--confirm' => 'SUBMIT_SHIPOUT_ORDER_'.$order->id,
        ];
        $this->artisan('shipout:production-canary', $args)->assertExitCode(0);

        $this->assertDatabaseHas('shipout_dispatch_attempts', [
            'order_id' => $order->id,
            'status' => 'acknowledged',
            'provider_order_id' => 'SYNTHETIC-PROVIDER-ORDER',
        ]);
        $this->assertSame('pending_shipment', $order->fresh()->order_status->value);
        $this->assertSame('pending', $order->fresh()->shipment->shipment_status);
        $this->assertNull($order->fresh()->shipment->tracking_number);
        $this->assertSame(1, collect(Http::recorded())->filter(
            fn ($pair) => $pair[0]->method() === 'POST'
        )->count());

        $this->artisan('shipout:production-canary', $args)->assertExitCode(1);
        $this->assertSame(1, collect(Http::recorded())->filter(
            fn ($pair) => $pair[0]->method() === 'POST'
        )->count());
    }

    public function test_operator_cli_preview_is_blocked_if_production_read_is_off(): void
    {
        $order = $this->order();
        $this->fakeProvider();
        config()->set('shipout.production_read_enabled', false);

        $this->artisan('shipout:production-canary', [
            'order-id' => (string) $order->id,
            '--ship-date' => $this->shipDate(),
        ])->assertExitCode(1);

        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_operator_cli_provider_rejection_is_failure_without_retry(): void
    {
        $order = $this->order();
        $this->fakeProvider(3, self::SKU, false, false, 'err');
        $preview = $this->preflight($order);
        config()->set('shipout.production_operator_cli_enabled', true);

        $args = [
            'order-id' => (string) $order->id,
            '--ship-date' => $this->shipDate(),
            '--apply' => true,
            '--payload-hash' => $preview['payload_hash'],
            '--confirm' => 'SUBMIT_SHIPOUT_ORDER_'.$order->id,
        ];
        $this->artisan('shipout:production-canary', $args)->assertExitCode(1);
        $this->assertDatabaseHas('shipout_dispatch_attempts', [
            'order_id' => $order->id,
            'status' => 'rejected',
            'result_code' => 'PROVIDER_VALIDATION_REJECTED',
        ]);
        $this->assertSame(1, collect(Http::recorded())->filter(
            fn ($pair) => $pair[0]->method() === 'POST'
        )->count());

        $this->artisan('shipout:production-canary', $args)->assertExitCode(1);
        $this->assertSame(1, collect(Http::recorded())->filter(
            fn ($pair) => $pair[0]->method() === 'POST'
        )->count());
    }

    private function enableProductionV1(): void
    {
        config()->set([
            'shipout.production_v1_read_enabled' => true,
            'shipout.production_v1_dispatch_enabled' => true,
            'shipout.production_v1_owner_approved' => true,
            'shipout.production_v1_min_order_id' => 1,
            'shipout.production_canary_order_id' => '',
            'shipout.production_canary_order_no' => '',
            'shipout.production_canary_execute_enabled' => false,
            'shipout.production_canary_owner_approved' => false,
        ]);
        $operator = $this->productionWarehouseUser(['logistics.view', 'logistics.operate']);
        $this->actingAs($operator);
        $andy = $this->productionWarehouseUser(['logistics.view', 'logistics.operate']);
        $vita = $this->productionWarehouseUser(['logistics.view']);
        config()->set('shipout.production_v1_operator_user_ids', implode(',', [
            $operator->id, $andy->id, $vita->id,
        ]));
    }

    public function test_v1_new_order_read_gate_is_default_off_and_never_contacts_provider(): void
    {
        $order = $this->order();
        $this->fakeProvider();
        $this->assertFalse((bool) config('shipout.production_v1_read_enabled', false));

        try {
            app(ShipOutProductionCanaryDispatchService::class)->previewApproved(
                $order->id, $this->shipDate()
            );
            $this->fail('V1 must be OFF without separate launch approval');
        } catch (RuntimeException $e) {
            $this->assertSame('SHIPOUT_V1_READ_DISABLED', $e->getMessage());
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
    }

    public function test_v1_uses_fresh_signed_stock_preview_for_approved_order_without_canary_allowlist(): void
    {
        $order = $this->order();
        $this->enableProductionV1();
        $this->fakeProvider();
        $preview = app(ShipOutProductionCanaryDispatchService::class)->previewApproved(
            $order->id, $this->shipDate()
        );
        $this->assertTrue($preview['ready']);
        $this->assertSame(self::REF, $preview['order_no']);
        $this->assertSame([['omsSku' => self::SKU, 'qty' => 1]], $preview['items']);
        $this->assertSame(64, strlen($preview['payload_hash']));
        $this->assertStringNotContainsString('Example Way', json_encode($preview));
        Http::assertSentCount(4);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
    }

    public function test_v1_real_transport_is_reused_in_fake_environment_and_never_marks_shipped(): void
    {
        $order = $this->order();
        $this->enableProductionV1();
        $this->fakeProvider();
        $service = app(ShipOutProductionCanaryDispatchService::class);
        $pre = $service->previewApproved($order->id, $this->shipDate());
        $result = $service->dispatchApprovedOrder(
            $order->id, $this->shipDate(), $pre['payload_hash'],
            'SUBMIT_SHIPOUT_ORDER_'.$order->id,
        );
        $this->assertSame('acknowledged', $result['status']);
        $this->assertSame('SYNTHETIC-PROVIDER-ORDER', $result['provider_order_id']);
        $this->assertDatabaseHas('shipout_dispatch_attempts', [
            'order_id' => $order->id, 'status' => 'acknowledged',
        ]);
        $this->assertSame('pending_shipment', $order->fresh()->order_status->value);
        $this->assertSame('pending', $order->fresh()->shipment->shipment_status);
        $this->assertNull($order->fresh()->shipment->tracking_number);
        $this->assertSame(1, collect(Http::recorded())->filter(
            fn ($pair) => $pair[0]->method() === 'POST'
        )->count());
        try {
            $service->dispatchApprovedOrder(
                $order->id, $this->shipDate(), $pre['payload_hash'],
                'SUBMIT_SHIPOUT_ORDER_'.$order->id,
            );
            $this->fail('V1 must not send twice');
        } catch (RuntimeException $error) {
            $this->assertSame('SHIPOUT_R10_PRIOR_ATTEMPT_BLOCKED', $error->getMessage());
        }
        $this->assertSame(1, collect(Http::recorded())->filter(
            fn ($pair) => $pair[0]->method() === 'POST'
        )->count());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'opendev.shipout.com'));
    }

    public function test_v1_write_gate_off_prevents_any_real_post(): void
    {
        $order = $this->order();
        $this->enableProductionV1();
        config()->set('shipout.production_v1_dispatch_enabled', false);
        $this->fakeProvider();
        $service = app(ShipOutProductionCanaryDispatchService::class);
        $pre = $service->previewApproved($order->id, $this->shipDate());
        try {
            $service->dispatchApprovedOrder(
                $order->id, $this->shipDate(), $pre['payload_hash'],
                'SUBMIT_SHIPOUT_ORDER_'.$order->id,
            );
            $this->fail('V1 write must remain independently disabled');
        } catch (RuntimeException $e) {
            $this->assertSame('SHIPOUT_V1_DISPATCH_DISABLED', $e->getMessage());
        }
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_v1_stale_hash_never_reserves_an_attempt(): void
    {
        $order = $this->order();
        $this->enableProductionV1();
        $this->fakeProvider();
        try {
            app(ShipOutProductionCanaryDispatchService::class)->dispatchApprovedOrder(
                $order->id, $this->shipDate(), str_repeat('b', 64),
                'SUBMIT_SHIPOUT_ORDER_'.$order->id,
            );
            $this->fail('V1 must reject an outdated hash');
        } catch (RuntimeException $e) {
            $this->assertSame('SHIPOUT_R10_PREFLIGHT_STALE', $e->getMessage());
        }
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_v1_dede_review_and_exact_sku_and_stock_still_required(): void
    {
        $order = $this->order('us-ny');
        $this->enableProductionV1();
        $this->fakeProvider();
        try {
            app(ShipOutProductionCanaryDispatchService::class)->previewApproved(
                $order->id, $this->shipDate()
            );
            $this->fail('Wrong warehouse must block');
        } catch (RuntimeException $e) {
            $this->assertSame('SHIPOUT_ERP_APPROVAL_OR_WAREHOUSE_BLOCKED', $e->getMessage());
        }
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_v1_timeout_keeps_unique_attempt_and_forbids_retry(): void
    {
        $order = $this->order();
        $this->enableProductionV1();
        $this->fakeProvider(3, self::SKU, false, true);
        $service = app(ShipOutProductionCanaryDispatchService::class);
        $pre = $service->previewApproved($order->id, $this->shipDate());
        try {
            $service->dispatchApprovedOrder(
                $order->id, $this->shipDate(), $pre['payload_hash'],
                'SUBMIT_SHIPOUT_ORDER_'.$order->id,
            );
            $this->fail('Synthetic timeout must stay uncertain');
        } catch (RuntimeException $e) {
            $this->assertSame('SHIPOUT_R10_SUBMISSION_OUTCOME_UNKNOWN', $e->getMessage());
        }
        $this->assertDatabaseHas('shipout_dispatch_attempts', [
            'order_id' => $order->id, 'status' => 'uncertain',
        ]);
        $this->assertSame(1, $this->postInvocations);
        try {
            $service->dispatchApprovedOrder(
                $order->id, $this->shipDate(), $pre['payload_hash'],
                'SUBMIT_SHIPOUT_ORDER_'.$order->id,
            );
            $this->fail('Unknown POST must not retry');
        } catch (RuntimeException $e) {
            $this->assertSame('SHIPOUT_R10_PRIOR_ATTEMPT_BLOCKED', $e->getMessage());
        }
        $this->assertSame(1, $this->postInvocations);
    }

    /** Testing Production environment sets CSRF checks ON; only the synthetic
     * HTTP fixture opts out of CSRF to isolate route authorization logic.
     * Production web middleware and browser CSRF remain fully enforced.
     */
    private function allowSyntheticHttpPost(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    private function productionWarehouseUser(array $keys): User
    {
        $role = Role::query()->create([
            'key' => 'shipout-production-v1-'.uniqid(), 'label' => 'V1 Synthetic Logistics',
        ]);
        foreach ($keys as $key) {
            $role->permissions()->firstOrCreate(['key' => $key], ['label' => $key]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    public function test_v1_production_route_denies_preview_when_dark_and_leaks_no_customer_data(): void
    {
        $order = $this->order();
        $this->actingAs($this->productionWarehouseUser(['logistics.view']));
        Http::fake();

        $this->getJson("/logistics/oversea/us-moreplus/orders/{$order->id}/shipout/production/preflight")
            ->assertStatus(423)
            ->assertJsonPath('error_code', 'SHIPOUT_V1_READ_DISABLED');

        Http::assertNothingSent();
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
    }

    public function test_v1_production_route_preflight_is_get_only_and_redacted(): void
    {
        $order = $this->order();
        $this->enableProductionV1();
        $this->fakeProvider();
        $this->actingAs($this->productionWarehouseUser(['logistics.view']));

        $response = $this->getJson("/logistics/oversea/us-moreplus/orders/{$order->id}/shipout/production/preflight")
            ->assertOk()
            ->assertJsonPath('ready', true)
            ->assertJsonPath('production', true)
            ->assertJsonPath('order_no', self::REF)
            ->assertJsonPath('items.0.omsSku', self::SKU)
            ->assertJsonPath('mutated', false);

        $this->assertStringNotContainsString('Example Way', $response->getContent());
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_v1_production_send_route_denies_non_operator(): void
    {
        $order = $this->order();
        $this->allowSyntheticHttpPost();
        $this->enableProductionV1();
        $this->fakeProvider();
        $this->actingAs($this->productionWarehouseUser(['logistics.view']));

        $this->postJson("/logistics/oversea/us-moreplus/orders/{$order->id}/shipout/production/send", [
            'ship_date' => $this->shipDate(),
            'shipping_mode' => '9',
            'payload_hash' => str_repeat('a', 64),
            'confirmation' => 'SUBMIT_SHIPOUT_ORDER_'.$order->id,
        ])->assertForbidden();

        Http::assertNothingSent();
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
    }

    public function test_v1_web_send_route_rejects_when_dispatch_off_even_for_operator(): void
    {
        $order = $this->order();
        $this->allowSyntheticHttpPost();
        $this->enableProductionV1();
        config()->set('shipout.production_v1_dispatch_enabled', false);
        $this->fakeProvider();
        // Preserve the allowlisted signed-in operator from enableProductionV1().

        $this->postJson("/logistics/oversea/us-moreplus/orders/{$order->id}/shipout/production/send", [
            'ship_date' => $this->shipDate(),
            'shipping_mode' => '9',
            'payload_hash' => str_repeat('a', 64),
            'confirmation' => 'SUBMIT_SHIPOUT_ORDER_'.$order->id,
        ])->assertStatus(423)
            ->assertJsonPath('error_code', 'SHIPOUT_V1_DISPATCH_DISABLED');

        Http::assertNothingSent();
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
    }

    public function test_v1_web_status_without_provider_attempt_stays_read_only(): void
    {
        $order = $this->order();
        $this->allowSyntheticHttpPost();
        $this->enableProductionV1();
        $this->fakeProvider();
        $this->actingAs($this->productionWarehouseUser(['logistics.view']));

        $this->postJson("/logistics/oversea/us-moreplus/orders/{$order->id}/shipout/production/status")
            ->assertOk()
            ->assertJsonPath('attempt_status', 'never_submitted')
            ->assertJsonPath('reconciliation', 'NO_ERP_ATTEMPT_AND_NO_OMS_ORDER')
            ->assertJsonPath('tracking_apply', false);

        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
    }

    public function test_v1_web_manual_send_uses_signed_r10_transport_and_returns_ack_not_shipped(): void
    {
        $order = $this->order();
        $this->allowSyntheticHttpPost();
        $this->enableProductionV1();
        $this->fakeProvider();
        $preflight = app(ShipOutProductionCanaryDispatchService::class)->previewApproved(
            $order->id, $this->shipDate()
        );
        $operator = $this->productionWarehouseUser(['logistics.view', 'logistics.operate']);
        $this->actingAs($operator);
        $andy = $this->productionWarehouseUser(['logistics.view', 'logistics.operate']);
        $vita = $this->productionWarehouseUser(['logistics.view']);
        config()->set('shipout.production_v1_operator_user_ids', implode(',', [
            $operator->id, $andy->id, $vita->id,
        ]));

        $this->postJson("/logistics/oversea/us-moreplus/orders/{$order->id}/shipout/production/send", [
            'ship_date' => $this->shipDate(),
            'shipping_mode' => '9',
            'payload_hash' => $preflight['payload_hash'],
            'confirmation' => 'SUBMIT_SHIPOUT_ORDER_'.$order->id,
        ])->assertOk()
            ->assertJsonPath('status', 'acknowledged')
            ->assertJsonPath('provider_order_id', 'SYNTHETIC-PROVIDER-ORDER')
            ->assertJsonPath('erp_shipment_unchanged', true)
            ->assertJsonPath('woo_unchanged', true)
            ->assertJsonPath('tracking_apply', false);

        $this->assertDatabaseHas('shipout_dispatch_attempts', [
            'order_id' => $order->id,
            'status' => 'acknowledged',
            'provider_order_id' => 'SYNTHETIC-PROVIDER-ORDER',
        ]);
        $this->assertSame('pending_shipment', $order->fresh()->order_status->value);
        $this->assertNull($order->fresh()->shipment->tracking_number);
        $this->assertSame(1, $this->postInvocations);

        $this->providerVisibleOrderId = 'SYNTHETIC-PROVIDER-ORDER';
        $this->postJson("/logistics/oversea/us-moreplus/orders/{$order->id}/shipout/production/status")
            ->assertOk()
            ->assertJsonPath('reconciliation', 'ORDER_VISIBLE_MANUAL_REVIEW_ONLY')
            ->assertJsonPath('provider_order_id', 'SYNTHETIC-PROVIDER-ORDER')
            ->assertJsonPath('shipped', false)
            ->assertJsonPath('tracking_apply', false);
        $this->assertSame(1, $this->postInvocations);
    }

    public function test_v1_restricts_signed_submission_to_exact_authorized_operator(): void
    {
        $order = $this->order();
        $this->enableProductionV1();
        $this->fakeProvider();
        $service = app(ShipOutProductionCanaryDispatchService::class);
        $preview = $service->previewApproved($order->id, $this->shipDate());

        $otherOperator = $this->productionWarehouseUser(['logistics.view', 'logistics.operate']);
        $this->actingAs($otherOperator);
        try {
            $service->dispatchApprovedOrder(
                $order->id, $this->shipDate(), $preview['payload_hash'],
                'SUBMIT_SHIPOUT_ORDER_'.$order->id,
            );
            $this->fail('A different logistics operator must not submit');
        } catch (RuntimeException $error) {
            $this->assertSame('SHIPOUT_V1_OPERATOR_DENIED', $error->getMessage());
        }
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_v1_older_erp_order_cannot_be_previewed_or_submitted(): void
    {
        $order = $this->order();
        $this->enableProductionV1();
        config()->set('shipout.production_v1_min_order_id', $order->id + 1);
        $this->fakeProvider();

        try {
            app(ShipOutProductionCanaryDispatchService::class)->previewApproved(
                $order->id, $this->shipDate()
            );
            $this->fail('Pre-cutover orders require the existing manual process');
        } catch (RuntimeException $error) {
            $this->assertSame('SHIPOUT_V1_OLD_ORDER_EXCLUDED', $error->getMessage());
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
    }

    public function test_v1_fails_closed_without_explicit_operator_user_id(): void
    {
        $order = $this->order();
        $this->enableProductionV1();
        config()->set('shipout.production_v1_operator_user_ids', '');
        $this->fakeProvider();
        $service = app(ShipOutProductionCanaryDispatchService::class);
        $preview = $service->previewApproved($order->id, $this->shipDate());

        try {
            $service->dispatchApprovedOrder(
                $order->id, $this->shipDate(), $preview['payload_hash'],
                'SUBMIT_SHIPOUT_ORDER_'.$order->id,
            );
            $this->fail('No configured operator must mean no POST');
        } catch (RuntimeException $error) {
            $this->assertSame('SHIPOUT_V1_OPERATOR_DENIED', $error->getMessage());
        }
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_v1_dede_can_use_approved_signed_web_send(): void
    {
        $this->assertThreeOperatorSend(0, true);
    }

    public function test_v1_andy_can_use_approved_signed_web_send(): void
    {
        $this->assertThreeOperatorSend(1, true);
    }

    public function test_v1_vita_can_use_v1_without_global_logistics_operate(): void
    {
        $this->assertThreeOperatorSend(2, false);
    }

    private function assertThreeOperatorSend(int $index, bool $globalOperate): void
    {
        $order = $this->order();
        $this->allowSyntheticHttpPost();
        $this->enableProductionV1();
        $this->fakeProvider();
        $ids = array_map('intval', explode(',', config('shipout.production_v1_operator_user_ids')));
        $actor = User::findOrFail($ids[$index]);
        $this->actingAs($actor);
        $this->assertSame($globalOperate, $actor->hasPermission('logistics.operate'));
        $preview = app(ShipOutProductionCanaryDispatchService::class)->previewApproved(
            $order->id, $this->shipDate()
        );

        $this->postJson("/logistics/oversea/us-moreplus/orders/{$order->id}/shipout/production/send", [
            'ship_date' => $this->shipDate(),
            'shipping_mode' => '9',
            'payload_hash' => $preview['payload_hash'],
            'confirmation' => 'SUBMIT_SHIPOUT_ORDER_'.$order->id,
        ])->assertOk()
            ->assertJsonPath('status', 'acknowledged')
            ->assertJsonPath('erp_shipment_unchanged', true)
            ->assertJsonPath('tracking_apply', false);

        $this->assertSame(1, $this->postInvocations);
        $this->assertNull($order->fresh()->shipment->tracking_number);
        $this->assertSame('pending_shipment', $order->fresh()->order_status->value);
    }

    public function test_v1_unlisted_logistics_staff_cannot_use_production_route(): void
    {
        $order = $this->order();
        $this->allowSyntheticHttpPost();
        $this->enableProductionV1();
        $other = $this->productionWarehouseUser(['logistics.view', 'logistics.operate']);
        $this->actingAs($other);
        $this->fakeProvider();

        $this->postJson("/logistics/oversea/us-moreplus/orders/{$order->id}/shipout/production/send", [
            'ship_date' => $this->shipDate(),
            'shipping_mode' => '9',
            'payload_hash' => str_repeat('a', 64),
            'confirmation' => 'SUBMIT_SHIPOUT_ORDER_'.$order->id,
        ])->assertForbidden();
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_v1_invalid_or_missing_allowlist_fails_closed(): void
    {
        $order = $this->order();
        $this->enableProductionV1();
        $this->fakeProvider();
        $service = app(ShipOutProductionCanaryDispatchService::class);
        $preview = $service->previewApproved($order->id, $this->shipDate());
        foreach (['', '0', '1,2', '1,2,2', '1,abc,3', '1, 2,3', '1,2,3,4'] as $bad) {
            config()->set('shipout.production_v1_operator_user_ids', $bad);
            try {
                $service->dispatchApprovedOrder(
                    $order->id, $this->shipDate(), $preview['payload_hash'],
                    'SUBMIT_SHIPOUT_ORDER_'.$order->id
                );
                $this->fail('Malformed allowlist must block provider POST');
            } catch (RuntimeException $error) {
                $this->assertSame('SHIPOUT_V1_OPERATOR_DENIED', $error->getMessage());
            }
        }
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_v1_inactive_vita_is_denied_even_when_listed(): void
    {
        $order = $this->order();
        $this->enableProductionV1();
        $ids = array_map('intval', explode(',', config('shipout.production_v1_operator_user_ids')));
        $vita = User::findOrFail($ids[2]);
        $vita->update(['is_active' => false]);
        $this->actingAs($vita);
        $this->fakeProvider();
        $service = app(ShipOutProductionCanaryDispatchService::class);
        $pre = $service->previewApproved($order->id, $this->shipDate());

        try {
            $service->dispatchApprovedOrder(
                $order->id, $this->shipDate(), $pre['payload_hash'],
                'SUBMIT_SHIPOUT_ORDER_'.$order->id
            );
            $this->fail('Inactive account must not send');
        } catch (RuntimeException $error) {
            $this->assertSame('SHIPOUT_V1_OPERATOR_DENIED', $error->getMessage());
        }
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_v1_vita_cannot_bypass_current_dede_review(): void
    {
        $order = $this->order();
        $this->enableProductionV1();
        $ids = array_map('intval', explode(',', config('shipout.production_v1_operator_user_ids')));
        $this->actingAs(User::findOrFail($ids[2]));
        OverseaWarehouseReview::query()->where('order_id', $order->id)
            ->update(['status' => 'pending']);
        $this->fakeProvider();

        try {
            app(ShipOutProductionCanaryDispatchService::class)->previewApproved(
                $order->id, $this->shipDate()
            );
            $this->fail('Unapproved order cannot be preflighted');
        } catch (RuntimeException $error) {
            $this->assertSame('SHIPOUT_ERP_APPROVAL_OR_WAREHOUSE_BLOCKED', $error->getMessage());
        }
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_v1_live_oms_cancelled_and_aborted_statuses_block_normal_shipping_claims(): void
    {
        $order = $this->order();
        $this->enableProductionV1();
        DB::table('shipout_dispatch_attempts')->insert([
            'order_id' => $order->id,
            'order_no' => self::REF,
            'provider_warehouse_id' => self::WAREHOUSE,
            'payload_hash' => str_repeat('b', 64),
            'status' => 'acknowledged',
            'provider_order_id' => 'OMS-CANCELLED-SYNTHETIC',
            'result_code' => 'ASYNC_ACCEPTED_QUERY_REQUIRED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $liveProviderStatus = 6;
        Http::fake(function ($request) use (&$liveProviderStatus) {
            return Http::response([
                'result' => 'OK',
                'data' => [
                    'total' => 1,
                    'records' => [[
                        'orderNO' => self::REF,
                        'orderId' => 'OMS-CANCELLED-SYNTHETIC',
                        'warehouseId' => self::WAREHOUSE,
                        'status' => $liveProviderStatus,
                    ]],
                ],
            ], 200);
        });

        foreach ([6, 11, 4] as $status) {
            $liveProviderStatus = $status;
            $result = app(ShipOutProductionCanaryReconcileService::class)->inspect($order->id);
            $this->assertSame($status, $result['provider_status']);
            $this->assertSame($status === 4
                ? 'ORDER_VISIBLE_MANUAL_REVIEW_ONLY'
                : 'PROVIDER_CANCELLED_OR_ABORTED_MANUAL_REVIEW', $result['reconciliation']);
            $this->assertFalse($result['shipped']);
            $this->assertFalse($result['tracking_apply']);
        }

        $this->assertDatabaseHas('shipout_dispatch_attempts', [
            'order_id' => $order->id, 'status' => 'acknowledged',
            'provider_order_id' => 'OMS-CANCELLED-SYNTHETIC',
        ]);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'shipment_status' => 'pending',
            'tracking_number' => null,
            'shipped_at' => null,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id, 'order_status' => 'pending_shipment',
        ]);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    private function makeSyntheticBatch(int $orderId, int $actorId, string $batchId): void
    {
        DB::table('shipout_production_batches')->insert([
            'id' => $batchId, 'operator_user_id' => $actorId,
            'status' => 'queued', 'dry_run' => false, 'total' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('shipout_production_batch_items')->insert([
            'batch_id' => $batchId, 'order_id' => $orderId,
            'order_no' => '007-1031-c99999', 'status' => 'queued',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_v1_batch_worker_uses_explicit_operator_without_http_session_and_sends_only_once(): void
    {
        $order = $this->order();
        $this->enableProductionV1();
        $this->fakeProvider(onOrderGet: function (): void {
            if ($this->postInvocations >= 1) {
                $this->providerVisibleOrderId = 'SYNTHETIC-PROVIDER-ORDER';
            }
        });
        config()->set([
            'shipout.production_v1_batch_enabled' => true,
            'shipout.production_v1_batch_owner_approved' => true,
            'shipout.production_v1_batch_canary_order_id' => $order->id,
        ]);
        $actor = auth()->user();
        $batchId = '00000000-0000-4000-8000-000000000091';
        $this->makeSyntheticBatch((int) $order->id, (int) $actor->id, $batchId);
        auth()->logout(); // Queue workers are not authenticated HTTP requests.

        $worker = new ProcessShipOutProductionBatch($batchId);
        $worker->handle(
            app(ShipOutProductionCanaryDispatchService::class),
            app(ShipOutProductionV1OperatorAccess::class),
        );
        $this->assertDatabaseHas('shipout_production_batch_items', [
            'batch_id' => $batchId, 'status' => 'oms_visible',
        ]);
        $this->assertDatabaseHas('shipout_dispatch_attempts', [
            'order_id' => $order->id, 'status' => 'acknowledged',
        ]);
        $this->assertSame(1, $this->postInvocations);
        $this->assertSame('pending', $order->fresh()->shipment->shipment_status);
        $this->assertNull($order->fresh()->shipment->tracking_number);
        $worker->handle(
            app(ShipOutProductionCanaryDispatchService::class),
            app(ShipOutProductionV1OperatorAccess::class),
        );
        $this->assertSame(1, $this->postInvocations);
    }

    public function test_v1_get_only_readback_rejects_async_duplicate_with_no_second_post(): void
    {
        $order = $this->order();
        $this->enableProductionV1();
        $batchId = '00000000-0000-4000-8000-000000000094';
        $this->makeSyntheticBatch((int) $order->id, 1, $batchId);
        DB::table('shipout_production_batch_items')->where('batch_id', $batchId)
            ->update(['status' => 'pending_oms', 'provider_order_id' => 'OMS-DUPLICATE-1']);
        DB::table('shipout_dispatch_attempts')->insert([
            'order_id' => $order->id, 'order_no' => self::REF,
            'provider_warehouse_id' => self::WAREHOUSE,
            'payload_hash' => str_repeat('c', 64), 'status' => 'acknowledged',
            'provider_order_id' => 'OMS-DUPLICATE-1',
            'result_code' => 'ASYNC_ACCEPTED_QUERY_REQUIRED',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Http::preventStrayRequests();
        Http::fake(['https://open.shipout.com/api/*' => Http::response([
            'result' => 'OK',
            'data' => ['total' => 1, 'records' => [[
                'orderNO' => self::REF, 'orderId' => 'OMS-DUPLICATE-1',
                'warehouseId' => self::WAREHOUSE, 'status' => 6,
                'cancelReason' => 'C99999-007 has been detected as duplicated order, please modify and re-submit. SECRET_ADDRESS',
            ]]],
        ], 200)]);
        (new VerifyShipOutProductionBatchItem($batchId, (int) $order->id))
            ->handle(app(ShipOutProductionCanaryReconcileService::class));
        $this->assertDatabaseHas('shipout_production_batch_items', [
            'batch_id' => $batchId, 'status' => 'provider_cancelled',
            'result_code' => 'PROVIDER_DUPLICATE_ORDER',
        ]);
        $this->assertDatabaseHas('shipout_dispatch_attempts', [
            'order_id' => $order->id, 'status' => 'acknowledged',
        ]);
        $this->assertSame(0, $this->postInvocations);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
        $this->assertStringNotContainsString('SECRET_ADDRESS', json_encode(
            DB::table('shipout_production_batch_items')->where('batch_id', $batchId)->first()
        ));
    }

    public function test_v1_get_only_readback_rechecks_provisional_oms_before_declaring_visible(): void
    {
        $order = $this->order();
        $this->enableProductionV1();
        $batchId = '00000000-0000-4000-8000-000000000096';
        $this->makeSyntheticBatch((int) $order->id, 1, $batchId);
        DB::table('shipout_production_batches')->where('id', $batchId)
            ->update(['status' => 'awaiting_oms']);
        DB::table('shipout_production_batch_items')->where('batch_id', $batchId)
            ->update(['status' => 'pending_oms', 'provider_order_id' => 'OMS-TWO-PHASE']);
        DB::table('shipout_dispatch_attempts')->insert([
            'order_id' => $order->id, 'order_no' => self::REF,
            'provider_warehouse_id' => self::WAREHOUSE,
            'payload_hash' => str_repeat('c', 64), 'status' => 'acknowledged',
            'provider_order_id' => 'OMS-TWO-PHASE',
            'result_code' => 'ASYNC_ACCEPTED_QUERY_REQUIRED',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Http::preventStrayRequests();
        Http::fake(['https://open.shipout.com/api/*' => Http::response([
            'result' => 'OK',
            'data' => ['total' => 1, 'records' => [[
                'orderNO' => self::REF, 'orderId' => 'OMS-TWO-PHASE',
                'warehouseId' => self::WAREHOUSE, 'status' => 10,
            ]]],
        ], 200)]);
        // Dispatch on the sync queue can execute the two readback passes
        // inline; neither pass may ever create another OMS order.
        (new VerifyShipOutProductionBatchItem($batchId, (int) $order->id))
            ->handle(app(ShipOutProductionCanaryReconcileService::class));
        $this->assertDatabaseHas('shipout_production_batch_items', [
            'batch_id' => $batchId, 'status' => 'oms_visible',
            'result_code' => 'OMS_VISIBLE_PENDING_REAL_FULFILLMENT',
        ]);
        $this->assertDatabaseHas('shipout_production_batches', [
            'id' => $batchId, 'status' => 'completed',
        ]);
        $this->assertDatabaseCount('shipout_dispatch_attempts', 1);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'shipment_status' => 'pending',
            'tracking_number' => null,
        ]);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_v1_late_async_cancellation_overrides_provisional_oms_visibility(): void
    {
        $order = $this->order();
        $this->enableProductionV1();
        $batchId = '00000000-0000-4000-8000-000000000099';
        $this->makeSyntheticBatch((int) $order->id, 1, $batchId);
        DB::table('shipout_production_batches')->where('id', $batchId)
            ->update(['status' => 'awaiting_oms']);
        DB::table('shipout_production_batch_items')->where('batch_id', $batchId)
            ->update(['status' => 'pending_oms', 'provider_order_id' => 'OMS-LATE-ERROR']);
        DB::table('shipout_dispatch_attempts')->insert([
            'order_id' => $order->id, 'order_no' => self::REF,
            'provider_warehouse_id' => self::WAREHOUSE,
            'payload_hash' => str_repeat('c', 64), 'status' => 'acknowledged',
            'provider_order_id' => 'OMS-LATE-ERROR',
            'result_code' => 'ASYNC_ACCEPTED_QUERY_REQUIRED',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $gets = 0;
        Http::preventStrayRequests();
        Http::fake(function ($request) use (&$gets) {
            $gets++;

            return Http::response([
                'result' => 'OK',
                'data' => ['total' => 1, 'records' => [[
                    'orderNO' => self::REF, 'orderId' => 'OMS-LATE-ERROR',
                    'warehouseId' => self::WAREHOUSE,
                    'status' => $gets === 1 ? 10 : 6,
                    'cancelReason' => $gets === 1 ? '' : 'shipping error! [EzeeShip]null',
                ]]],
            ], 200);
        });
        (new VerifyShipOutProductionBatchItem($batchId, (int) $order->id))
            ->handle(app(ShipOutProductionCanaryReconcileService::class));

        $this->assertSame(2, $gets);
        $this->assertDatabaseHas('shipout_production_batch_items', [
            'batch_id' => $batchId, 'status' => 'provider_cancelled',
            'result_code' => 'PROVIDER_EZEESHIP_SHIPPING_ERROR',
        ]);
        $this->assertDatabaseHas('shipout_production_batches', [
            'id' => $batchId, 'status' => 'completed_partial',
        ]);
        $this->assertDatabaseCount('shipout_dispatch_attempts', 1);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'shipment_status' => 'pending',
            'tracking_number' => null,
        ]);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_v1_get_only_readback_classifies_ezeeship_shipping_error(): void
    {
        $order = $this->order();
        $this->enableProductionV1();
        $batchId = '00000000-0000-4000-8000-000000000095';
        $this->makeSyntheticBatch((int) $order->id, 1, $batchId);
        DB::table('shipout_production_batch_items')->where('batch_id', $batchId)
            ->update(['status' => 'pending_oms', 'provider_order_id' => 'OMS-ERROR-1']);
        DB::table('shipout_dispatch_attempts')->insert([
            'order_id' => $order->id, 'order_no' => self::REF,
            'provider_warehouse_id' => self::WAREHOUSE,
            'payload_hash' => str_repeat('c', 64), 'status' => 'acknowledged',
            'provider_order_id' => 'OMS-ERROR-1',
            'result_code' => 'ASYNC_ACCEPTED_QUERY_REQUIRED',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Http::preventStrayRequests();
        Http::fake(['https://open.shipout.com/api/*' => Http::response([
            'result' => 'OK',
            'data' => ['total' => 1, 'records' => [[
                'orderNO' => self::REF, 'orderId' => 'OMS-ERROR-1',
                'warehouseId' => self::WAREHOUSE, 'status' => 6,
                'cancelReason' => 'shipping error! [EzeeShip]null Trace is PRIVATE_TRACE',
            ]]],
        ], 200)]);
        (new VerifyShipOutProductionBatchItem($batchId, (int) $order->id))
            ->handle(app(ShipOutProductionCanaryReconcileService::class));
        $this->assertDatabaseHas('shipout_production_batch_items', [
            'batch_id' => $batchId, 'status' => 'provider_cancelled',
            'result_code' => 'PROVIDER_EZEESHIP_SHIPPING_ERROR',
        ]);
        $this->assertSame(0, $this->postInvocations);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_v1_batch_worker_denies_out_of_scope_order_without_provider_egress(): void
    {
        $order = $this->order();
        $this->enableProductionV1();
        $this->fakeProvider();
        config()->set([
            'shipout.production_v1_batch_enabled' => true,
            'shipout.production_v1_batch_owner_approved' => true,
            'shipout.production_v1_batch_canary_order_id' => $order->id + 5,
        ]);
        $actor = auth()->user();
        $batchId = '00000000-0000-4000-8000-000000000093';
        $this->makeSyntheticBatch((int) $order->id, (int) $actor->id, $batchId);
        auth()->logout();
        (new ProcessShipOutProductionBatch($batchId))->handle(
            app(ShipOutProductionCanaryDispatchService::class),
            app(ShipOutProductionV1OperatorAccess::class),
        );
        $this->assertDatabaseHas('shipout_production_batch_items', [
            'batch_id' => $batchId, 'status' => 'skipped',
            'result_code' => 'SHIPOUT_BATCH_CANARY_SCOPE_DENIED',
        ]);
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_v1_batch_timeout_is_uncertain_and_never_retries(): void
    {
        $order = $this->order();
        $this->enableProductionV1();
        $this->fakeProvider(3, self::SKU, false, true);
        config()->set([
            'shipout.production_v1_batch_enabled' => true,
            'shipout.production_v1_batch_owner_approved' => true,
            'shipout.production_v1_batch_canary_order_id' => $order->id,
        ]);
        $actor = auth()->user();
        $batchId = '00000000-0000-4000-8000-000000000092';
        $this->makeSyntheticBatch((int) $order->id, (int) $actor->id, $batchId);
        auth()->logout();
        $worker = new ProcessShipOutProductionBatch($batchId);
        $worker->handle(
            app(ShipOutProductionCanaryDispatchService::class),
            app(ShipOutProductionV1OperatorAccess::class),
        );
        $this->assertDatabaseHas('shipout_production_batch_items', [
            'batch_id' => $batchId, 'status' => 'uncertain',
        ]);
        $this->assertDatabaseHas('shipout_dispatch_attempts', [
            'order_id' => $order->id, 'status' => 'uncertain',
        ]);
        $this->assertSame(1, $this->postInvocations);
        $worker->handle(
            app(ShipOutProductionCanaryDispatchService::class),
            app(ShipOutProductionV1OperatorAccess::class),
        );
        $this->assertSame(1, $this->postInvocations);
    }
}
