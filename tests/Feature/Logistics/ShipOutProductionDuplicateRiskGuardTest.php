<?php

namespace Tests\Feature\Logistics;

use App\Modules\Logistics\Application\ShipOut\ShipOutProductionDuplicateRiskGuard;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ShipOutProductionDuplicateRiskGuardTest extends TestCase
{
    private mixed $originalEnvironment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnvironment = $this->app['env'];
        $this->app->instance('env', 'production');
        config()->set([
            'shipout.production_read_enabled' => true,
            'shipout.production_app_key' => 'TEST-KEY',
            'shipout.production_app_secret' => 'TEST-SECRET',
            'shipout.production_bearer' => 'TEST-BEARER',
            'shipout.production_warehouse_id' => '1265864414750314497',
            'shipout.production_client_org_id' => '57893',
        ]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->app->instance('env', $this->originalEnvironment);
        parent::tearDown();
    }

    private function payload(string $sku = 'BY489A'): array
    {
        return [[
            'orderNo' => 'NEVER-SEEN-ORDER-001',
            'toAddress' => [
                'name' => 'Test Recipient',
                'zipCode' => '90210',
                'addressLine1' => '100 Demo Street',
            ],
            'shipmentForms' => [[
                'productList' => [['omsSku' => $sku, 'qty' => 2]],
            ]],
        ]];
    }

    private function history(string $sku = 'BY489A', int $qty = 2, string $name = 'Test Recipient', ?string $date = null): array
    {
        return [
            'orderId' => 'PROVIDER-SYNTHETIC-001', 'orderNO' => 'DIFFERENT-ORDER-NO',
            'orderDate' => $date ?? now('America/Los_Angeles')->format('Y-m-d H:i:s'),
            'recipientName' => $name, 'zipCode' => '90210',
            'shipTo' => '100 Demo Street, Test City, CA, 90210',
            'shipmentList' => [[
                'warehouseId' => '1265864414750314497',
                'productList' => [['omsSku' => $sku, 'qty' => $qty]],
            ]],
        ];
    }

    private function stub(array $records, ?int $total = null): void
    {
        Http::fake(function ($request) use ($records, $total) {
            if (! str_contains($request->url(), '/open-api/oms/order/queryListV2?')
                || str_contains($request->url(), 'orderNO=')) {
                return Http::response(['result' => 'ERR'], 400);
            }

            return Http::response(['result' => 'OK', 'data' => [
                'total' => $total ?? count($records),
                'records' => $records,
            ]], 200);
        });
    }

    public function test_matching_sku_quantity_and_recipient_with_different_order_number_blocks_auto_post(): void
    {
        $this->stub([$this->history()]);
        $result = app(ShipOutProductionDuplicateRiskGuard::class)->inspect($this->payload());
        $this->assertSame('risk', $result['status']);
        $this->assertSame('SHIPOUT_R9_14D_CONTENT_DUPLICATE_RISK', $result['code']);
        $this->assertSame(1, $result['checked_orders']);
        $this->assertSame(14, $result['window_days']);
        $this->assertStringNotContainsString('Test Recipient', json_encode($result));
        $this->assertStringNotContainsString('Demo Street', json_encode($result));
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_different_sku_or_recipient_does_not_indicate_a_duplicate(): void
    {
        $this->stub([$this->history('OTHER-SKU'), $this->history('BY489A', 2, 'Different Recipient')]);
        // Two rows with same orderId is intentionally not trusted, even when
        // contents differ. A complete clear requires unique provider IDs.
        $result = app(ShipOutProductionDuplicateRiskGuard::class)->inspect($this->payload());
        $this->assertSame('unknown', $result['status']);
        $this->assertSame('SHIPOUT_R9_HISTORY_UNTRUSTED', $result['code']);
    }

    public function test_complete_history_with_no_matching_content_is_clear(): void
    {
        $old = $this->history('OTHER-SKU');
        $other = $this->history('BY489A', 2, 'Different Recipient');
        $other['orderId'] = 'PROVIDER-SYNTHETIC-002';
        $other['zipCode'] = '10001';
        $other['shipTo'] = '500 Other Avenue, NY 10001';
        $this->stub([$old, $other]);
        $r = app(ShipOutProductionDuplicateRiskGuard::class)->inspect($this->payload());
        $this->assertSame('clear', $r['status']);
        $this->assertSame(2, $r['checked_orders']);
    }

    public function test_unavailable_history_is_unknown_not_clear(): void
    {
        Http::fake(['https://open.shipout.com/api/*' => Http::response(['result' => 'ERR'], 503)]);
        $r = app(ShipOutProductionDuplicateRiskGuard::class)->inspect($this->payload());
        $this->assertSame('unknown', $r['status']);
        $this->assertSame('SHIPOUT_R9_HISTORY_UNAVAILABLE', $r['code']);
        $this->expectExceptionMessage('SHIPOUT_R9_HISTORY_UNAVAILABLE');
        app(ShipOutProductionDuplicateRiskGuard::class)->assertNoContentRisk($this->payload());
    }

    public function test_provider_page_truncated_even_with_ok_result_blocks(): void
    {
        $this->stub([$this->history()], 2);
        $r = app(ShipOutProductionDuplicateRiskGuard::class)->inspect($this->payload());
        $this->assertSame('unknown', $r['status']);
        $this->assertSame('SHIPOUT_R9_HISTORY_INCOMPLETE', $r['code']);
    }

    public function test_missing_recipient_or_items_blocks(): void
    {
        $row = $this->history();
        unset($row['shipTo']);
        $this->stub([$row]);
        $r = app(ShipOutProductionDuplicateRiskGuard::class)->inspect($this->payload());
        $this->assertSame('unknown', $r['status']);
        $this->assertSame('SHIPOUT_R9_HISTORY_CONTENT_UNVERIFIED', $r['code']);
    }

    public function test_exact_old_match_outside_window_does_not_block(): void
    {
        $old = $this->history('BY489A', 2, 'Test Recipient',
            now('America/Los_Angeles')->subDays(16)->format('Y-m-d H:i:s'));
        $old['lastSyncTime'] = now('America/Los_Angeles')->subDays(16)->format('Y-m-d H:i:s');
        $this->stub([$old]);
        $r = app(ShipOutProductionDuplicateRiskGuard::class)->inspect($this->payload());
        $this->assertSame('clear', $r['status']);
    }

    public function test_old_business_date_recent_provider_sync_still_blocks_duplicate(): void
    {
        $old = $this->history('BY489A', 2, 'Test Recipient',
            now('America/Los_Angeles')->subDays(25)->format('Y-m-d H:i:s'));
        $old['lastSyncTime'] = now('America/Los_Angeles')->format('Y-m-d H:i:s');
        $this->stub([$old]);
        $r = app(ShipOutProductionDuplicateRiskGuard::class)->inspect($this->payload());
        $this->assertSame('risk', $r['status']);
        $this->assertSame('SHIPOUT_R9_14D_CONTENT_DUPLICATE_RISK', $r['code']);
    }

    public function test_old_business_date_without_provider_sync_is_unknown_not_safe(): void
    {
        $old = $this->history('BY489A', 2, 'Test Recipient',
            now('America/Los_Angeles')->subDays(25)->format('Y-m-d H:i:s'));
        $this->stub([$old]);
        $r = app(ShipOutProductionDuplicateRiskGuard::class)->inspect($this->payload());
        $this->assertSame('unknown', $r['status']);
        $this->assertSame('SHIPOUT_R9_HISTORY_DATE_UNVERIFIED', $r['code']);
    }

    public function test_history_larger_than_capacity_is_unknown(): void
    {
        $this->stub([$this->history()], 1001);
        $r = app(ShipOutProductionDuplicateRiskGuard::class)->inspect($this->payload());
        $this->assertSame('unknown', $r['status']);
        $this->assertSame('SHIPOUT_R9_HISTORY_OVERFLOW', $r['code']);
    }

    public function test_multi_page_scan_with_101_valid_orders_is_complete(): void
    {
        $rows = [];
        for ($i = 0; $i < 101; $i++) {
            $row = $this->history('OTHER-SKU');
            $row['orderId'] = 'PROVIDER-'.$i;
            $rows[] = $row;
        }
        Http::fake(function ($req) use ($rows) {
            parse_str(parse_url($req->url(), PHP_URL_QUERY) ?: '', $query);
            $page = (int) ($query['curPageNo'] ?? 0);

            return Http::response(['result' => 'OK', 'data' => [
                'total' => 101,
                'records' => array_slice($rows, ($page - 1) * 100, 100),
            ]]);
        });
        $r = app(ShipOutProductionDuplicateRiskGuard::class)->inspect($this->payload());
        $this->assertSame('clear', $r['status']);
        $this->assertSame(101, $r['checked_orders']);
        Http::assertSentCount(2);
    }
}
