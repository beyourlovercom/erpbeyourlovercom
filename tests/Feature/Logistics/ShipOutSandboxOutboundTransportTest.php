<?php

namespace Tests\Feature\Logistics;

use App\Modules\Logistics\Infrastructure\ShipOut\ShipOutSandboxOutboundTransport;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ShipOutSandboxOutboundTransportTest extends TestCase
{
    private function client(): ShipOutSandboxOutboundTransport
    {
        return new ShipOutSandboxOutboundTransport('app-key-test', 'app-secret-test', 'bearer token-test');
    }

    private function order(string $orderNo = 'C41843-007', string $warehouse = 'TEST-W'): array
    {
        return [[
            'sid' => 1, 'orderNo' => $orderNo, 'warehouseId' => $warehouse,
            'orderSummary' => ['orderDate' => '2026-10-29 00:00:00'],
            'toAddress' => ['name' => 'Sandbox Test'],
            'shipmentForms' => [[
                'shippingInfo' => ['carrierId' => 9, 'signatureType' => 4],
                'outboundInfo' => ['sysServiceId' => '9'],
                'productList' => [['omsSku' => 'UAT', 'qty' => 1]],
            ]],
        ]];
    }

    public function test_dispatch_is_off_by_default(): void
    {
        config()->set('shipout.sandbox_dispatch_enabled', false);
        Http::fake();
        $this->expectExceptionMessage('SHIPOUT_SANDBOX_DISPATCH_DISABLED');
        $this->client()->submitOne($this->order());
        Http::assertNothingSent();
    }

    public function test_requires_exact_order_number_and_warehouse_allowlist(): void
    {
        config()->set('shipout.sandbox_dispatch_enabled', true);
        config()->set('shipout.sandbox_order_allowlist', 'C41843-007');
        config()->set('shipout.sandbox_warehouse_id', 'TEST-W');
        Http::fake();

        foreach ([
            $this->order('OTHER-ORDER'),
            $this->order('C41843-007', 'WRONG-WAREHOUSE'),
            [$this->order()[0], $this->order()[0]],
        ] as $invalid) {
            try {
                $this->client()->submitOne($invalid);
                $this->fail('Payload outside single-order allowlist must fail.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('SHIPOUT_SANDBOX_PAYLOAD_NOT_ALLOWLISTED', $exception->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_mode_10_transport_rejects_spoofed_channel_id_before_network(): void
    {
        config()->set('shipout.sandbox_dispatch_enabled',true);
        config()->set('shipout.sandbox_order_allowlist','C41843-007');
        config()->set('shipout.sandbox_warehouse_id','TEST-W');
        config()->set('shipout.sandbox_enabled_modes','9,10');
        config()->set('shipout.sandbox_service_group_id','1993981773496696833');
        $payload=$this->order();
        $payload[0]['shipmentForms'][0]['shippingInfo']['carrierId']=1;
        $payload[0]['shipmentForms'][0]['outboundInfo']=[
            'sysServiceId'=>'10', 'serviceGroupId'=>'1993981773496696834',
        ];
        Http::fake();
        $this->expectExceptionMessage('SHIPOUT_SANDBOX_PAYLOAD_NOT_ALLOWLISTED');
        try { $this->client()->submitOne($payload); }
        finally { Http::assertNothingSent(); }
    }

    public function test_signed_post_is_always_sandbox_and_async_result_never_marks_shipped(): void
    {
        config()->set('shipout.sandbox_dispatch_enabled', true);
        config()->set('shipout.sandbox_order_allowlist', 'C41843-007');
        config()->set('shipout.sandbox_warehouse_id', 'TEST-W');
        Http::fake(['https://opendev.shipout.com/api/*' => Http::response([[
            'result' => 'OK',
            'data' => ['sid' => 1, 'orderNO' => 'C41843-007', 'orderId' => 'PROVIDER-ID-123'],
        ]], 200)]);
        Http::preventStrayRequests();

        $response = $this->client()->submitOne($this->order());
        $this->assertSame('acknowledged', $response['status']);
        $this->assertSame('ASYNC_ACCEPTED_QUERY_REQUIRED', $response['result_code']);
        $this->assertSame('PROVIDER-ID-123', $response['provider_order_id']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://opendev.shipout.com/api/open-api/oms/order/batchSubmitAsync'
            && $request->hasHeader('sign')
            && $request->hasHeader('Authorization', 'bearer token-test'));
    }

    public function test_provider_business_failure_is_not_equivalent_to_shipped(): void
    {
        config()->set('shipout.sandbox_dispatch_enabled', true);
        config()->set('shipout.sandbox_order_allowlist', 'C41843-007');
        config()->set('shipout.sandbox_warehouse_id', 'TEST-W');
        Http::fake(['https://opendev.shipout.com/api/*' =>
            Http::response([['result' => 'ERROR', 'message' => 'secret provider response']], 200)]);

        $r = $this->client()->submitOne($this->order());
        $this->assertSame('rejected', $r['status']);
        $this->assertSame('PROVIDER_BUSINESS_REJECTED', $r['result_code']);
        $this->assertStringNotContainsString('secret provider response', json_encode($r));
    }

    public function test_top_level_err_envelope_is_business_rejection_despite_http_200(): void
    {
        config()->set('shipout.sandbox_dispatch_enabled', true);
        config()->set('shipout.sandbox_order_allowlist', 'C41843-007');
        config()->set('shipout.sandbox_warehouse_id', 'TEST-W');
        Http::fake(['https://opendev.shipout.com/api/*' =>
            Http::response(['result' => 'ERR', 'message' => 'request contains sensitive address'], 200)]);

        $result = $this->client()->submitOne($this->order());
        $this->assertSame('rejected', $result['status']);
        $this->assertSame('PROVIDER_TOP_LEVEL_VALIDATION_REJECTED', $result['result_code']);
        $this->assertStringNotContainsString('sensitive address', json_encode($result));
    }

    public function test_uncertain_http_response_throws_without_inventing_success(): void
    {
        config()->set('shipout.sandbox_dispatch_enabled', true);
        config()->set('shipout.sandbox_order_allowlist', 'C41843-007');
        config()->set('shipout.sandbox_warehouse_id', 'TEST-W');
        Http::fake(['https://opendev.shipout.com/api/*' =>
            Http::response(['message' => 'HTTP 502 secret diagnostics'], 502)]);
        $this->expectExceptionMessage('SHIPOUT_SUBMISSION_OUTCOME_UNKNOWN');
        $this->client()->submitOne($this->order());
    }
}
