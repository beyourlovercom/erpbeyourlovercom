<?php

namespace Tests\Unit\Logistics;

use App\Modules\Logistics\Application\Xlwms\XlwmsWarehouseChannelCatalog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class XlwmsWarehouseChannelCatalogTest extends TestCase
{
    private array $options = [
        ['value' => 'DHL', 'services' => [
            ['id' => 27, 'key' => 'paket_domestic', 'value' => 'DHL Paket', 'name_en' => 'Parcel', 'name_zh' => '包裹'],
        ]],
    ];

    public function test_exact_warehouse_and_carrier_service_match_is_only_a_candidate(): void
    {
        $result = XlwmsWarehouseChannelCatalog::map([
            ['channelCode' => 'paket_domestic', 'channelName' => 'DHL Paket',
                'carrierName' => 'DHL', 'whCodes' => ['Hannover'], 'channelType' => 1],
            ['channelCode' => 'US-ONLY', 'channelName' => 'Other',
                'carrierName' => 'DHL', 'whCodes' => ['US-SM']],
        ], $this->options, 'Hannover');

        self::assertSame(2, $result['total_provider_channels']);
        self::assertSame(1, $result['eligible_channels']);
        self::assertSame(1, $result['exact_candidate_count']);
        self::assertSame('exact_candidate', $result['channels'][0]['mapping_status']);
        self::assertSame(27, $result['channels'][0]['erp_service_id']);
        self::assertTrue($result['read_only']);
    }

    public function test_missing_scope_or_service_never_implies_a_dispatch_mapping(): void
    {
        $result = XlwmsWarehouseChannelCatalog::map([
            ['channelCode' => 'UNKNOWN', 'channelName' => 'Other', 'carrierName' => 'DHL', 'whCodes' => ['Hannover']],
            ['channelCode' => 'paket_domestic', 'channelName' => 'DHL Paket', 'carrierName' => 'DHL'],
        ], $this->options, 'Hannover');

        self::assertSame(0, $result['exact_candidate_count']);
        self::assertSame(1, $result['scope_unverified_count']);
        self::assertSame(['unmapped', 'unmapped'], array_column($result['channels'], 'mapping_status'));
    }

    public function test_actual_tianyun_account_wide_channels_are_reported_for_review_without_dispatch_mapping(): void
    {
        $rows = [
            ['carrierName' => '', 'channelCode' => 'Service with the lowest price', 'channelName' => '自动选取价格最低的渠道', 'channelType' => 1, 'whCodes' => []],
            ['carrierName' => '系统默认渠道', 'channelCode' => 'Upload_Shipping_Label', 'channelName' => '上传物流面单', 'channelType' => 3, 'whCodes' => []],
            ['carrierName' => '系统默认渠道', 'channelCode' => 'No_Shipping_Service', 'channelName' => '无需物流服务', 'channelType' => 3, 'whCodes' => []],
            ['carrierName' => 'DE-DHL-TG', 'channelCode' => 'DHL PAKET DOMESTIC', 'channelName' => 'DE-DHL本地派送服务', 'channelType' => 2, 'whCodes' => []],
            ['carrierName' => 'DE-DHL-TG', 'channelCode' => 'DHL PAKET International N', 'channelName' => 'DE-DHL-N国际派送服务', 'channelType' => 2, 'whCodes' => []],
        ];
        $result = XlwmsWarehouseChannelCatalog::map($rows, $this->options, 'Hannover');
        self::assertSame(5, $result['total_provider_channels']);
        self::assertSame(5, $result['scope_unverified_count']);
        self::assertSame(0, $result['exact_candidate_count']);
        self::assertSame(5, count(array_filter($result['channels'], fn (array $row): bool =>
            $row['mapping_status'] === 'unmapped')));
    }

    public function test_duplicate_provider_channel_codes_fail_closed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OMS_DE_CHANNEL_CODE_INVALID');
        XlwmsWarehouseChannelCatalog::map([
            ['channelCode' => 'X', 'whCodes' => ['Hannover']],
            ['channelCode' => 'X', 'whCodes' => ['Hannover']],
        ], [], 'Hannover');
    }
}
