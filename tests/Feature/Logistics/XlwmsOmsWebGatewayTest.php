<?php

namespace Tests\Feature\Logistics;

use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsOmsWebGateway;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsWebTrackKey;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class XlwmsOmsWebGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('xlwms.oms_web_enabled', true);
    }

    public function test_track_key_is_sorted_and_prefixed(): void
    {
        self::assertSame(
            XlwmsWebTrackKey::sign(['b' => 2, 'a' => ['d' => 4, 'c' => 3]], 'secret'),
            XlwmsWebTrackKey::sign(['a' => ['c' => 3, 'd' => 4], 'b' => 2], 'secret'),
        );
        self::assertStringStartsWith('v2:', XlwmsWebTrackKey::sign([], 'secret'));
    }

    public function test_current_drafts_page_through_and_match_strict_prefix_boundary(): void
    {
        Http::fake(function ($request) {
            $page = (int) $request->data()['pageNum'];
            return Http::response(['code' => 200, 'data' => [
                'records' => $page === 1
                    ? [['outboundOrderNo' => 'OBS-1', 'referOrderNo' => 'OTHER-ORDER', 'status'=>0]]
                    : [['outboundOrderNo' => 'OBS-2', 'referOrderNo' => 'ERP-123-USPS-TEST4','status'=>0]],
                'total' => 2,
            ]]);
        });

        $gateway = new XlwmsOmsWebGateway('https://oms.xlwms.com/gateway/woms', 'token', 'secret');
        $found = $gateway->findCurrentDraft(['ERP-123']);

        self::assertSame('OBS-2', $found['outboundOrderNo']);
        self::assertSame(2, count(Http::recorded()));
        self::assertSame('v2:'.base64_encode(hash_hmac('sha256', 'POST'.hash('sha256', '{"pageNum":1,"pageSize":20,"status":0}'), 'secret', true)), Http::recorded()[0][0]->header('Track-Key')[0]);
    }

    public function test_upload_is_multipart_and_create_uses_web_endpoint_only(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xlwms-void-');
        file_put_contents($path, 'png');
        Http::fake([
            'https://oms.xlwms.com/gateway/woms/appendix/upload' => Http::response(['code' => 200, 'data' => ['fileName' => 'VOID.png', 'bizCode' => 'outbound']], 200),
            'https://oms.xlwms.com/gateway/woms/outboundOrder/create' => Http::response(['code' => 200, 'data' => ['outboundOrderNo' => 'OBS-3']], 200),
        ]);

        $gateway = new XlwmsOmsWebGateway('https://oms.xlwms.com/gateway/woms', 'token', 'secret');
        $appendix = $gateway->uploadAppendix($path);
        $created = $gateway->createDraft(['isSubmit' => 0, 'logisticsChannel' => 'Upload_Shipping_Label', 'logisticsCarrier' => 'usps','channelType'=>3, 'appendixList' => [$appendix], 'logisticsSheet' => 'VOID.png']);

        self::assertSame('VOID.png', $appendix['fileName']);
        self::assertSame('OBS-3', $created['outboundOrderNo']);
        self::assertTrue(Http::recorded()[0][0]->hasFile('file'));
        self::assertSame('/gateway/woms/appendix/upload', parse_url(Http::recorded()[0][0]->url(), PHP_URL_PATH));
        self::assertSame('/gateway/woms/outboundOrder/create', parse_url(Http::recorded()[1][0]->url(), PHP_URL_PATH));
        @unlink($path);
    }

    public function test_missing_credentials_fail_closed_before_http(): void
    {
        $this->expectExceptionMessage('OMS_WEB_TOKEN_MISSING');
        (new XlwmsOmsWebGateway('https://oms.xlwms.com/gateway/woms', '', 'secret'))->currentDrafts();
        Http::assertNothingSent();
    }

    public function test_public_openapi_create_is_always_blocked(): void
    {
        $this->expectExceptionMessage('XLWMS_OPENAPI_CREATE_BLOCKED_USE_OMS_DRAFT_IMPORT');
        app(\App\Modules\Logistics\Infrastructure\Xlwms\XlwmsOrderGateway::class)->createSingle(
            new \App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection(['app_key_encrypted' => 'a', 'app_secret_encrypted' => 'b']),
            ['isSubmit' => 1],
        );
    }
}
