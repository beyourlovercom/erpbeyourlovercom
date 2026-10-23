<?php

namespace Tests\Feature\Logistics;

use App\Modules\Logistics\Application\XlwmsMappingReadinessService;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsReadOnlyClient;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsSignature;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class XlwmsReadOnlyConnectorTest extends TestCase
{
    public function test_signing_matches_official_field_sort_and_hmac_sha256_contract(): void
    {
        $data = (object) [
            'pageSize' => 10,
            'outboundOrderNos' => 'OB1,OB2',
            'page' => 1,
        ];
        $expectedPlain = 'test-key'.'{"outboundOrderNos":"OB1,OB2","page":1,"pageSize":10}'.'1653640516';
        $expected = hash_hmac('sha256', $expectedPlain, 'test-secret');

        $this->assertSame($expected, XlwmsSignature::sign('test-key', 'test-secret', '1653640516', $data));
        $this->assertSame(
            '{"List":[{"a":1,"b":2}],"outer":{"a":1,"Z":2}}',
            XlwmsSignature::dataJson((object) ['outer' => ['Z' => 2, 'a' => 1], 'List' => [['b' => 2, 'a' => 1]]])
        );
    }

    public function test_disabled_or_missing_credentials_never_make_network_requests(): void
    {
        Http::fake();
        config()->set('xlwms.enabled', false);
        config()->set('xlwms.app_key', 'test-key');
        config()->set('xlwms.app_secret', 'test-secret');

        $client = new XlwmsReadOnlyClient();
        $this->assertFalse($client->isConfigured());
        try {
            $client->warehouses();
            $this->fail('Disabled XLWMS request must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('disabled', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_catalog_access_is_allowlisted_and_signed_without_any_dispatch(): void
    {
        config()->set('xlwms.enabled', true);
        config()->set('xlwms.app_key', 'test-key');
        config()->set('xlwms.app_secret', 'test-secret');
        config()->set('xlwms.base_url', 'https://api.xlwms.com/openapi/v1');
        config()->set('xlwms.timeout_seconds', 10);
        config()->set('xlwms.auto_dispatch_enabled', false);
        Http::fake(['https://api.xlwms.com/*' => Http::response(['code' => 200, 'data' => []])]);

        $client = new XlwmsReadOnlyClient();
        $this->assertSame(200, $client->warehouses()['http_status']);
        $this->assertSame(200, $client->channels()['http_status']);
        $this->assertSame(200, $client->products()['http_status']);

        Http::assertSentCount(3);
        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/warehouse/options?authcode=')) {
                return false;
            }
            $body = $request->data();
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ! array_key_exists('data', $body)
                && ($body['appKey'] ?? null) === 'test-key'
                && ($query['authcode'] ?? null) === XlwmsSignature::sign(
                    'test-key', 'test-secret', (string) $body['reqTime']
                );
        });
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'outboundOrder/create'));
    }

    public function test_untrusted_endpoint_is_blocked_before_http(): void
    {
        Http::fake();
        config()->set('xlwms.enabled', true);
        config()->set('xlwms.app_key', 'test-key');
        config()->set('xlwms.app_secret', 'test-secret');
        config()->set('xlwms.base_url', 'https://unexpected-host.example/openapi/v1');

        $this->expectException(RuntimeException::class);
        try {
            (new XlwmsReadOnlyClient())->warehouses();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_transport_exception_never_exposes_signed_url_or_secret(): void
    {
        config()->set('xlwms.enabled', true);
        config()->set('xlwms.app_key', 'test-key');
        config()->set('xlwms.app_secret', 'test-secret');
        config()->set('xlwms.base_url', 'https://api.xlwms.com/openapi/v1');

        Http::fake(function (): never {
            throw new RuntimeException('https://api.xlwms.com/openapi/v1?authcode=SECRET-DIAGNOSTIC');
        });

        try {
            (new XlwmsReadOnlyClient())->warehouses();
            $this->fail('Transport error expected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('XLWMS read-only transport failed.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    public function test_same_name_skus_use_identity_without_manual_mapping_or_claiming_remote_verification(): void
    {
        config()->set('xlwms.warehouse_map', ['US-LA' => 'WAREHOUSE-01']);
        config()->set('xlwms.sku_map', []);

        $result = (new XlwmsMappingReadinessService())->assess('us-la', ['BYL-A', 'BYL-B']);
        $this->assertSame('configured_unverified', $result['status']);
        $this->assertSame('WAREHOUSE-01', $result['warehouse_code']);
        $this->assertSame(['BYL-A' => 'BYL-A', 'BYL-B' => 'BYL-B'], $result['sku_map']);
        $this->assertSame([], $result['blockers']);
        $this->assertFalse($result['remote_verified']);
    }

    public function test_exception_override_is_warehouse_scoped_and_other_skus_stay_identical(): void
    {
        config()->set('xlwms.warehouse_map', ['US-LA' => 'WAREHOUSE-01', 'DE' => 'WAREHOUSE-02']);
        config()->set('xlwms.sku_map', ['US-LA' => ['ERP-A' => 'OMS-ALIAS-A']]);

        $service = new XlwmsMappingReadinessService();
        $la = $service->assess('US-LA', ['ERP-A', 'ERP-B']);
        $this->assertSame('configured_unverified', $la['status']);
        $this->assertSame(['ERP-A' => 'OMS-ALIAS-A', 'ERP-B' => 'ERP-B'], $la['sku_map']);

        $de = $service->assess('DE', ['ERP-A', 'ERP-B']);
        $this->assertSame('configured_unverified', $de['status']);
        $this->assertSame(['ERP-A' => 'ERP-A', 'ERP-B' => 'ERP-B'], $de['sku_map']);
        $this->assertFalse($de['remote_verified']);
    }

    public function test_missing_warehouse_binding_and_invalid_exception_fail_closed(): void
    {
        config()->set('xlwms.warehouse_map', ['US-LA' => 'WAREHOUSE-01']);
        config()->set('xlwms.sku_map', ['US-LA' => ['BYL-1' => '']]);

        $service = new XlwmsMappingReadinessService();
        $unbound = $service->assess('US-NY', ['BYL-2']);
        $this->assertSame('blocked', $unbound['status']);
        $this->assertContains('warehouse_not_mapped', $unbound['blockers']);

        $badOverride = $service->assess('US-LA', ['BYL-1', 'BYL-2']);
        $this->assertSame('blocked', $badOverride['status']);
        $this->assertContains('invalid_sku_override:BYL-1', $badOverride['blockers']);
        $this->assertSame(['BYL-2' => 'BYL-2'], $badOverride['sku_map']);
    }

    public function test_no_carrier_translation_is_required_for_sku_readiness(): void
    {
        config()->set('xlwms.warehouse_map', ['US-LA' => 'WAREHOUSE-01']);
        config()->set('xlwms.sku_map', []);
        // Carrier service is the warehouse native code; no map config exists.
        $this->assertArrayNotHasKey('carrier_map', config('xlwms'));
        $this->assertArrayNotHasKey('logistics_map', config('xlwms'));
        $result = (new XlwmsMappingReadinessService())->assess('US-LA', ['BYL-101']);
        $this->assertSame('configured_unverified', $result['status']);
        $this->assertFalse($result['remote_verified']);
    }
}
