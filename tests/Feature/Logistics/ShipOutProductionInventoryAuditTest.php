<?php

namespace Tests\Feature\Logistics;

use App\Modules\Logistics\Infrastructure\ShipOut\ShipOutProductionInventoryAudit;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ShipOutProductionInventoryAuditTest extends TestCase
{
    use RefreshDatabase;

    private mixed $oldEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oldEnv = $this->app['env'];
        $this->app->instance('env', 'production');
        config()->set('shipout.production_read_enabled', true);
        config()->set('shipout.production_app_key', 'synthetic-key');
        config()->set('shipout.production_app_secret', 'synthetic-secret');
        config()->set('shipout.production_bearer', 'synthetic-bearer');
        config()->set('shipout.production_warehouse_id', 'WAREHOUSE-A');
        config()->set('shipout.production_client_org_id', 'ORG-A');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->app->instance('env', $this->oldEnv);
        parent::tearDown();
    }

    private function fakeProvider(string $sku): void
    {
        Http::fake(function ($request) use ($sku) {
            $url = $request->url();
            if (str_contains($url, '/warehouse/list')) {
                return Http::response(['result' => 'OK', 'data' => [
                    ['warehouseId' => 'WAREHOUSE-A', 'warehouseName' => 'MorePlus'],
                ]], 200);
            }
            if (str_contains($url, '/product/queryList')) {
                return Http::response(['result' => 'OK', 'data' => [
                    'total' => 1, 'records' => [['omsSku' => $sku, 'status' => 1]],
                ]], 200);
            }
            if (str_contains($url, '/stock/list')) {
                return Http::response(['result' => 'OK', 'data' => [
                    'total' => 1, 'records' => [[
                        'warehouseId' => 'WAREHOUSE-A', 'omsSku' => $sku,
                        'availableQuantity' => 3, 'standardQuantity' => 20,
                    ]],
                ]], 200);
            }

            return Http::response([], 404);
        });
    }

    public function test_audit_reads_verified_canonical_identity_and_actual_availability(): void
    {
        Product::query()->create([
            'sku' => 'AUDIT-SKU-001', 'name' => 'Local Verified Product',
            'product_type' => 'simple', 'is_active' => true,
        ]);
        $this->fakeProvider('AUDIT-SKU-001');
        $audit = app(ShipOutProductionInventoryAudit::class)->snapshot();
        $this->assertSame('WAREHOUSE-A', $audit['warehouse_id']);
        $this->assertSame('ORG-A', $audit['client_org_id']);
        $this->assertSame(1, $audit['verified_sku_count']);
        $this->assertSame(0, $audit['unresolved_sku_count']);
        $this->assertSame(3, $audit['stock_rows'][0]['available_quantity']);
        $this->assertSame('AUDIT-SKU-001', $audit['stock_rows'][0]['canonical_sku']);
        Http::assertSentCount(3);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/stock/list')
            && str_contains($r->url(), 'clientOrgId=ORG-A')
            && str_contains($r->url(), 'warehouseId=WAREHOUSE-A'));
    }

    public function test_unknown_provider_sku_cannot_be_auto_approved(): void
    {
        $this->fakeProvider('UNKNOWN-PROVIDER-SKU');
        $audit = app(ShipOutProductionInventoryAudit::class)->snapshot();
        $this->assertSame(0, $audit['verified_sku_count']);
        $this->assertSame(1, $audit['unresolved_sku_count']);
        $this->assertNull($audit['stock_rows'][0]['canonical_sku']);
        $this->assertSame('unresolved', $audit['stock_rows'][0]['sku_alias_status']);
        Http::assertSentCount(3);
    }

    public function test_unknown_warehouse_blocks_before_catalog_and_stock(): void
    {
        Http::fake(['https://open.shipout.com/api/*' => Http::response([
            'result' => 'OK', 'data' => [['warehouseId' => 'OTHER-W', 'warehouseName' => 'Not Ours']],
        ], 200)]);
        $this->expectExceptionMessage('SHIPOUT_PROD_WAREHOUSE_NOT_AUTHORIZED');
        try {
            app(ShipOutProductionInventoryAudit::class)->snapshot();
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_catalog_missing_sku_cannot_be_verified_even_when_erp_sku_exists(): void
    {
        Product::query()->create([
            'sku' => 'AUDIT-SKU-002', 'name' => 'Local Known Product',
            'product_type' => 'simple', 'is_active' => true,
        ]);
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/warehouse/list')) {
                return Http::response(['result' => 'OK', 'data' => [
                    ['warehouseId' => 'WAREHOUSE-A', 'warehouseName' => 'MorePlus'],
                ]], 200);
            }
            if (str_contains($url, '/product/queryList')) {
                return Http::response(['result' => 'OK', 'data' => ['total' => 0, 'records' => []]], 200);
            }

            return Http::response(['result' => 'OK', 'data' => [
                'total' => 1, 'records' => [[
                    'omsSku' => 'AUDIT-SKU-002', 'warehouseId' => 'WAREHOUSE-A',
                    'availableQuantity' => 2,
                ]],
            ]], 200);
        });
        $audit = app(ShipOutProductionInventoryAudit::class)->snapshot();
        $this->assertSame(0, $audit['verified_sku_count']);
        $this->assertSame(1, $audit['unresolved_sku_count']);
        $this->assertFalse($audit['stock_rows'][0]['in_product_catalog']);
        Http::assertSentCount(3);
    }

    public function test_cross_page_duplicate_product_sku_is_rejected(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/warehouse/list')) {
                return Http::response(['result' => 'OK', 'data' => [
                    ['warehouseId' => 'WAREHOUSE-A', 'warehouseName' => 'MorePlus'],
                ]], 200);
            }
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $records = ($query['curPageNo'] ?? '') == '2'
                ? [['omsSku' => 'SKU-001']]
                : array_map(fn ($n) => ['omsSku' => sprintf('SKU-%03d', $n)], range(1, 100));

            return Http::response(['result' => 'OK', 'data' => [
                'total' => 101, 'records' => $records,
            ]], 200);
        });
        $this->expectExceptionMessage('SHIPOUT_PROD_AUDIT_DUPLICATE_SKU');
        try {
            app(ShipOutProductionInventoryAudit::class)->snapshot();
        } finally {
            Http::assertSentCount(3);
        }
    }
}
