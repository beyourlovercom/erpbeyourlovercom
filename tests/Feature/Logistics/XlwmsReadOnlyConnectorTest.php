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

    public function test_mapping_blocks_unmapped_skus_and_never_claims_remote_verification(): void
    {
        config()->set('xlwms.warehouse_map', ['US-LA' => 'WAREHOUSE-01']);
        config()->set('xlwms.sku_map', ['US-LA' => ['ERP-A' => 'OMS-A']]);

        $mapper = new XlwmsMappingReadinessService();
        $blocked = $mapper->assess('US-LA', ['ERP-A', 'ERP-B']);
        $this->assertSame('blocked', $blocked['status']);
        $this->assertContains('sku_not_mapped:ERP-B', $blocked['blockers']);
        $this->assertFalse($blocked['remote_verified']);

        $configured = $mapper->assess('us-la', ['ERP-A']);
        $this->assertSame('configured_unverified', $configured['status']);
        $this->assertSame('WAREHOUSE-01', $configured['warehouse_code']);
        $this->assertSame(['ERP-A' => 'OMS-A'], $configured['sku_map']);
        $this->assertFalse($configured['remote_verified']);
    }
}
